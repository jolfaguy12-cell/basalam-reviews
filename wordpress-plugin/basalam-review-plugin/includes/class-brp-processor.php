<?php
defined( 'ABSPATH' ) || exit;

class BRP_Processor {

    /**
     * Insert a review (and its replies) into WooCommerce.
     * If the review already exists, syncs any new replies.
     * Returns the WP comment ID (new or existing), or 0 on failure.
     */
    public static function insert( array $payload ): int {
        // Merge defaults first so new settings keys (added in later versions) always
        // have their default values even when the saved option pre-dates this version.
        $settings           = array_merge( BRP_Settings::defaults(), (array) get_option( BRP_OPTION_KEY, [] ) );
        $basalam_review_id  = (int) ( $payload['basalam_review_id'] ?? 0 );
        $wc_product_id      = (int) ( $payload['wc_product_id'] ?? 0 );
        $user_name          = sanitize_text_field( $payload['user_name'] ?? '' );
        $star               = max( 1, min( 5, (int) ( $payload['star'] ?? 5 ) ) );
        $description        = sanitize_textarea_field( $payload['description'] ?? '' );
        $created_at         = sanitize_text_field( $payload['created_at'] ?? current_time( 'mysql' ) );
        $replies            = $payload['replies'] ?? [];

        if ( ! $basalam_review_id || ! $wc_product_id ) {
            return 0;
        }

        // ── Duplicate check — sync new replies if review already exists ───────
        $existing_id = self::find_existing( $basalam_review_id );
        if ( $existing_id ) {
            self::sync_new_replies( $existing_id, $wc_product_id, $replies, $created_at, $settings );
            brp_push_log( 'info', 'duplicate_found', [
                'basalam_review_id' => $basalam_review_id,
                'wc_comment_id'     => $existing_id,
            ] );
            return $existing_id;
        }

        // ── Apply customer name prefix / suffix ──────────────────────────────
        $prefix    = $settings['customer_name_prefix'] ?? '';
        $suffix    = $settings['customer_name_suffix'] ?? '';
        $full_name = trim( "{$prefix} {$user_name} {$suffix}" );
        if ( empty( $full_name ) ) {
            $full_name = $user_name;
        }

        // ── Star-only import policy ──────────────────────────────────────────
        $is_star_only = mb_strlen( trim( $description ) ) <= 1;
        if ( $is_star_only && empty( $settings['import_star_only'] ) ) {
            brp_push_log( 'info', 'star_only_blocked', [ 'basalam_review_id' => $basalam_review_id ] );
            return 0;
        }

        // ── Build comment data ───────────────────────────────────────────────
        // Star-only reviews (no text) are never auto-approved regardless of setting.
        $approved = ( $settings['auto_approve'] && ! $is_star_only ) ? 1 : 0;

        $comment_data = [
            'comment_post_ID'      => $wc_product_id,
            'comment_author'       => $full_name,
            'comment_author_email' => 'basalam-import@noreply.local',
            'comment_author_url'   => '',
            'comment_content'      => $description,
            'comment_type'         => 'review',
            'comment_parent'       => 0,
            'comment_approved'     => $approved,
            'comment_date'         => $created_at,
            'comment_date_gmt'     => get_gmt_from_date( $created_at ),
        ];

        $comment_id = wp_insert_comment( $comment_data );
        if ( ! $comment_id ) {
            brp_push_log( 'error', 'insert_failed', [
                'basalam_review_id' => $basalam_review_id,
                'wc_product_id'     => $wc_product_id,
            ] );
            return 0;
        }

        // ── Store meta ───────────────────────────────────────────────────────
        add_comment_meta( $comment_id, 'rating',             $star,               true );
        add_comment_meta( $comment_id, 'basalam_review_id',  $basalam_review_id,  true );
        add_comment_meta( $comment_id, 'verified',           1,                   true );

        if ( ! empty( $settings['attach_product_image'] ) ) {
            $thumbnail_id = get_post_thumbnail_id( $wc_product_id );
            if ( $thumbnail_id ) {
                add_comment_meta( $comment_id, 'review_image', $thumbnail_id, true );
            }
        }

        // ── Insert seller replies as child comments ───────────────────────────
        self::insert_replies( $comment_id, $wc_product_id, $replies, $created_at, $approved );

        // ── Queue rating recalc — runs once per product at request shutdown ──
        self::queue_rating_recalc( $wc_product_id );

        brp_push_log( 'info', 'review_inserted', [
            'basalam_review_id' => $basalam_review_id,
            'wc_comment_id'     => $comment_id,
        ] );

        return $comment_id;
    }

