<?php
defined( 'ABSPATH' ) || exit;

class BRP_Processor {

    /**
     * Insert a review (and its replies) into WooCommerce.
     *
     * Returns the new wp_comment ID, or 0 if skipped (duplicate) or failed.
     */
    public static function insert( array $payload ): int {
        $settings           = get_option( BRP_OPTION_KEY, BRP_Settings::defaults() );
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

        // ── Duplicate check ──────────────────────────────────────────────────
        if ( self::already_exists( $basalam_review_id ) ) {
            return 0;
        }

        // ── Apply customer name prefix / suffix ──────────────────────────────
        $prefix    = $settings['customer_name_prefix'] ?? '';
        $suffix    = $settings['customer_name_suffix'] ?? '';
        $full_name = trim( "{$prefix} {$user_name} {$suffix}" );
        if ( empty( $full_name ) ) {
            $full_name = $user_name;
        }

        // ── Build comment data ───────────────────────────────────────────────
        $approved = $settings['auto_approve'] ? 1 : 0;

        $comment_data = [
            'comment_post_ID'      => $wc_product_id,
            'comment_author'       => $full_name,
            'comment_author_email' => '',
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
        foreach ( (array) $replies as $reply ) {
            $reply_description = sanitize_textarea_field( $reply['description'] ?? '' );
            $reply_author      = self::resolve_admin_name(
                sanitize_text_field( $reply['author_name'] ?? '' ),
                $settings
            );

            if ( empty( $reply_description ) ) {
                continue;
            }

            $reply_data = [
                'comment_post_ID'  => $wc_product_id,
                'comment_author'   => $reply_author,
                'comment_content'  => $reply_description,
                'comment_type'     => 'review',
                'comment_parent'   => $comment_id,
                'comment_approved' => $approved,
                'comment_date'     => $created_at,
                'comment_date_gmt' => get_gmt_from_date( $created_at ),
            ];
            $reply_id = wp_insert_comment( $reply_data );
            if ( $reply_id ) {
                add_comment_meta( $reply_id, 'basalam_is_reply', 1, true );
            }
        }

        // ── Recalculate WooCommerce product rating synchronously ─────────────
        if ( class_exists( 'WC_Comments' ) ) {
            $product = wc_get_product( $wc_product_id );
            if ( $product ) {
                $product->set_rating_counts( WC_Comments::get_rating_counts_for_product( $product ) );
                $product->set_average_rating( WC_Comments::get_average_rating_for_product( $product ) );
                $product->set_review_count( WC_Comments::get_count_for_product( $product ) );
                $product->save();
            }
        }

        return $comment_id;
    }

    private static function already_exists( int $basalam_review_id ): bool {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT comment_id FROM {$wpdb->commentmeta}
             WHERE meta_key = 'basalam_review_id' AND meta_value = %d LIMIT 1",
            $basalam_review_id
        ) );
        return ! empty( $exists );
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