    // ── Reply helpers ─────────────────────────────────────────────────────────

    /**
     * For an existing review: insert any replies not yet in WordPress.
     * Uses basalam_answer_id to detect existing replies (idempotent).
     */
    private static function sync_new_replies(
        int $parent_id,
        int $wc_product_id,
        array $replies,
        string $created_at,
        array $settings
    ): void {
        if ( empty( $replies ) ) {
            return;
        }

        $approved   = ( $settings['auto_approve'] ) ? 1 : 0;
        $new_count  = 0;

        $wc_hook_priority = has_action( 'wp_insert_comment', [ 'WC_Comments', 'maybe_run_product_meta_sync_query' ] );
        if ( $wc_hook_priority !== false ) {
            remove_action( 'wp_insert_comment', [ 'WC_Comments', 'maybe_run_product_meta_sync_query' ], $wc_hook_priority );
        }

        try {
            foreach ( (array) $replies as $reply ) {
                $answer_id         = (int) ( $reply['basalam_answer_id'] ?? 0 );
                $reply_description = sanitize_textarea_field( $reply['description'] ?? '' );

                if ( empty( $reply_description ) ) {
                    continue;
                }

                // Replies without a trackable ID cannot be deduplicated — skip to
                // prevent orphan accumulation on future syncs of the same review.
                if ( ! $answer_id ) {
                    continue;
                }

                // Skip if this specific reply is already in WordPress.
                if ( self::find_existing_reply( $parent_id, $answer_id ) ) {
                    continue;
                }

                $reply_author = self::resolve_admin_name(
                    sanitize_text_field( $reply['author_name'] ?? '' ),
                    $settings
                );

                $reply_id = wp_insert_comment( [
                    'comment_post_ID'  => $wc_product_id,
                    'comment_author'   => $reply_author,
                    'comment_content'  => $reply_description,
                    'comment_type'     => 'review',
                    'comment_parent'   => $parent_id,
                    'comment_approved' => $approved,
                    'comment_date'     => $created_at,
                    'comment_date_gmt' => get_gmt_from_date( $created_at ),
                ] );

                if ( $reply_id ) {
                    add_comment_meta( $reply_id, 'basalam_is_reply', 1, true );
                    if ( $answer_id ) {
                        add_comment_meta( $reply_id, 'basalam_answer_id', $answer_id, true );
                    }
                    $new_count++;
                }
            }
        } finally {
            if ( $wc_hook_priority !== false ) {
                add_action( 'wp_insert_comment', [ 'WC_Comments', 'maybe_run_product_meta_sync_query' ], $wc_hook_priority );
            }
        }

        if ( $new_count > 0 ) {
            self::queue_rating_recalc( $wc_product_id );
            brp_push_log( 'info', 'replies_added', [
                'parent_wc_comment_id' => $parent_id,
                'count'                => $new_count,
            ] );
        }
    }

    /**
     * Insert all replies for a newly created review.
     * Wraps WC hook removal in try/finally.
     */
    private static function insert_replies(
        int $parent_id,
        int $wc_product_id,
        array $replies,
        string $created_at,
        int $approved
    ): void {
        if ( empty( $replies ) ) {
            return;
        }

        $settings = array_merge( BRP_Settings::defaults(), (array) get_option( BRP_OPTION_KEY, [] ) );

        $wc_hook_priority = has_action( 'wp_insert_comment', [ 'WC_Comments', 'maybe_run_product_meta_sync_query' ] );
        if ( $wc_hook_priority !== false ) {
            remove_action( 'wp_insert_comment', [ 'WC_Comments', 'maybe_run_product_meta_sync_query' ], $wc_hook_priority );
        }

        try {
            foreach ( (array) $replies as $reply ) {
                $reply_description = sanitize_textarea_field( $reply['description'] ?? '' );
                $reply_author      = self::resolve_admin_name(
                    sanitize_text_field( $reply['author_name'] ?? '' ),
                    $settings
                );

                if ( empty( $reply_description ) ) {
                    continue;
                }

                $answer_id = (int) ( $reply['basalam_answer_id'] ?? 0 );

                // Skip replies without a trackable ID — they would become
                // unresolvable orphans if the review is re-synced later.
                if ( ! $answer_id ) {
                    continue;
                }

                $reply_id = wp_insert_comment( [
                    'comment_post_ID'  => $wc_product_id,
                    'comment_author'   => $reply_author,
                    'comment_content'  => $reply_description,
                    'comment_type'     => 'review',
                    'comment_parent'   => $parent_id,
                    'comment_approved' => $approved,
                    'comment_date'     => $created_at,
                    'comment_date_gmt' => get_gmt_from_date( $created_at ),
                ] );

                if ( $reply_id ) {
                    add_comment_meta( $reply_id, 'basalam_is_reply', 1, true );
                    if ( $answer_id ) {
                        add_comment_meta( $reply_id, 'basalam_answer_id', $answer_id, true );
                    }
                }
            }
        } finally {
            // Always restore WC hook even if an exception occurred mid-loop.
            if ( $wc_hook_priority !== false ) {
                add_action( 'wp_insert_comment', [ 'WC_Comments', 'maybe_run_product_meta_sync_query' ], $wc_hook_priority );
            }
        }
    }

    // Collect product IDs during a request and recalculate each once at shutdown.
    // Prevents redundant recalculations when many reviews for the same product arrive.
    private static function queue_rating_recalc( int $product_id ): void {
        static $queued   = [];
        static $hooked   = false;

        $queued[ $product_id ] = true;

        if ( ! $hooked ) {
            $hooked = true;
            add_action( 'shutdown', static function () use ( &$queued ) {
                foreach ( array_keys( $queued ) as $pid ) {
                    brp_recalc_product_rating( (int) $pid );
                }
            } );
        }
    }

    // ── Lookup helpers ────────────────────────────────────────────────────────

    private static function find_existing( int $basalam_review_id ): int {
        global $wpdb;
        // Join with comments to exclude trashed/spam rows — those should be
        // re-insertable after a reset rather than treated as duplicates.
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT cm.comment_id FROM {$wpdb->commentmeta} cm
             INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
             WHERE cm.meta_key   = 'basalam_review_id'
               AND cm.meta_value = %d
               AND c.comment_approved NOT IN ('trash', 'spam')
             LIMIT 1",
            $basalam_review_id
        ) );
    }

    private static function find_existing_reply( int $parent_id, int $basalam_answer_id ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT cm.comment_id FROM {$wpdb->commentmeta} cm
             INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
             WHERE cm.meta_key   = 'basalam_answer_id'
               AND cm.meta_value = %d
               AND c.comment_parent = %d
             LIMIT 1",
            $basalam_answer_id,
            $parent_id
        ) );
    }

    private static function resolve_admin_name( string $original, array $settings ): string {
        if ( empty( $settings['admin_name_randomizer'] ) ) {
            return $original ?: get_bloginfo( 'name' );
        }

        $pool = array_filter(
            array_map( 'trim', explode( "\n", $settings['admin_name_pool'] ?? '' ) )
        );

        if ( empty( $pool ) ) {
            return $original ?: get_bloginfo( 'name' );
        }

        return $pool[ array_rand( $pool ) ];
    }
}
