<?php
defined( 'ABSPATH' ) || exit;

class BRP_Settings {

    public static function defaults(): array {
        return [
            'api_key'               => '',
            'plugin_secret'         => '',
            'env_label'             => 'DEV',
            'customer_name_prefix'  => '',
            'customer_name_suffix'  => '',
            'admin_name_randomizer' => false,
            'admin_name_pool'       => "علی خلیلی\nپشتیبانی بهداشتیک\nتیم فروش",
            'attach_product_image'  => true,
            'auto_approve'          => true,
            'import_star_only'      => true,
            'log_enabled'           => false,
            'log_endpoint'          => '',
            'log_api_key'           => '',
        ];
    }

    public static function init(): void {
        add_action( 'admin_menu',            [ self::class, 'add_menu' ] );
        add_action( 'admin_init',            [ self::class, 'register_fields' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );

        add_action( 'wp_ajax_brp_view_logs',       [ self::class, 'ajax_view_logs' ] );
        add_action( 'wp_ajax_brp_clear_logs',      [ self::class, 'ajax_clear_logs' ] );
        add_action( 'wp_ajax_brp_fix_star_only',   [ self::class, 'ajax_fix_star_only' ] );
        add_action( 'wp_ajax_brp_trigger_sync',    [ self::class, 'ajax_trigger_sync' ] );
        add_action( 'wp_ajax_brp_trash_star_only',          [ self::class, 'ajax_trash_star_only' ] );
        add_action( 'wp_ajax_brp_migrate_emails',           [ self::class, 'ajax_migrate_emails' ] );
        add_action( 'wp_ajax_brp_check_connection',         [ self::class, 'ajax_check_connection' ] );
        add_action( 'wp_ajax_brp_remove_duplicate_replies', [ self::class, 'ajax_remove_duplicate_replies' ] );
        add_action( 'wp_ajax_brp_refresh_ratings',          [ self::class, 'ajax_refresh_ratings' ] );
        add_action( 'wp_ajax_brp_trash_all_imported',       [ self::class, 'ajax_trash_all_imported' ] );
        add_action( 'wp_ajax_brp_delete_all_imported',      [ self::class, 'ajax_delete_all_imported' ] );
        add_action( 'wp_ajax_brp_reset_sync',               [ self::class, 'ajax_reset_sync' ] );
    }

    public static function add_menu(): void {
        add_options_page(
            __( 'Basalam Review', 'basalam-review-plugin' ),
            'Basalam Review',
            'manage_options',
            'basalam-review-plugin',
            [ self::class, 'render_page' ]
        );
    }

    public static function register_fields(): void {
        register_setting(
            'brp_settings_group',
            BRP_OPTION_KEY,
            [ 'sanitize_callback' => [ self::class, 'sanitize' ] ]
        );
    }

    public static function sanitize( array $input ): array {
        $d = self::defaults();
        $raw_label = strtoupper( sanitize_text_field( $input['env_label'] ?? $d['env_label'] ) );
        $env_label = in_array( $raw_label, [ 'DEV', 'STAGING', 'PRODUCTION' ], true )
            ? $raw_label : 'DEV';

        return [
            'api_key'               => sanitize_text_field( $input['api_key']              ?? $d['api_key'] ),
            'plugin_secret'         => sanitize_text_field( $input['plugin_secret']        ?? $d['plugin_secret'] ),
            'env_label'             => $env_label,
            'customer_name_prefix'  => sanitize_text_field( $input['customer_name_prefix'] ?? '' ),
            'customer_name_suffix'  => sanitize_text_field( $input['customer_name_suffix'] ?? '' ),
            'admin_name_randomizer' => ! empty( $input['admin_name_randomizer'] ),
            'admin_name_pool'       => sanitize_textarea_field( $input['admin_name_pool']  ?? $d['admin_name_pool'] ),
            'attach_product_image'  => ! empty( $input['attach_product_image'] ),
            'auto_approve'          => ! empty( $input['auto_approve'] ),
            'import_star_only'      => ! empty( $input['import_star_only'] ),
            'log_enabled'           => ! empty( $input['log_enabled'] ),
            'log_endpoint'          => esc_url_raw( $input['log_endpoint']  ?? '' ),
            'log_api_key'           => sanitize_text_field( $input['log_api_key'] ?? '' ),
        ];
    }

    public static function enqueue_assets( string $hook ): void {
        if ( $hook !== 'settings_page_basalam-review-plugin' ) {
            return;
        }
        wp_enqueue_style( 'brp-admin', BRP_PLUGIN_URL . 'assets/admin.css', [], BRP_VERSION );
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    private static function check_ajax_nonce(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'forbidden', 403 );
        }
        check_ajax_referer( 'brp_logs_nonce', 'nonce' );
    }

    public static function ajax_view_logs(): void {
        self::check_ajax_nonce();
        $s        = array_merge( self::defaults(), (array) get_option( BRP_OPTION_KEY, [] ) );
        $endpoint = rtrim( $s['log_endpoint'], '/' );
        $api_key  = $s['log_api_key'] ?: $s['api_key'];
        $lines    = max( 1, min( 2000, (int) ( $_POST['lines'] ?? 200 ) ) );

        if ( empty( $endpoint ) ) {
            wp_send_json_error( 'Log Server URL not configured in settings.' );
        }

        $resp = wp_remote_get( $endpoint . '/logs?lines=' . $lines, [
            'timeout'   => 15,
            'headers'   => [ 'X-BRP-API-Key' => $api_key ],
            'sslverify' => false,
        ] );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( $resp->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );

        if ( $code !== 200 ) {
            wp_send_json_error( "Backend returned HTTP {$code}: {$body}" );
        }

        wp_send_json_success( [ 'logs' => $body ] );
    }

    public static function ajax_clear_logs(): void {
        self::check_ajax_nonce();
        $s        = array_merge( self::defaults(), (array) get_option( BRP_OPTION_KEY, [] ) );
        $endpoint = rtrim( $s['log_endpoint'], '/' );
        $api_key  = $s['log_api_key'] ?: $s['api_key'];

        if ( empty( $endpoint ) ) {
            wp_send_json_error( 'Log Server URL not configured in settings.' );
        }

        $resp = wp_remote_request( $endpoint . '/logs', [
            'method'    => 'DELETE',
            'timeout'   => 15,
            'headers'   => [ 'X-BRP-API-Key' => $api_key ],
            'sslverify' => false,
        ] );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( $resp->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 ) {
            wp_send_json_error( "Backend returned HTTP {$code}" );
        }

        wp_send_json_success( [ 'message' => 'Logs cleared.' ] );
    }

    public static function ajax_fix_star_only(): void {
        self::check_ajax_nonce();
        global $wpdb;

        $count = brp_unapprove_star_only_reviews();

        // Count how many star-only reviews are now pending (context for the user).
        $already_pending = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT c.comment_ID)
             FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->commentmeta} cm
                 ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
             WHERE c.comment_type               = 'review'
               AND CHAR_LENGTH(TRIM(c.comment_content)) <= 1
               AND c.comment_approved            = '0'
               AND c.comment_parent              = 0"
        );

        wp_send_json_success( [
            'updated'         => $count,
            'already_pending' => $already_pending,
        ] );
    }

    // Calls GET /status on the backend and returns env + db + wordpress info.
    public static function ajax_check_connection(): void {
        self::check_ajax_nonce();
        $s        = array_merge( self::defaults(), (array) get_option( BRP_OPTION_KEY, [] ) );
        $endpoint = rtrim( $s['log_endpoint'], '/' );
        $api_key  = $s['log_api_key'] ?: $s['api_key'];

        if ( empty( $endpoint ) ) {
            wp_send_json_error( 'Log Server URL not configured in Debug Logs settings.' );
        }

        $resp = wp_remote_get( $endpoint . '/status', [
            'timeout'   => 10,
            'headers'   => [ 'X-BRP-API-Key' => $api_key ],
            'sslverify' => false,
        ] );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( $resp->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code !== 200 ) {
            wp_send_json_error( "Backend returned HTTP {$code}" );
        }

        wp_send_json_success( $body );
    }

    // Two-mode handler: mode=dryrun → count only; mode=execute → batch-trash + recalc.
    // Strictly scoped to plugin-owned reviews (basalam_review_id meta).
    public static function ajax_trash_star_only(): void {
        self::check_ajax_nonce();
        global $wpdb;

        $mode = sanitize_key( $_POST['mode'] ?? 'dryrun' );

        if ( $mode === 'dryrun' ) {
            $base_join  = "FROM {$wpdb->comments} c
                           INNER JOIN {$wpdb->commentmeta} cm
                               ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                           WHERE c.comment_type                       = 'review'
                             AND CHAR_LENGTH(TRIM(c.comment_content)) <= 1
                             AND c.comment_parent                     = 0";

            $approved_count = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT COUNT(DISTINCT c.comment_ID) {$base_join} AND c.comment_approved = '1'"
            );
            $pending_count = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT COUNT(DISTINCT c.comment_ID) {$base_join} AND c.comment_approved = '0'"
            );
            $products = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT COUNT(DISTINCT c.comment_post_ID) {$base_join} AND c.comment_approved NOT IN ('trash','spam')"
            );
            $already_trashed = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                "SELECT COUNT(DISTINCT c.comment_ID) {$base_join} AND c.comment_approved = 'trash'"
            );

            wp_send_json_success( [
                'approved_count'  => $approved_count,
                'pending_count'   => $pending_count,
                'reviews'         => $approved_count + $pending_count,
                'products'        => $products,
                'already_trashed' => $already_trashed,
            ] );
            return;
        }

        // Execute: batch-trash in chunks of 50, then recalculate affected product ratings.
        $batch       = 50;
        $updated     = 0;
        $product_ids = [];

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT c.comment_ID, c.comment_post_ID
                     FROM {$wpdb->comments} c
                     INNER JOIN {$wpdb->commentmeta} cm
                         ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                     WHERE c.comment_type                       = 'review'
                       AND CHAR_LENGTH(TRIM(c.comment_content)) <= 1
                       AND c.comment_approved NOT IN ('trash', 'spam')
                       AND c.comment_parent                     = 0
                     LIMIT %d",
                    $batch
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            $ids = array_column( $rows, 'comment_ID' );
            foreach ( array_column( $rows, 'comment_post_ID' ) as $pid ) {
                $product_ids[ (int) $pid ] = true;
            }

            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->comments} SET comment_approved = 'trash' WHERE comment_ID IN ({$placeholders})",
                ...$ids
            ) );

            $updated += count( $ids );

        } while ( count( $rows ) === $batch );

        foreach ( array_keys( $product_ids ) as $product_id ) {
            brp_recalc_product_rating( $product_id );
        }

        wp_send_json_success( [
            'updated'               => $updated,
            'products_recalculated' => count( $product_ids ),
        ] );
    }

    // Two-mode handler: mode=dryrun → count; mode=execute → patch email on plugin-owned reviews.
    public static function ajax_migrate_emails(): void {
        self::check_ajax_nonce();
        global $wpdb;

        $mode = sanitize_key( $_POST['mode'] ?? 'dryrun' );

        if ( $mode === 'dryrun' ) {
            $count = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT c.comment_ID)
                 FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->commentmeta} cm
                     ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                 WHERE c.comment_author_email = ''"
            );
            wp_send_json_success( [ 'reviews' => $count ] );
            return;
        }

        // Execute: batch-update email in chunks of 50.
        $batch   = 50;
        $updated = 0;

        do {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT c.comment_ID
                     FROM {$wpdb->comments} c
                     INNER JOIN {$wpdb->commentmeta} cm
                         ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                     WHERE c.comment_author_email = ''
                     LIMIT %d",
                    $batch
                )
            );

            if ( empty( $ids ) ) {
                break;
            }

            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->comments} SET comment_author_email = 'basalam-import@noreply.local' WHERE comment_ID IN ({$placeholders})",
                ...$ids
            ) );

            $updated += count( $ids );

        } while ( count( $ids ) === $batch );

        wp_send_json_success( [ 'updated' => $updated ] );
    }

    public static function ajax_trigger_sync(): void {
        self::check_ajax_nonce();
        $s        = array_merge( self::defaults(), (array) get_option( BRP_OPTION_KEY, [] ) );
        $endpoint = rtrim( $s['log_endpoint'], '/' );
        $api_key  = $s['log_api_key'] ?: $s['api_key'];

        if ( empty( $endpoint ) ) {
            wp_send_json_error( 'Log Server URL not configured in settings.' );
        }

        // Call /push-only: synchronous, no Basalam crawl, returns actual result.
        // Timeout 60s covers pushing up to ~100 reviews with error retries.
        $resp = wp_remote_post( $endpoint . '/push-only', [
            'timeout'   => 60,
            'headers'   => [ 'X-BRP-API-Key' => $api_key ],
            'sslverify' => false,
            'body'      => '',
        ] );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( $resp->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code !== 200 ) {
            wp_send_json_error( "Backend returned HTTP {$code}: " . wp_remote_retrieve_body( $resp ) );
        }

        wp_send_json_success( $body );
    }

    // Reset backend sync state: clear wc_comment_id for all reviews so push_only re-queues them.
    // Dryrun (mode=dryrun) calls /status to get current synced count without modifying anything.
    // Execute (mode=execute) calls /reset-sync and returns the number of rows reset.
    public static function ajax_reset_sync(): void {
        self::check_ajax_nonce();
        $s        = array_merge( self::defaults(), (array) get_option( BRP_OPTION_KEY, [] ) );
        $endpoint = rtrim( $s['log_endpoint'], '/' );
        $api_key  = $s['log_api_key'] ?: $s['api_key'];

        if ( empty( $endpoint ) ) {
            wp_send_json_error( 'Log Server URL not configured in settings.' );
        }

        $mode = sanitize_key( $_POST['mode'] ?? 'dryrun' );

        if ( $mode === 'dryrun' ) {
            $resp = wp_remote_get( $endpoint . '/status', [
                'timeout'   => 10,
                'headers'   => [ 'X-BRP-API-Key' => $api_key ],
                'sslverify' => false,
            ] );
            if ( is_wp_error( $resp ) ) {
                wp_send_json_error( $resp->get_error_message() );
            }
            $body   = json_decode( wp_remote_retrieve_body( $resp ), true );
            $synced = (int) ( $body['db']['synced'] ?? 0 );
            wp_send_json_success( [ 'synced' => $synced ] );
            return;
        }

        $resp = wp_remote_post( $endpoint . '/reset-sync', [
            'timeout'   => 30,
            'headers'   => [ 'X-BRP-API-Key' => $api_key ],
            'sslverify' => false,
            'body'      => '',
        ] );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( $resp->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code !== 200 ) {
            wp_send_json_error( "Backend returned HTTP {$code}: " . wp_remote_retrieve_body( $resp ) );
        }

        wp_send_json_success( $body );
    }

    // Remove orphan replies: child comments with basalam_is_reply but no basalam_answer_id.
    // These are untrackable duplicates created when basalam_answer_id was 0 on first sync.
    public static function ajax_remove_duplicate_replies(): void {
        self::check_ajax_nonce();
        global $wpdb;

        $mode = sanitize_key( $_POST['mode'] ?? 'dryrun' );

        $ids = $wpdb->get_col(
            "SELECT c.comment_ID
             FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->commentmeta} rm
                 ON rm.comment_id = c.comment_ID AND rm.meta_key = 'basalam_is_reply'
             LEFT  JOIN {$wpdb->commentmeta} am
                 ON am.comment_id = c.comment_ID AND am.meta_key = 'basalam_answer_id'
             WHERE c.comment_parent > 0
               AND am.meta_value IS NULL"
        );

        if ( $mode === 'dryrun' ) {
            wp_send_json_success( [ 'count' => count( $ids ) ] );
            return;
        }

        $deleted = 0;
        foreach ( $ids as $id ) {
            if ( wp_delete_comment( (int) $id, true ) ) {
                $deleted++;
            }
        }

        wp_send_json_success( [ 'deleted' => $deleted ] );
    }

    // Recalculate WC ratings for ALL products that have ever had imported Basalam reviews
    // (including trashed/spam — catches stale ratings after a Trash All operation).
    public static function ajax_refresh_ratings(): void {
        self::check_ajax_nonce();
        global $wpdb;

        $mode = sanitize_key( $_POST['mode'] ?? 'dryrun' );

        // No status filter: include active, trashed, and spam imported reviews so
        // this works even after all reviews have been moved to Trash.
        $base = "FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->commentmeta} cm
                     ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                 WHERE c.comment_parent = 0";

        if ( $mode === 'dryrun' ) {
            $products = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT c.comment_post_ID) {$base}" ); // phpcs:ignore
            $active   = (int) $wpdb->get_var( // phpcs:ignore
                "SELECT COUNT(DISTINCT c.comment_post_ID) {$base} AND c.comment_approved NOT IN ('trash','spam')"
            );
            $trashed  = max( 0, $products - $active );
            wp_send_json_success( [
                'products' => $products,
                'active'   => $active,
                'trashed'  => $trashed,
            ] );
            return;
        }

        $offset       = 0;
        $batch        = 50;
        $recalculated = 0;

        do {
            $product_ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore
                "SELECT DISTINCT c.comment_post_ID {$base} ORDER BY c.comment_post_ID LIMIT %d OFFSET %d",
                $batch, $offset
            ) );

            foreach ( $product_ids as $pid ) {
                brp_recalc_product_rating( (int) $pid );
                $recalculated++;
            }

            $offset += $batch;
        } while ( count( $product_ids ) === $batch );

        wp_send_json_success( [ 'products_recalculated' => $recalculated ] );
    }

    // Move ALL active plugin-imported reviews and their plugin-owned replies to Trash.
    // Two-mode: dryrun → counts only; execute → batch trash + recalc ratings.
    public static function ajax_trash_all_imported(): void {
        self::check_ajax_nonce();
        global $wpdb;

        $mode = sanitize_key( $_POST['mode'] ?? 'dryrun' );

        if ( $mode === 'dryrun' ) {
            $root_reviews = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT c.comment_ID)
                 FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->commentmeta} cm
                     ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                 WHERE c.comment_parent = 0
                   AND c.comment_approved NOT IN ('trash','spam')"
            );
            $products = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT c.comment_post_ID)
                 FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->commentmeta} cm
                     ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                 WHERE c.comment_parent = 0
                   AND c.comment_approved NOT IN ('trash','spam')"
            );
            $replies = (int) $wpdb->get_var(
                "SELECT COUNT(*)
                 FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->commentmeta} cm
                     ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_is_reply'
                 WHERE c.comment_parent > 0
                   AND c.comment_approved NOT IN ('trash','spam')"
            );

            wp_send_json_success( [
                'root_reviews' => $root_reviews,
                'replies'      => $replies,
                'products'     => $products,
            ] );
            return;
        }

        $batch       = 50;
        $product_ids = [];
        $trashed_reviews = 0;
        $trashed_replies = 0;

        // Trash root reviews
        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT c.comment_ID, c.comment_post_ID
                     FROM {$wpdb->comments} c
                     INNER JOIN {$wpdb->commentmeta} cm
                         ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                     WHERE c.comment_parent = 0
                       AND c.comment_approved NOT IN ('trash','spam')
                     LIMIT %d",
                    $batch
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            $ids = array_column( $rows, 'comment_ID' );
            foreach ( array_column( $rows, 'comment_post_ID' ) as $pid ) {
                $product_ids[ (int) $pid ] = true;
            }

            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->comments} SET comment_approved = 'trash' WHERE comment_ID IN ({$placeholders})",
                ...$ids
            ) );

            $trashed_reviews += count( $ids );

        } while ( count( $rows ) === $batch );

        // Trash plugin-owned replies
        do {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT c.comment_ID
                     FROM {$wpdb->comments} c
                     INNER JOIN {$wpdb->commentmeta} cm
                         ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_is_reply'
                     WHERE c.comment_parent > 0
                       AND c.comment_approved NOT IN ('trash','spam')
                     LIMIT %d",
                    $batch
                )
            );

            if ( empty( $ids ) ) {
                break;
            }

            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->comments} SET comment_approved = 'trash' WHERE comment_ID IN ({$placeholders})",
                ...$ids
            ) );

            $trashed_replies += count( $ids );

        } while ( count( $ids ) === $batch );

        // Recalculate ratings for all affected products
        foreach ( array_keys( $product_ids ) as $pid ) {
            brp_recalc_product_rating( (int) $pid );
        }

        wp_send_json_success( [
            'trashed_reviews'       => $trashed_reviews,
            'trashed_replies'       => $trashed_replies,
            'products_recalculated' => count( $product_ids ),
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Permanently delete ALL plugin-imported reviews and their child comments from
     * WordPress, regardless of current status (active, trashed, spam).
     * Scoped strictly to basalam_review_id meta (root) + all children of those roots.
     * Does NOT reset backend DB state — re-sync is always possible after deletion.
     */
    public static function ajax_delete_all_imported(): void {
        self::check_ajax_nonce();
        global $wpdb;

        $mode = sanitize_key( $_POST['mode'] ?? 'dryrun' );

        if ( $mode === 'dryrun' ) {
            $root_count = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT c.comment_ID)
                 FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->commentmeta} cm
                     ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                 WHERE c.comment_parent = 0"
            );
            $child_count = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT child.comment_ID)
                 FROM {$wpdb->comments} child
                 INNER JOIN {$wpdb->comments} parent
                     ON child.comment_parent = parent.comment_ID
                 INNER JOIN {$wpdb->commentmeta} cm
                     ON parent.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                 WHERE child.comment_parent > 0"
            );
            wp_send_json_success( [
                'root_reviews'   => $root_count,
                'child_comments' => $child_count,
                'total'          => $root_count + $child_count,
            ] );
            return;
        }

        $batch           = 50;
        $deleted_children = 0;
        $deleted_root    = 0;
        $product_ids     = [];

        // Step 1: permanently delete all child comments first (avoids orphans).
        // Covers both plugin-inserted replies (basalam_is_reply meta) AND
        // admin/seller replies added via WP Admin that are children of Basalam roots.
        do {
            $child_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT child.comment_ID
                     FROM {$wpdb->comments} child
                     INNER JOIN {$wpdb->comments} parent
                         ON child.comment_parent = parent.comment_ID
                     INNER JOIN {$wpdb->commentmeta} cm
                         ON parent.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                     WHERE child.comment_parent > 0
                     LIMIT %d",
                    $batch
                )
            );
            if ( empty( $child_ids ) ) {
                break;
            }
            foreach ( $child_ids as $id ) {
                wp_delete_comment( (int) $id, true );
                $deleted_children++;
            }
        } while ( count( $child_ids ) === $batch );

        // Step 2: permanently delete root reviews (collect product IDs first).
        do {
            $root_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT c.comment_ID, c.comment_post_ID
                     FROM {$wpdb->comments} c
                     INNER JOIN {$wpdb->commentmeta} cm
                         ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                     WHERE c.comment_parent = 0
                     LIMIT %d",
                    $batch
                )
            );
            if ( empty( $root_rows ) ) {
                break;
            }
            foreach ( $root_rows as $row ) {
                $product_ids[ (int) $row->comment_post_ID ] = true;
                wp_delete_comment( (int) $row->comment_ID, true );
                $deleted_root++;
            }
        } while ( count( $root_rows ) === $batch );

        // Step 3: recalculate ratings for all affected products.
        foreach ( array_keys( $product_ids ) as $pid ) {
            brp_recalc_product_rating( (int) $pid );
        }

        wp_send_json_success( [
            'deleted_reviews'       => $deleted_root,
            'deleted_replies'       => $deleted_children,
            'products_recalculated' => count( $product_ids ),
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s      = array_merge( self::defaults(), (array) get_option( BRP_OPTION_KEY, [] ) );
        $stats  = self::get_stats();
        $has_keys = ! empty( $s['api_key'] ) && ! empty( $s['plugin_secret'] );
        $opt   = BRP_OPTION_KEY;
        ?>
        <div class="wrap brp-page">

            <?php
            $env      = strtoupper( $s['env_label'] ?? 'DEV' );
            $env_cls  = ( $env === 'PRODUCTION' ) ? 'brp-env-prod' : 'brp-env-dev';
            ?>
            <h1>
                <?php esc_html_e( 'Basalam Review', 'basalam-review-plugin' ); ?>
                <span class="brp-v">v<?php echo esc_html( BRP_VERSION ); ?></span>
                <span class="brp-env-badge <?php echo esc_attr( $env_cls ); ?>">
                    <?php echo esc_html( $env ); ?>
                </span>
            </h1>

            <?php /* Status line */ ?>
            <p class="brp-status-line">
                <?php if ( $stats['total'] > 0 ) : ?>
                    <span class="ok">●</span>
                    <?php echo esc_html( sprintf(
                        /* translators: 1: total, 2: approved, 3: pending */
                        __( '%1$d reviews imported &mdash; %2$d approved, %3$d pending', 'basalam-review-plugin' ),
                        $stats['total'], $stats['approved'], $stats['pending']
                    ) ); ?>
                <?php elseif ( $has_keys ) : ?>
                    <span class="ok">●</span>
                    <?php esc_html_e( 'Ready — no reviews imported yet', 'basalam-review-plugin' ); ?>
                <?php else : ?>
                    <span class="bad">●</span>
                    <?php esc_html_e( 'Setup required — generate your API credentials below', 'basalam-review-plugin' ); ?>
                <?php endif; ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields( 'brp_settings_group' ); ?>

                <?php /* ── Card 1: Authentication ───────────────────────── */ ?>
                <div class="brp-card">
                    <h2><?php esc_html_e( 'Authentication', 'basalam-review-plugin' ); ?></h2>
                    <p class="brp-card-desc">
                        <?php esc_html_e( 'Generate both keys here, save the page, then copy the values into your Server 2 .env file.', 'basalam-review-plugin' ); ?>
                    </p>

                    <div class="brp-field">
                        <div class="brp-field-label">
                            <label for="brp-api-key"><?php esc_html_e( 'API Key', 'basalam-review-plugin' ); ?></label>
                        </div>
                        <div class="brp-field-input">
                            <div class="brp-key-row">
                                <input type="password" id="brp-api-key"
                                       name="<?php echo $opt; ?>[api_key]"
                                       value="<?php echo esc_attr( $s['api_key'] ); ?>"
                                       autocomplete="off" />
                                <button type="button" class="brp-ibutton" data-eye="brp-api-key" title="<?php esc_attr_e( 'Show / hide', 'basalam-review-plugin' ); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                                <button type="button" class="brp-ibutton" data-copy="brp-api-key" title="<?php esc_attr_e( 'Copy', 'basalam-review-plugin' ); ?>">
                                    <span class="dashicons dashicons-admin-page"></span>
                                </button>
                                <button type="button" class="button button-small" data-gen="brp-api-key">
                                    <?php esc_html_e( '↻ Generate', 'basalam-review-plugin' ); ?>
                                </button>
                            </div>
                            <p class="brp-field-desc"><?php esc_html_e( 'WORDPRESS_API_KEY in Server 2 .env', 'basalam-review-plugin' ); ?></p>
                        </div>
                    </div>

                    <div class="brp-field">
                        <div class="brp-field-label">
                            <label for="brp-secret"><?php esc_html_e( 'Plugin Secret', 'basalam-review-plugin' ); ?></label>
                        </div>
                        <div class="brp-field-input">
                            <div class="brp-key-row">
                                <input type="password" id="brp-secret"
                                       name="<?php echo $opt; ?>[plugin_secret]"
                                       value="<?php echo esc_attr( $s['plugin_secret'] ); ?>"
                                       autocomplete="off" />
                                <button type="button" class="brp-ibutton" data-eye="brp-secret" title="<?php esc_attr_e( 'Show / hide', 'basalam-review-plugin' ); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                                <button type="button" class="brp-ibutton" data-copy="brp-secret" title="<?php esc_attr_e( 'Copy', 'basalam-review-plugin' ); ?>">
                                    <span class="dashicons dashicons-admin-page"></span>
                                </button>
                                <button type="button" class="button button-small" data-gen="brp-secret">
                                    <?php esc_html_e( '↻ Generate', 'basalam-review-plugin' ); ?>
                                </button>
                            </div>
                            <p class="brp-field-desc"><?php esc_html_e( 'WORDPRESS_PLUGIN_SECRET in Server 2 .env', 'basalam-review-plugin' ); ?></p>
                        </div>
                    </div>

                    <div class="brp-field" style="margin-top:14px;">
                        <div class="brp-field-label">
                            <label for="brp-env-label"><?php esc_html_e( 'Environment', 'basalam-review-plugin' ); ?></label>
                        </div>
                        <div class="brp-field-input">
                            <select id="brp-env-label" name="<?php echo $opt; ?>[env_label]">
                                <option value="DEV"        <?php selected( $s['env_label'] ?? 'DEV', 'DEV' ); ?>>DEV</option>
                                <option value="STAGING"    <?php selected( $s['env_label'] ?? 'DEV', 'STAGING' ); ?>>STAGING</option>
                                <option value="PRODUCTION" <?php selected( $s['env_label'] ?? 'DEV', 'PRODUCTION' ); ?>>PRODUCTION</option>
                            </select>
                            <p class="brp-field-desc"><?php esc_html_e( 'Label that identifies this WordPress site. Shown as a badge in the header and included in all maintenance confirmations. Must match your backend APP_ENV.', 'basalam-review-plugin' ); ?></p>
                        </div>
                    </div>

                    <div style="margin-top:14px;">
                        <button type="button" class="button" id="brp-regen-both">
                            <?php esc_html_e( '↻ Regenerate Both Keys', 'basalam-review-plugin' ); ?>
                        </button>
                    </div>
                </div>

                <?php /* ── Card 2: Review Display ─────────────────────────── */ ?>
                <div class="brp-card">
                    <h2><?php esc_html_e( 'Review Display', 'basalam-review-plugin' ); ?></h2>

                    <div class="brp-field">
                        <div class="brp-field-label">
                            <label for="brp-prefix"><?php esc_html_e( 'Name Prefix', 'basalam-review-plugin' ); ?></label>
                        </div>
                        <div class="brp-field-input">
                            <input type="text" id="brp-prefix"
                                   name="<?php echo $opt; ?>[customer_name_prefix]"
                                   value="<?php echo esc_attr( $s['customer_name_prefix'] ); ?>"
                                   class="regular-text"
                                   placeholder="<?php esc_attr_e( 'e.g. خریدار', 'basalam-review-plugin' ); ?>" />
                            <p class="brp-field-desc"><?php esc_html_e( 'Added before reviewer name.', 'basalam-review-plugin' ); ?></p>
                        </div>
                    </div>

                    <div class="brp-field">
                        <div class="brp-field-label">
                            <label for="brp-suffix"><?php esc_html_e( 'Name Suffix', 'basalam-review-plugin' ); ?></label>
                        </div>
                        <div class="brp-field-input">
                            <input type="text" id="brp-suffix"
                                   name="<?php echo $opt; ?>[customer_name_suffix]"
                                   value="<?php echo esc_attr( $s['customer_name_suffix'] ); ?>"
                                   class="regular-text"
                                   placeholder="<?php esc_attr_e( 'e.g. عزیز', 'basalam-review-plugin' ); ?>" />
                            <p class="brp-field-desc"><?php esc_html_e( 'Added after reviewer name.', 'basalam-review-plugin' ); ?></p>
                        </div>
                    </div>

                    <div class="brp-field">
                        <div class="brp-field-label"><?php esc_html_e( 'Auto-approve', 'basalam-review-plugin' ); ?></div>
                        <div class="brp-field-input">
                            <label class="brp-check-label">
                                <input type="checkbox" name="<?php echo $opt; ?>[auto_approve]"
                                       value="1" <?php checked( $s['auto_approve'] ); ?> />
                                <?php esc_html_e( 'Approve imported reviews immediately, skip moderation queue', 'basalam-review-plugin' ); ?>
                            </label>
                        </div>
                    </div>

                    <div class="brp-field">
                        <div class="brp-field-label"><?php esc_html_e( 'Star-only Reviews', 'basalam-review-plugin' ); ?></div>
                        <div class="brp-field-input">
                            <label class="brp-check-label">
                                <input type="checkbox" name="<?php echo $opt; ?>[import_star_only]"
                                       value="1" <?php checked( $s['import_star_only'] ?? true ); ?> />
                                <?php esc_html_e( 'Import star-only reviews (no text content)', 'basalam-review-plugin' ); ?>
                            </label>
                            <p class="brp-field-desc"><?php esc_html_e( 'When unchecked, reviews with empty or single-character content are rejected at insert time. Use Trash Star-only to clean up existing ones. For full enforcement before WordPress, also set BLOCK_STAR_ONLY_REVIEWS=true in backend .env.', 'basalam-review-plugin' ); ?></p>
                        </div>
                    </div>

                    <div class="brp-field">
                        <div class="brp-field-label"><?php esc_html_e( 'Product Image', 'basalam-review-plugin' ); ?></div>
                        <div class="brp-field-input">
                            <label class="brp-check-label">
                                <input type="checkbox" name="<?php echo $opt; ?>[attach_product_image]"
                                       value="1" <?php checked( $s['attach_product_image'] ); ?> />
                                <?php esc_html_e( 'Attach WooCommerce product thumbnail to each review', 'basalam-review-plugin' ); ?>
                            </label>
                        </div>
                    </div>

                </div>

                <?php /* ── Card 3: Seller Replies ───────────────────────── */ ?>
                <div class="brp-card">
                    <h2><?php esc_html_e( 'Seller Replies', 'basalam-review-plugin' ); ?></h2>

                    <div class="brp-field">
                        <div class="brp-field-label"><?php esc_html_e( 'Randomize Name', 'basalam-review-plugin' ); ?></div>
                        <div class="brp-field-input">
                            <label class="brp-check-label">
                                <input type="checkbox" name="<?php echo $opt; ?>[admin_name_randomizer]"
                                       value="1" <?php checked( $s['admin_name_randomizer'] ); ?> />
                                <?php esc_html_e( 'Pick a random name from the pool for each seller reply', 'basalam-review-plugin' ); ?>
                            </label>
                        </div>
                    </div>

                    <div class="brp-field">
                        <div class="brp-field-label">
                            <label for="brp-name-pool"><?php esc_html_e( 'Name Pool', 'basalam-review-plugin' ); ?></label>
                        </div>
                        <div class="brp-field-input">
                            <textarea id="brp-name-pool"
                                      name="<?php echo $opt; ?>[admin_name_pool]"
                                      rows="4" class="large-text"><?php echo esc_textarea( $s['admin_name_pool'] ); ?></textarea>
                            <p class="brp-field-desc"><?php esc_html_e( 'One name per line. Used when randomizer is enabled.', 'basalam-review-plugin' ); ?></p>
                        </div>
                    </div>

                </div>

                <?php /* ── Card 4: Debug Logs ─────────────────────────────── */ ?>
                <div class="brp-card">
                    <h2><?php esc_html_e( 'Debug Logs', 'basalam-review-plugin' ); ?></h2>
                    <p class="brp-card-desc">
                        <?php esc_html_e( 'Connect to the backend log server to view and clear sync logs. Save settings first after entering the URL and key.', 'basalam-review-plugin' ); ?>
                    </p>

                    <div class="brp-field">
                        <div class="brp-field-label"><?php esc_html_e( 'Logging', 'basalam-review-plugin' ); ?></div>
                        <div class="brp-field-input">
                            <label class="brp-check-label">
                                <input type="checkbox" name="<?php echo $opt; ?>[log_enabled]"
                                       value="1" <?php checked( $s['log_enabled'] ); ?> />
                                <?php esc_html_e( 'Enable — push plugin events to the backend log server', 'basalam-review-plugin' ); ?>
                            </label>
                        </div>
                    </div>

                    <div class="brp-field">
                        <div class="brp-field-label">
                            <label for="brp-log-endpoint"><?php esc_html_e( 'Log Server URL', 'basalam-review-plugin' ); ?></label>
                        </div>
                        <div class="brp-field-input">
                            <input type="text" id="brp-log-endpoint"
                                   name="<?php echo $opt; ?>[log_endpoint]"
                                   value="<?php echo esc_attr( $s['log_endpoint'] ); ?>"
                                   class="regular-text"
                                   placeholder="http://your-server-ip:8101" />
                            <p class="brp-field-desc"><?php esc_html_e( 'Backend log server address. Port is LOG_SERVER_PORT in .env (default 8101). Ensure port 8101 is open in your VPS firewall.', 'basalam-review-plugin' ); ?></p>
                        </div>
                    </div>

                    <div class="brp-field">
                        <div class="brp-field-label">
                            <label for="brp-log-api-key"><?php esc_html_e( 'Log API Key', 'basalam-review-plugin' ); ?></label>
                        </div>
                        <div class="brp-field-input">
                            <input type="text" id="brp-log-api-key"
                                   name="<?php echo $opt; ?>[log_api_key]"
                                   value="<?php echo esc_attr( $s['log_api_key'] ); ?>"
                                   class="regular-text"
                                   placeholder="<?php esc_attr_e( 'Leave blank to use the API Key above', 'basalam-review-plugin' ); ?>" />
                            <p class="brp-field-desc"><?php esc_html_e( 'Leave blank to use the API Key from Card 1 (WORDPRESS_API_KEY in .env).', 'basalam-review-plugin' ); ?></p>
                        </div>
                    </div>

                    <div class="brp-field" style="margin-top:6px;">
                        <div class="brp-field-label"></div>
                        <div class="brp-field-input">
                            <button type="button" class="button" id="brp-check-conn">
                                <?php esc_html_e( '&#10003; Check Backend Connection', 'basalam-review-plugin' ); ?>
                            </button>
                            <span id="brp-conn-status" style="font-size:12px; margin-left:8px; color:#646970;"></span>
                            <p class="brp-field-desc"><?php esc_html_e( 'Calls the backend /status endpoint to verify the connection and confirm which environment is active.', 'basalam-review-plugin' ); ?></p>
                            <pre id="brp-conn-result" style="
                                background:#1d2327; color:#a0c4a0;
                                padding:10px; border-radius:3px;
                                font-size:11px; line-height:1.5;
                                display:none; margin-top:8px;
                                white-space:pre-wrap;
                            "></pre>
                        </div>
                    </div>

                </div>

                <div class="brp-submit">
                    <?php submit_button( __( 'Save Settings', 'basalam-review-plugin' ), 'primary', 'submit', false ); ?>
                </div>

            </form>

            <?php /* ── Log viewer (outside form — uses AJAX, not form POST) ── */ ?>
            <div class="brp-card">
                <h2><?php esc_html_e( 'Log Viewer', 'basalam-review-plugin' ); ?></h2>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:10px;">
                    <button type="button" class="button" id="brp-view-logs">
                        <?php esc_html_e( '&#8635; View Logs', 'basalam-review-plugin' ); ?>
                    </button>
                    <button type="button" class="button" id="brp-clear-logs" style="color:#d63638;">
                        <?php esc_html_e( '&#10005; Clear Logs', 'basalam-review-plugin' ); ?>
                    </button>
                    <label style="font-size:12px; color:#646970; margin-left:4px;">
                        <?php esc_html_e( 'Lines:', 'basalam-review-plugin' ); ?>
                        <select id="brp-log-lines" style="margin-left:4px;">
                            <option value="100">100</option>
                            <option value="200" selected>200</option>
                            <option value="500">500</option>
                        </select>
                    </label>
                    <span id="brp-logs-status" style="font-size:12px; color:#646970;"></span>
                </div>
                <pre id="brp-log-output" style="
                    background:#1d2327; color:#a0c4a0;
                    padding:14px; border-radius:3px;
                    font-size:11px; line-height:1.5;
                    max-height:400px; overflow-y:auto;
                    white-space:pre-wrap; word-break:break-all;
                    display:none; margin:0;
                "></pre>
            </div>

            <?php /* ── Card: Sync Status ──────────────────────────────────── */ ?>
            <div class="brp-card">
                <h2><?php esc_html_e( 'Sync Status', 'basalam-review-plugin' ); ?></h2>
                <p class="brp-card-desc">
                    <?php esc_html_e( 'Live backend state. Click "Check Backend Connection" in the Debug Logs card above to refresh.', 'basalam-review-plugin' ); ?>
                </p>
                <table class="brp-status-table" id="brp-status-table">
                    <tr><th><?php esc_html_e( 'Backend Environment', 'basalam-review-plugin' ); ?></th><td id="brps-env">—</td></tr>
                    <tr><th><?php esc_html_e( 'Star-only Policy (backend)', 'basalam-review-plugin' ); ?></th><td id="brps-block-star">—</td></tr>
                    <tr><th><?php esc_html_e( 'Auto Sync Schedule', 'basalam-review-plugin' ); ?></th><td><?php esc_html_e( 'Every 6 hours', 'basalam-review-plugin' ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Total in Backend DB', 'basalam-review-plugin' ); ?></th><td id="brps-total">—</td></tr>
                    <tr><th><?php esc_html_e( 'Synced to WordPress', 'basalam-review-plugin' ); ?></th><td id="brps-synced">—</td></tr>
                    <tr id="brps-blocked-row" style="display:none;"><th><?php esc_html_e( 'Blocked by Policy', 'basalam-review-plugin' ); ?></th><td id="brps-blocked" style="color:#996800;">—</td></tr>
                    <tr id="brps-nomapping-row" style="display:none;"><th><?php esc_html_e( 'No Product Mapping', 'basalam-review-plugin' ); ?></th><td id="brps-nomapping" style="color:#996800;">—</td></tr>
                    <tr><th><?php esc_html_e( 'Pending Push', 'basalam-review-plugin' ); ?></th><td id="brps-unsynced">—</td></tr>
                    <tr><th><?php esc_html_e( 'Last Crawl', 'basalam-review-plugin' ); ?></th><td id="brps-crawl">—</td></tr>
                    <tr><th><?php esc_html_e( 'Next Crawl Allowed', 'basalam-review-plugin' ); ?></th><td id="brps-next-crawl">—</td></tr>
                    <tr><th><?php esc_html_e( 'Last Sync Run', 'basalam-review-plugin' ); ?></th><td id="brps-last-run">—</td></tr>
                    <tr><th><?php esc_html_e( 'Last Error', 'basalam-review-plugin' ); ?></th><td id="brps-last-error" style="color:#d63638;">—</td></tr>
                </table>
            </div>

            <?php /* ── Card: Maintenance ─────────────────────────────────── */ ?>
            <div class="brp-card">
                <h2><?php esc_html_e( 'Maintenance', 'basalam-review-plugin' ); ?></h2>
                <p class="brp-card-desc">
                    <?php esc_html_e( 'One-time fix operations. Run only when needed.', 'basalam-review-plugin' ); ?>
                </p>
                <div class="brp-field">
                    <div class="brp-field-label"><?php esc_html_e( 'Sync Missed Reviews', 'basalam-review-plugin' ); ?></div>
                    <div class="brp-field-input">
                        <button type="button" class="button button-primary" id="brp-trigger-sync">
                            <?php esc_html_e( 'Sync Missed Reviews Now', 'basalam-review-plugin' ); ?>
                        </button>
                        <button type="button" class="button" id="brp-sync-abort" style="display:none; margin-left:6px;">
                            <?php esc_html_e( 'Abort', 'basalam-review-plugin' ); ?>
                        </button>
                        <span id="brp-sync-status" style="font-size:12px; margin-left:8px; color:#646970;"></span>
                        <pre id="brp-sync-log" style="display:none; margin-top:6px; font-size:11px; background:#f0f0f1; padding:8px; border-radius:3px; white-space:pre-wrap; max-height:150px; overflow-y:auto;"></pre>
                        <p class="brp-field-desc"><?php esc_html_e( 'Pushes all pending reviews from the backend DB to WordPress, batch by batch (50 per batch), until the queue is empty. Runs automatically — no need to click multiple times.', 'basalam-review-plugin' ); ?></p>
                    </div>
                </div>

                <div class="brp-field">
                    <div class="brp-field-label"><?php esc_html_e( 'Unapprove Star-only', 'basalam-review-plugin' ); ?></div>
                    <div class="brp-field-input">
                        <button type="button" class="button" id="brp-fix-star-only">
                            <?php esc_html_e( 'Unapprove star-only reviews', 'basalam-review-plugin' ); ?>
                        </button>
                        <p class="brp-field-desc"><?php esc_html_e( 'Sets status to pending for all imported reviews with no text content. Runs automatically on plugin upgrade. Product ratings are recalculated.', 'basalam-review-plugin' ); ?></p>
                        <p id="brp-fix-result" style="font-size:12px; color:#00a32a; margin-top:6px; display:none;"></p>
                    </div>
                </div>

                <div class="brp-field">
                    <div class="brp-field-label"><?php esc_html_e( 'Trash Star-only', 'basalam-review-plugin' ); ?></div>
                    <div class="brp-field-input">
                        <button type="button" class="button" id="brp-trash-preview">
                            <?php esc_html_e( 'Preview', 'basalam-review-plugin' ); ?>
                        </button>
                        <button type="button" class="button" id="brp-trash-confirm" style="display:none; color:#d63638; margin-left:6px;">
                            <?php esc_html_e( '&#10003; Confirm: Move to Trash', 'basalam-review-plugin' ); ?>
                        </button>
                        <p class="brp-field-desc"><?php esc_html_e( 'Moves star-only imported reviews to Trash (recoverable from WP Admin → Comments → Trash). Product ratings are recalculated. Only affects plugin-imported reviews.', 'basalam-review-plugin' ); ?></p>
                        <p id="brp-trash-result" style="font-size:12px; margin-top:6px; display:none;"></p>
                    </div>
                </div>

                <div class="brp-field">
                    <div class="brp-field-label"><?php esc_html_e( 'Fix Visibility', 'basalam-review-plugin' ); ?></div>
                    <div class="brp-field-input">
                        <button type="button" class="button" id="brp-email-preview">
                            <?php esc_html_e( 'Preview', 'basalam-review-plugin' ); ?>
                        </button>
                        <button type="button" class="button" id="brp-email-confirm" style="display:none; margin-left:6px;">
                            <?php esc_html_e( '&#10003; Confirm: Migrate Emails', 'basalam-review-plugin' ); ?>
                        </button>
                        <p class="brp-field-desc"><?php esc_html_e( 'Sets a placeholder email on imported reviews to prevent a WordPress bug where pending reviews appear to visitors as their own. Run once after upgrading to v1.3.', 'basalam-review-plugin' ); ?></p>
                        <p id="brp-email-result" style="font-size:12px; margin-top:6px; display:none;"></p>
                    </div>
                </div>

                <div class="brp-field">
                    <div class="brp-field-label"><?php esc_html_e( 'Remove Duplicate Replies', 'basalam-review-plugin' ); ?></div>
                    <div class="brp-field-input">
                        <button type="button" class="button" id="brp-dup-preview">
                            <?php esc_html_e( 'Preview', 'basalam-review-plugin' ); ?>
                        </button>
                        <button type="button" class="button" id="brp-dup-confirm" style="display:none; color:#d63638; margin-left:6px;">
                            <?php esc_html_e( '&#10003; Confirm: Delete Orphan Replies', 'basalam-review-plugin' ); ?>
                        </button>
                        <p class="brp-field-desc"><?php esc_html_e( 'Permanently removes plugin reply comments that have no Basalam answer ID (untrackable duplicates created during the first sync). This is the only action that permanently deletes — run dryrun first.', 'basalam-review-plugin' ); ?></p>
                        <p id="brp-dup-result" style="font-size:12px; margin-top:6px; display:none;"></p>
                    </div>
                </div>

                <div class="brp-field">
                    <div class="brp-field-label"><?php esc_html_e( 'Refresh Ratings', 'basalam-review-plugin' ); ?></div>
                    <div class="brp-field-input">
                        <button type="button" class="button" id="brp-ratings-preview">
                            <?php esc_html_e( 'Preview', 'basalam-review-plugin' ); ?>
                        </button>
                        <button type="button" class="button" id="brp-ratings-confirm" style="display:none; margin-left:6px;">
                            <?php esc_html_e( '&#10003; Confirm: Refresh Ratings', 'basalam-review-plugin' ); ?>
                        </button>
                        <p class="brp-field-desc"><?php esc_html_e( 'Recalculates WooCommerce average rating, review count, and rating distribution for all products that have active imported Basalam reviews. Only affects imported-review products.', 'basalam-review-plugin' ); ?></p>
                        <p id="brp-ratings-result" style="font-size:12px; margin-top:6px; display:none;"></p>
                    </div>
                </div>

                <div class="brp-field">
                    <div class="brp-field-label"><?php esc_html_e( 'Trash All Imported', 'basalam-review-plugin' ); ?></div>
                    <div class="brp-field-input">
                        <button type="button" class="button" id="brp-trashall-preview">
                            <?php esc_html_e( 'Preview', 'basalam-review-plugin' ); ?>
                        </button>
                        <button type="button" class="button brp-btn-danger" id="brp-trashall-confirm" style="display:none; margin-left:6px;">
                            <?php esc_html_e( '&#10003; Confirm: Trash All Imported Reviews', 'basalam-review-plugin' ); ?>
                        </button>
                        <p class="brp-field-desc"><?php esc_html_e( 'Moves ALL active imported Basalam reviews (and their plugin-owned replies) to Trash. Recoverable from WP Admin → Comments → Trash. Does not touch manual reviews. Does not clear the backend DB.', 'basalam-review-plugin' ); ?></p>
                        <p id="brp-trashall-result" style="font-size:12px; margin-top:6px; display:none;"></p>
                    </div>
                </div>

                <div class="brp-field">
                    <div class="brp-field-label"><?php esc_html_e( 'Clear Synced to WordPress', 'basalam-review-plugin' ); ?></div>
                    <div class="brp-field-input">
                        <button type="button" class="button" id="brp-resetsync-preview">
                            <?php esc_html_e( 'Preview', 'basalam-review-plugin' ); ?>
                        </button>
                        <button type="button" class="button brp-btn-danger" id="brp-resetsync-confirm" style="display:none; margin-left:6px;">
                            <?php esc_html_e( '&#10003; Confirm: Clear Sync State', 'basalam-review-plugin' ); ?>
                        </button>
                        <p class="brp-field-desc"><?php esc_html_e( 'Resets the backend DB sync state so all reviews can be re-imported to WordPress. Use after a WordPress database restore or reset. Existing WP comments are deduplicated automatically — no duplicates are created. After confirming, click "Sync Missed Reviews" to re-import (batches of 50).', 'basalam-review-plugin' ); ?></p>
                        <p id="brp-resetsync-result" style="font-size:12px; margin-top:6px; display:none;"></p>
                    </div>
                </div>

                <div class="brp-field">
                    <div class="brp-field-label"><?php esc_html_e( 'Permanently Delete All Imported', 'basalam-review-plugin' ); ?></div>
                    <div class="brp-field-input">
                        <button type="button" class="button brp-btn-danger" id="brp-deleteall-preview">
                            <?php esc_html_e( 'Preview', 'basalam-review-plugin' ); ?>
                        </button>
                        <span id="brp-deleteall-preview-text" style="margin-left:10px; font-size:12px;"></span>
                        <div id="brp-deleteall-confirm" style="display:none; margin-top:8px; padding:8px; background:#fff3f3; border:1px solid #d63638; border-radius:3px;">
                            <strong><?php echo esc_html( sprintf(
                                /* translators: %s: env label */
                                __( '[%s] Permanently delete all imported reviews?', 'basalam-review-plugin' ),
                                strtoupper( $s['env_label'] ?? 'DEV' )
                            ) ); ?></strong><br>
                            <span id="brp-deleteall-confirm-text" style="font-size:12px;"></span>
                            <strong><?php esc_html_e( 'This is irreversible and removes all statuses including Trash. Backend DB is unchanged — re-sync is still possible.', 'basalam-review-plugin' ); ?></strong><br><br>
                            <button type="button" class="button brp-btn-danger" id="brp-deleteall-confirm-btn">
                                <?php esc_html_e( 'Yes, Permanently Delete All', 'basalam-review-plugin' ); ?>
                            </button>
                            <button type="button" class="button" id="brp-deleteall-cancel-btn" style="margin-left:6px;">
                                <?php esc_html_e( 'Cancel', 'basalam-review-plugin' ); ?>
                            </button>
                        </div>
                        <p class="brp-field-desc"><?php esc_html_e( 'Permanently removes ALL plugin-imported root reviews and their child comments (any status: active, trashed, spam). Use when Trash All leaves residual reviews in the WP Trash. Does not affect backend DB sync state.', 'basalam-review-plugin' ); ?></p>
                        <p id="brp-deleteall-result" style="font-size:12px; margin-top:6px; display:none;"></p>
                    </div>
                </div>
            </div>

            <?php /* Footer — version, WC status, health endpoint */ ?>
            <p class="brp-footer">
                <?php
                $wc_label = class_exists( 'WooCommerce' )
                    ? sprintf( __( 'WooCommerce %s', 'basalam-review-plugin' ), WC()->version )
                    : __( 'WooCommerce not active', 'basalam-review-plugin' );
                ?>
                <?php esc_html_e( 'Plugin', 'basalam-review-plugin' ); ?> v<?php echo esc_html( BRP_VERSION ); ?>
                <span class="sep">·</span>
                <?php echo esc_html( $wc_label ); ?>
                <span class="sep">·</span>
                <a href="<?php echo esc_url( home_url( '/wp-json/basalam-review/v1/health' ) ); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e( 'Health check ↗', 'basalam-review-plugin' ); ?>
                </a>
            </p>

        </div><!-- .wrap.brp-page -->

        <script>
        (function () {
            var brpAjax = {
                url:   '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                nonce: '<?php echo esc_js( wp_create_nonce( 'brp_logs_nonce' ) ); ?>',
                env:   '<?php echo esc_js( strtoupper( $s['env_label'] ?? 'DEV' ) ); ?>'
            };

            function randHex(n) {
                var a = new Uint8Array(n);
                window.crypto.getRandomValues(a);
                return Array.from(a).map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
            }

            // Show / hide password fields
            document.querySelectorAll('[data-eye]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var inp  = document.getElementById(this.dataset.eye);
                    var icon = this.querySelector('.dashicons');
                    if (!inp) return;
                    var show = inp.type === 'password';
                    inp.type = show ? 'text' : 'password';
                    if (icon) icon.classList.toggle('dashicons-visibility', !show);
                    if (icon) icon.classList.toggle('dashicons-hidden',     show);
                });
            });

            // Copy to clipboard
            document.querySelectorAll('[data-copy]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var inp = document.getElementById(this.dataset.copy);
                    if (!inp || !inp.value) return;
                    var self = this;
                    navigator.clipboard.writeText(inp.value).then(function() {
                        self.classList.add('did-copy');
                        setTimeout(function(){ self.classList.remove('did-copy'); }, 1500);
                    });
                });
            });

            // Generate single key
            document.querySelectorAll('[data-gen]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var inp = document.getElementById(this.dataset.gen);
                    if (inp) inp.value = randHex(32);
                });
            });

            // Regenerate both
            var regenBtn = document.getElementById('brp-regen-both');
            if (regenBtn) {
                regenBtn.addEventListener('click', function() {
                    var k = document.getElementById('brp-api-key');
                    var s = document.getElementById('brp-secret');
                    if (k) k.value = randHex(32);
                    if (s) s.value = randHex(32);
                });
            }

            // ── Log viewer ───────────────────────────────────────────────────
            var viewBtn   = document.getElementById('brp-view-logs');
            var clearBtn  = document.getElementById('brp-clear-logs');
            var fixBtn    = document.getElementById('brp-fix-star-only');
            var logOut    = document.getElementById('brp-log-output');
            var logStatus = document.getElementById('brp-logs-status');
            var fixResult = document.getElementById('brp-fix-result');
            var linesEl   = document.getElementById('brp-log-lines');

            function setStatus(msg) {
                if (logStatus) logStatus.textContent = msg;
            }

            if (viewBtn) {
                viewBtn.addEventListener('click', function() {
                    setStatus('Loading…');
                    var lines = linesEl ? linesEl.value : '200';
                    var fd = new FormData();
                    fd.append('action', 'brp_view_logs');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('lines',  lines);
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                logOut.textContent = data.data.logs || '(empty)';
                                logOut.style.display = 'block';
                                logOut.scrollTop = logOut.scrollHeight;
                                setStatus('');
                            } else {
                                setStatus('Error: ' + (data.data || 'unknown'));
                            }
                        })
                        .catch(function(e) { setStatus('Network error: ' + e); });
                });
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    if (!confirm('[' + brpAjax.env + '] Clear backend debug logs for this environment?')) { return; }
                    setStatus('Clearing…');
                    var fd = new FormData();
                    fd.append('action', 'brp_clear_logs');
                    fd.append('nonce',  brpAjax.nonce);
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                if (logOut) { logOut.textContent = ''; logOut.style.display = 'none'; }
                                setStatus('Cleared.');
                                setTimeout(function() { setStatus(''); }, 2000);
                            } else {
                                setStatus('Error: ' + (data.data || 'unknown'));
                            }
                        })
                        .catch(function(e) { setStatus('Network error: ' + e); });
                });
            }

            if (fixBtn) {
                fixBtn.addEventListener('click', function() {
                    fixBtn.disabled = true;
                    if (fixResult) fixResult.style.display = 'none';
                    var fd = new FormData();
                    fd.append('action', 'brp_fix_star_only');
                    fd.append('nonce',  brpAjax.nonce);
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            fixBtn.disabled = false;
                            if (data.success && fixResult) {
                                var d = data.data;
                                if (d.updated === 0) {
                                    fixResult.textContent = 'Nothing to do — ' + d.already_pending + ' star-only reviews are already in pending status (set on import, not an error).';
                                    fixResult.style.color = '#646970';
                                } else {
                                    fixResult.textContent = d.updated + ' star-only reviews set to pending. ' + d.already_pending + ' total are now in pending status.';
                                    fixResult.style.color = '#00a32a';
                                }
                                fixResult.style.display = 'block';
                            }
                        })
                        .catch(function() { fixBtn.disabled = false; });
                });
            }

            var syncBtn    = document.getElementById('brp-trigger-sync');
            var syncAbort  = document.getElementById('brp-sync-abort');
            var syncStatus = document.getElementById('brp-sync-status');
            var syncLog    = document.getElementById('brp-sync-log');
            var syncAborted = false;

            function brpFetchStatus(cb) {
                var fd = new FormData();
                fd.append('action', 'brp_check_connection');
                fd.append('nonce',  brpAjax.nonce);
                fetch(brpAjax.url, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) { cb(d.success ? d.data : null); })
                    .catch(function() { cb(null); });
            }

            function brpSyncFinish(msg, color) {
                if (syncBtn)   syncBtn.disabled = false;
                if (syncAbort) syncAbort.style.display = 'none';
                if (syncStatus) { syncStatus.textContent = msg; syncStatus.style.color = color; }
                // Refresh the Sync Status table with latest counts
                brpFetchStatus(function(d) { if (d) brpSetStatusTable(d); });
            }

            if (syncAbort) {
                syncAbort.addEventListener('click', function() {
                    syncAborted = true;
                    syncAbort.disabled = true;
                    if (syncStatus) syncStatus.textContent = 'Aborting after current batch…';
                });
            }

            if (syncBtn) {
                syncBtn.addEventListener('click', function() {
                    syncBtn.disabled = true;
                    syncAborted = false;
                    if (syncAbort) { syncAbort.style.display = 'inline-block'; syncAbort.disabled = false; }
                    if (syncStatus) { syncStatus.textContent = 'Starting sync…'; syncStatus.style.color = '#646970'; }
                    if (syncLog) { syncLog.style.display = 'block'; syncLog.textContent = ''; }

                    var totalInserted = 0, totalSkipped = 0, totalErrors = 0;
                    var batchNum = 0;
                    var prevUnsynced = null;
                    var logLines = [];

                    function addLog(line) {
                        logLines.push(line);
                        if (syncLog) {
                            syncLog.textContent = logLines.join('\n');
                            syncLog.scrollTop = syncLog.scrollHeight;
                        }
                    }

                    function runBatch() {
                        if (syncAborted) {
                            brpSyncFinish(
                                'Aborted after ' + batchNum + ' batches — ' + totalInserted + ' imported, ' + totalSkipped + ' skipped, ' + totalErrors + ' errors.',
                                '#996800'
                            );
                            return;
                        }

                        batchNum++;
                        if (syncStatus) syncStatus.textContent = 'Batch ' + batchNum + ' — pushing reviews…';

                        var fd = new FormData();
                        fd.append('action', 'brp_trigger_sync');
                        fd.append('nonce',  brpAjax.nonce);

                        fetch(brpAjax.url, { method: 'POST', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (!data.success) {
                                    addLog('Batch ' + batchNum + ': ERROR — ' + (data.data || 'unknown'));
                                    brpSyncFinish('Error: ' + (data.data || 'unknown'), '#d63638');
                                    return;
                                }
                                var d = data.data;
                                if (d.status === 'already_running') {
                                    brpSyncFinish('Sync already running on backend — wait and try again.', '#996800');
                                    return;
                                }

                                var ins = d.inserted || 0;
                                var skp = d.skipped  || 0;
                                var err = d.errors   || 0;
                                totalInserted += ins;
                                totalSkipped  += skp;
                                totalErrors   += err;

                                // Fetch /status to see current queue depth
                                brpFetchStatus(function(status) {
                                    var db = status ? (status.db || {}) : {};
                                    var unsynced   = db.unsynced   != null ? db.unsynced   : null;
                                    var noMapping  = db.no_mapping != null ? db.no_mapping : 0;

                                    var logLine = 'Batch ' + batchNum + ': +' + ins + ' imported';
                                    if (skp > 0) logLine += ', ' + skp + ' skipped';
                                    if (err > 0) logLine += ', ' + err + ' errors';
                                    if (unsynced != null) logLine += ' | Queue remaining: ' + unsynced;
                                    addLog(logLine);

                                    // Update status table inline
                                    if (status) brpSetStatusTable(status);

                                    // Stop conditions:
                                    // 1) Queue fully empty
                                    if (unsynced != null && unsynced === 0) {
                                        brpSyncFinish(
                                            'Sync complete — ' + totalInserted + ' reviews imported, ' + totalSkipped + ' skipped (policy-blocked). Queue empty.',
                                            '#00a32a'
                                        );
                                        return;
                                    }
                                    // 2) Queue stopped draining (only permanently-stuck no-mapping reviews remain)
                                    if (prevUnsynced != null && unsynced != null && unsynced >= prevUnsynced) {
                                        var stuckMsg = 'Sync complete — ' + totalInserted + ' reviews imported, ' + totalSkipped + ' skipped.';
                                        if (unsynced > 0) stuckMsg += ' ' + unsynced + ' reviews remain (no WooCommerce product mapping found).';
                                        brpSyncFinish(stuckMsg, totalErrors > 0 ? '#d63638' : '#00a32a');
                                        return;
                                    }
                                    // 3) Transient errors only, no progress on inserts and skips
                                    if (ins === 0 && skp === 0 && err > 0) {
                                        brpSyncFinish(
                                            'Stopped: only errors in last batch (' + err + '). ' + totalInserted + ' imported so far. Try again.',
                                            '#d63638'
                                        );
                                        return;
                                    }

                                    prevUnsynced = unsynced;
                                    setTimeout(runBatch, 400);
                                });
                            })
                            .catch(function() {
                                addLog('Batch ' + batchNum + ': network error');
                                brpSyncFinish('Network error on batch ' + batchNum + '. ' + totalInserted + ' imported so far.', '#d63638');
                            });
                    }

                    runBatch();
                });
            }

            // ── Check backend connection ─────────────────────────────────────
            var connBtn    = document.getElementById('brp-check-conn');
            var connStatus = document.getElementById('brp-conn-status');
            var connResult = document.getElementById('brp-conn-result');

            function brpSetStatusTable(d) {
                function setCell(id, text, color) {
                    var el = document.getElementById(id);
                    if (!el) return;
                    el.textContent = text || '—';
                    if (color) el.style.color = color;
                }
                if (!d) return;
                setCell('brps-env', d.env);
                setCell('brps-block-star', d.block_star_only ? 'Block star-only (BLOCK_STAR_ONLY_REVIEWS=true)' : 'Allow (BLOCK_STAR_ONLY_REVIEWS=false)');
                var db = d.db || {};
                setCell('brps-total', db.total_reviews != null ? String(db.total_reviews) : '—');
                setCell('brps-synced', db.synced != null ? String(db.synced) : '—');
                if (db.blocked != null && db.blocked > 0) {
                    setCell('brps-blocked', String(db.blocked) + ' (star-only or policy-rejected — skipped by backend)');
                    var blockedRow = document.getElementById('brps-blocked-row');
                    if (blockedRow) blockedRow.style.display = '';
                }
                if (db.no_mapping != null && db.no_mapping > 0) {
                    setCell('brps-nomapping', String(db.no_mapping) + ' (no WooCommerce product mapping found)');
                    var noMappingRow = document.getElementById('brps-nomapping-row');
                    if (noMappingRow) noMappingRow.style.display = '';
                }
                setCell('brps-unsynced', db.unsynced != null ? String(db.unsynced) : '—', db.unsynced > 0 ? '#996800' : '');
                setCell('brps-crawl', db.last_crawled_at || 'Never');
                setCell('brps-next-crawl', db.next_crawl_allowed_at
                    ? db.next_crawl_allowed_at + ' (' + (db.crawl_interval_hours || '?') + 'h interval)'
                    : '—');
                var lr = db.last_run;
                setCell('brps-last-run', lr ? (lr.run_at + ' [' + lr.mode + ']') : '—');
                setCell('brps-last-error', db.last_error || 'None', db.last_error ? '#d63638' : '#00a32a');
            }

            if (connBtn) {
                connBtn.addEventListener('click', function() {
                    connBtn.disabled = true;
                    if (connStatus) connStatus.textContent = 'Connecting…';
                    if (connResult) connResult.style.display = 'none';
                    var fd = new FormData();
                    fd.append('action', 'brp_check_connection');
                    fd.append('nonce',  brpAjax.nonce);
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            connBtn.disabled = false;
                            if (data.success) {
                                var d = data.data;
                                connStatus.textContent = 'Connected';
                                connStatus.style.color = '#00a32a';
                                var dbLine = d.db ? (
                                    'DB reviews : total=' + d.db.total_reviews +
                                    ' | synced=' + d.db.synced +
                                    (d.db.blocked   ? ' | blocked='    + d.db.blocked   : '') +
                                    (d.db.no_mapping ? ' | no_mapping=' + d.db.no_mapping : '') +
                                    ' | unsynced=' + d.db.unsynced
                                ) : '';
                                connResult.textContent =
                                    'Backend environment : ' + (d.env || '?') + '\n' +
                                    'Database path       : ' + (d.db_path || '?') + '\n' +
                                    'WordPress endpoint  : ' + (d.wordpress || '(not set)') +
                                    (dbLine ? '\n' + dbLine : '');
                                connResult.style.color = '#a0c4a0';
                                connResult.style.display = 'block';
                                // Populate Sync Status card
                                brpSetStatusTable(d);
                                // Warn if backend env does not match plugin env label
                                if (d.env && d.env !== brpAjax.env) {
                                    connResult.textContent += '\n\n⚠ MISMATCH: Plugin is labelled "' + brpAjax.env + '" but backend reports "' + d.env + '".';
                                    connResult.style.color = '#d63638';
                                }
                                // Warn if unsynced reviews exist
                                if (d.db && d.db.unsynced > 0) {
                                    connResult.textContent += '\n\n⚠ ' + d.db.unsynced + ' reviews in backend queue not yet pushed to WordPress. Click Sync Missed Reviews.';
                                }
                            } else {
                                connStatus.textContent = 'Failed: ' + (data.data || 'unknown error');
                                connStatus.style.color = '#d63638';
                            }
                            setTimeout(function() { connStatus.style.color = ''; }, 8000);
                        })
                        .catch(function() {
                            connBtn.disabled = false;
                            if (connStatus) connStatus.textContent = 'Network error.';
                        });
                });
            }

            // ── Trash star-only reviews (two-step) ───────────────────────────
            var trashPreviewBtn  = document.getElementById('brp-trash-preview');
            var trashConfirmBtn  = document.getElementById('brp-trash-confirm');
            var trashResult      = document.getElementById('brp-trash-result');

            if (trashPreviewBtn) {
                trashPreviewBtn.addEventListener('click', function() {
                    trashPreviewBtn.disabled = true;
                    if (trashResult) { trashResult.style.display = 'none'; }
                    if (trashConfirmBtn) trashConfirmBtn.style.display = 'none';
                    var fd = new FormData();
                    fd.append('action', 'brp_trash_star_only');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'dryrun');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            trashPreviewBtn.disabled = false;
                            if (data.success && trashResult) {
                                var d = data.data;
                                var alreadyMsg = d.already_trashed > 0 ? ' (' + d.already_trashed + ' already in Trash).' : '.';
                                if (d.reviews === 0) {
                                    trashResult.textContent = 'No active star-only reviews found' + alreadyMsg;
                                    trashResult.style.color = '#646970';
                                } else {
                                    trashResult.textContent =
                                        'Approved star-only: ' + d.approved_count + '\n' +
                                        'Pending star-only:  ' + d.pending_count + '\n' +
                                        'Already in Trash:   ' + d.already_trashed + '\n' +
                                        'Affected products:  ' + d.products + ' (ratings will be recalculated)';
                                    trashResult.style.color = '#996800';
                                    if (trashConfirmBtn) trashConfirmBtn.style.display = 'inline-block';
                                }
                                trashResult.style.display = 'block';
                            }
                        })
                        .catch(function() { trashPreviewBtn.disabled = false; });
                });
            }

            if (trashConfirmBtn) {
                trashConfirmBtn.addEventListener('click', function() {
                    if (!confirm('[' + brpAjax.env + '] Move these star-only reviews to Trash?\n\nEnvironment: ' + brpAjax.env + '\nRecoverable from WP Admin → Comments → Trash.')) return;
                    trashConfirmBtn.disabled = true;
                    trashPreviewBtn.disabled = true;
                    if (trashResult) { trashResult.textContent = 'Running…'; trashResult.style.color = '#646970'; }
                    var fd = new FormData();
                    fd.append('action', 'brp_trash_star_only');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'execute');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            trashConfirmBtn.style.display = 'none';
                            trashPreviewBtn.disabled = false;
                            if (trashResult) {
                                if (data.success) {
                                    var d = data.data;
                                    trashResult.textContent = 'Done: ' + d.updated + ' reviews moved to Trash, ' + d.products_recalculated + ' product ratings updated.';
                                    trashResult.style.color = '#00a32a';
                                } else {
                                    trashResult.textContent = 'Error: ' + (data.data || 'unknown');
                                    trashResult.style.color = '#d63638';
                                }
                            }
                        })
                        .catch(function() { trashConfirmBtn.disabled = false; trashPreviewBtn.disabled = false; });
                });
            }

            // ── Migrate import emails (two-step) ─────────────────────────────
            var emailPreviewBtn = document.getElementById('brp-email-preview');
            var emailConfirmBtn = document.getElementById('brp-email-confirm');
            var emailResult     = document.getElementById('brp-email-result');

            if (emailPreviewBtn) {
                emailPreviewBtn.addEventListener('click', function() {
                    emailPreviewBtn.disabled = true;
                    if (emailResult) { emailResult.style.display = 'none'; }
                    if (emailConfirmBtn) emailConfirmBtn.style.display = 'none';
                    var fd = new FormData();
                    fd.append('action', 'brp_migrate_emails');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'dryrun');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            emailPreviewBtn.disabled = false;
                            if (data.success && emailResult) {
                                var d = data.data;
                                if (d.reviews === 0) {
                                    emailResult.textContent = 'All imported reviews already have a placeholder email. No action needed.';
                                    emailResult.style.color = '#00a32a';
                                } else {
                                    emailResult.textContent = d.reviews + ' imported reviews will have their email field updated.';
                                    emailResult.style.color = '#996800';
                                    if (emailConfirmBtn) emailConfirmBtn.style.display = 'inline-block';
                                }
                                emailResult.style.display = 'block';
                            }
                        })
                        .catch(function() { emailPreviewBtn.disabled = false; });
                });
            }

            if (emailConfirmBtn) {
                emailConfirmBtn.addEventListener('click', function() {
                    if (!confirm('[' + brpAjax.env + '] Update email field on all imported reviews on this site?\n\nEnvironment: ' + brpAjax.env + '\nThis prevents a WordPress visibility bug for unapproved reviews.')) return;
                    emailConfirmBtn.disabled = true;
                    emailPreviewBtn.disabled = true;
                    if (emailResult) { emailResult.textContent = 'Running…'; emailResult.style.color = '#646970'; }
                    var fd = new FormData();
                    fd.append('action', 'brp_migrate_emails');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'execute');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            emailConfirmBtn.style.display = 'none';
                            emailPreviewBtn.disabled = false;
                            if (emailResult) {
                                if (data.success) {
                                    emailResult.textContent = 'Done: ' + data.data.updated + ' reviews updated.';
                                    emailResult.style.color = '#00a32a';
                                } else {
                                    emailResult.textContent = 'Error: ' + (data.data || 'unknown');
                                    emailResult.style.color = '#d63638';
                                }
                            }
                        })
                        .catch(function() { emailConfirmBtn.disabled = false; emailPreviewBtn.disabled = false; });
                });
            }
            // ── Remove duplicate replies (two-step) ──────────────────────────
            var dupPreviewBtn  = document.getElementById('brp-dup-preview');
            var dupConfirmBtn  = document.getElementById('brp-dup-confirm');
            var dupResult      = document.getElementById('brp-dup-result');

            if (dupPreviewBtn) {
                dupPreviewBtn.addEventListener('click', function() {
                    dupPreviewBtn.disabled = true;
                    if (dupResult) dupResult.style.display = 'none';
                    if (dupConfirmBtn) dupConfirmBtn.style.display = 'none';
                    var fd = new FormData();
                    fd.append('action', 'brp_remove_duplicate_replies');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'dryrun');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            dupPreviewBtn.disabled = false;
                            if (data.success && dupResult) {
                                var n = data.data.count;
                                if (n === 0) {
                                    dupResult.textContent = 'No orphan replies found — nothing to remove.';
                                    dupResult.style.color = '#00a32a';
                                } else {
                                    dupResult.textContent = n + ' orphan replies will be permanently deleted.';
                                    dupResult.style.color = '#996800';
                                    if (dupConfirmBtn) dupConfirmBtn.style.display = 'inline-block';
                                }
                                dupResult.style.display = 'block';
                            }
                        })
                        .catch(function() { dupPreviewBtn.disabled = false; });
                });
            }

            if (dupConfirmBtn) {
                dupConfirmBtn.addEventListener('click', function() {
                    if (!confirm('[' + brpAjax.env + '] Permanently delete orphan plugin replies?\n\nThis action cannot be undone.')) return;
                    dupConfirmBtn.disabled = true;
                    dupPreviewBtn.disabled = true;
                    if (dupResult) { dupResult.textContent = 'Deleting…'; dupResult.style.color = '#646970'; }
                    var fd = new FormData();
                    fd.append('action', 'brp_remove_duplicate_replies');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'execute');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            dupConfirmBtn.style.display = 'none';
                            dupPreviewBtn.disabled = false;
                            if (dupResult) {
                                if (data.success) {
                                    dupResult.textContent = 'Done: ' + data.data.deleted + ' orphan replies permanently deleted.';
                                    dupResult.style.color = '#00a32a';
                                } else {
                                    dupResult.textContent = 'Error: ' + (data.data || 'unknown');
                                    dupResult.style.color = '#d63638';
                                }
                            }
                        })
                        .catch(function() { dupConfirmBtn.disabled = false; dupPreviewBtn.disabled = false; });
                });
            }

            // ── Refresh ratings (two-step) ───────────────────────────────────
            var ratingsPreviewBtn  = document.getElementById('brp-ratings-preview');
            var ratingsConfirmBtn  = document.getElementById('brp-ratings-confirm');
            var ratingsResult      = document.getElementById('brp-ratings-result');

            if (ratingsPreviewBtn) {
                ratingsPreviewBtn.addEventListener('click', function() {
                    ratingsPreviewBtn.disabled = true;
                    if (ratingsResult) ratingsResult.style.display = 'none';
                    if (ratingsConfirmBtn) ratingsConfirmBtn.style.display = 'none';
                    var fd = new FormData();
                    fd.append('action', 'brp_refresh_ratings');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'dryrun');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            ratingsPreviewBtn.disabled = false;
                            if (data.success && ratingsResult) {
                                var d = data.data;
                                if (d.products === 0) {
                                    ratingsResult.textContent = 'No imported-review products found.';
                                    ratingsResult.style.color = '#646970';
                                } else {
                                    var lines = d.products + ' products total will be recalculated.';
                                    if (d.active > 0)   lines += '\n  • ' + d.active   + ' with active imported reviews';
                                    if (d.trashed > 0)  lines += '\n  • ' + d.trashed  + ' with all reviews trashed (stale rating data will be cleared)';
                                    ratingsResult.textContent = lines;
                                    ratingsResult.style.color = '#996800';
                                    if (ratingsConfirmBtn) ratingsConfirmBtn.style.display = 'inline-block';
                                }
                                ratingsResult.style.display = 'block';
                            }
                        })
                        .catch(function() { ratingsPreviewBtn.disabled = false; });
                });
            }

            if (ratingsConfirmBtn) {
                ratingsConfirmBtn.addEventListener('click', function() {
                    if (!confirm('[' + brpAjax.env + '] Recalculate WooCommerce ratings for all imported review products?')) return;
                    ratingsConfirmBtn.disabled = true;
                    ratingsPreviewBtn.disabled = true;
                    if (ratingsResult) { ratingsResult.textContent = 'Running…'; ratingsResult.style.color = '#646970'; }
                    var fd = new FormData();
                    fd.append('action', 'brp_refresh_ratings');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'execute');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            ratingsConfirmBtn.style.display = 'none';
                            ratingsPreviewBtn.disabled = false;
                            if (ratingsResult) {
                                if (data.success) {
                                    ratingsResult.textContent = 'Done: ' + data.data.products_recalculated + ' product ratings updated.';
                                    ratingsResult.style.color = '#00a32a';
                                } else {
                                    ratingsResult.textContent = 'Error: ' + (data.data || 'unknown');
                                    ratingsResult.style.color = '#d63638';
                                }
                            }
                        })
                        .catch(function() { ratingsConfirmBtn.disabled = false; ratingsPreviewBtn.disabled = false; });
                });
            }

            // ── Trash all imported reviews (two-step) ────────────────────────
            var trashAllPreviewBtn  = document.getElementById('brp-trashall-preview');
            var trashAllConfirmBtn  = document.getElementById('brp-trashall-confirm');
            var trashAllResult      = document.getElementById('brp-trashall-result');

            if (trashAllPreviewBtn) {
                trashAllPreviewBtn.addEventListener('click', function() {
                    trashAllPreviewBtn.disabled = true;
                    if (trashAllResult) trashAllResult.style.display = 'none';
                    if (trashAllConfirmBtn) trashAllConfirmBtn.style.display = 'none';
                    var fd = new FormData();
                    fd.append('action', 'brp_trash_all_imported');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'dryrun');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            trashAllPreviewBtn.disabled = false;
                            if (data.success && trashAllResult) {
                                var d = data.data;
                                if (d.root_reviews === 0) {
                                    trashAllResult.textContent = 'No active imported reviews found.';
                                    trashAllResult.style.color = '#646970';
                                } else {
                                    trashAllResult.textContent =
                                        d.root_reviews + ' root reviews + ' + d.replies + ' replies across ' + d.products + ' products will be moved to Trash.';
                                    trashAllResult.style.color = '#d63638';
                                    if (trashAllConfirmBtn) trashAllConfirmBtn.style.display = 'inline-block';
                                }
                                trashAllResult.style.display = 'block';
                            }
                        })
                        .catch(function() { trashAllPreviewBtn.disabled = false; });
                });
            }

            if (trashAllConfirmBtn) {
                trashAllConfirmBtn.addEventListener('click', function() {
                    if (!confirm('[' + brpAjax.env + '] Move ALL imported Basalam reviews and plugin replies to Trash?\n\nEnvironment: ' + brpAjax.env + '\nRecoverable from WP Admin → Comments → Trash.')) return;
                    trashAllConfirmBtn.disabled = true;
                    trashAllPreviewBtn.disabled = true;
                    if (trashAllResult) { trashAllResult.textContent = 'Running…'; trashAllResult.style.color = '#646970'; }
                    var fd = new FormData();
                    fd.append('action', 'brp_trash_all_imported');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'execute');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            trashAllConfirmBtn.style.display = 'none';
                            trashAllPreviewBtn.disabled = false;
                            if (trashAllResult) {
                                if (data.success) {
                                    var d = data.data;
                                    trashAllResult.textContent = 'Done: ' + d.trashed_reviews + ' reviews + ' + d.trashed_replies + ' replies trashed. ' + d.products_recalculated + ' product ratings updated.';
                                    trashAllResult.style.color = '#00a32a';
                                } else {
                                    trashAllResult.textContent = 'Error: ' + (data.data || 'unknown');
                                    trashAllResult.style.color = '#d63638';
                                }
                            }
                        })
                        .catch(function() { trashAllConfirmBtn.disabled = false; trashAllPreviewBtn.disabled = false; });
                });
            }
            // ── Clear Synced to WordPress (two-step) ─────────────────────────
            var resetSyncPreviewBtn = document.getElementById('brp-resetsync-preview');
            var resetSyncConfirmBtn = document.getElementById('brp-resetsync-confirm');
            var resetSyncResult     = document.getElementById('brp-resetsync-result');
            var resetSyncCount      = 0;

            if (resetSyncPreviewBtn) {
                resetSyncPreviewBtn.addEventListener('click', function() {
                    resetSyncPreviewBtn.disabled = true;
                    if (resetSyncResult) resetSyncResult.style.display = 'none';
                    if (resetSyncConfirmBtn) resetSyncConfirmBtn.style.display = 'none';
                    var fd = new FormData();
                    fd.append('action', 'brp_reset_sync');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'dryrun');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            resetSyncPreviewBtn.disabled = false;
                            if (data.success && resetSyncResult) {
                                resetSyncCount = data.data.synced;
                                if (resetSyncCount === 0) {
                                    resetSyncResult.textContent = 'Backend DB has no synced reviews — nothing to reset.';
                                    resetSyncResult.style.color = '#646970';
                                } else {
                                    resetSyncResult.textContent =
                                        resetSyncCount + ' reviews in the backend DB are marked as synced. Confirming will mark all of them as unsynced so they can be re-imported.';
                                    resetSyncResult.style.color = '#d63638';
                                    if (resetSyncConfirmBtn) resetSyncConfirmBtn.style.display = 'inline-block';
                                }
                                resetSyncResult.style.display = 'block';
                            } else if (resetSyncResult) {
                                resetSyncResult.textContent = 'Error: ' + (data.data || 'unknown');
                                resetSyncResult.style.color  = '#d63638';
                                resetSyncResult.style.display = 'block';
                            }
                        })
                        .catch(function() { resetSyncPreviewBtn.disabled = false; });
                });
            }

            if (resetSyncConfirmBtn) {
                resetSyncConfirmBtn.addEventListener('click', function() {
                    if (!confirm('[' + brpAjax.env + '] Clear backend sync state for all ' + resetSyncCount + ' reviews?\n\nExisting WordPress comments will NOT be duplicated — dedup is automatic.\nAfter confirming, click "Sync Missed Reviews" to re-import.')) return;
                    resetSyncConfirmBtn.disabled = true;
                    resetSyncPreviewBtn.disabled = true;
                    if (resetSyncResult) { resetSyncResult.textContent = 'Clearing…'; resetSyncResult.style.color = '#646970'; resetSyncResult.style.display = 'block'; }
                    var fd = new FormData();
                    fd.append('action', 'brp_reset_sync');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'execute');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            resetSyncConfirmBtn.style.display = 'none';
                            resetSyncPreviewBtn.disabled = false;
                            if (resetSyncResult) {
                                if (data.success) {
                                    var d = data.data;
                                    resetSyncResult.textContent = 'Done: ' + d.reset + ' reviews marked as unsynced. Click "Sync Missed Reviews" to re-import (batches of 50).';
                                    resetSyncResult.style.color = '#00a32a';
                                } else {
                                    resetSyncResult.textContent = 'Error: ' + (data.data || 'unknown');
                                    resetSyncResult.style.color  = '#d63638';
                                }
                            }
                        })
                        .catch(function() { resetSyncConfirmBtn.disabled = false; resetSyncPreviewBtn.disabled = false; });
                });
            }

            // ── Permanently Delete All Imported Reviews ──────────────────────
            var deleteAllPreviewBtn  = document.getElementById('brp-deleteall-preview');
            var deleteAllConfirmDiv  = document.getElementById('brp-deleteall-confirm');
            var deleteAllPreviewText = document.getElementById('brp-deleteall-preview-text');
            var deleteAllConfirmText = document.getElementById('brp-deleteall-confirm-text');
            var deleteAllConfirmBtn  = document.getElementById('brp-deleteall-confirm-btn');
            var deleteAllCancelBtn   = document.getElementById('brp-deleteall-cancel-btn');
            var deleteAllResult      = document.getElementById('brp-deleteall-result');
            var deleteAllCounts      = {};

            if (deleteAllPreviewBtn) {
                deleteAllPreviewBtn.addEventListener('click', function() {
                    deleteAllPreviewBtn.disabled = true;
                    if (deleteAllConfirmDiv) deleteAllConfirmDiv.style.display = 'none';
                    if (deleteAllResult) deleteAllResult.style.display = 'none';
                    if (deleteAllPreviewText) deleteAllPreviewText.textContent = 'Checking…';
                    var fd = new FormData();
                    fd.append('action', 'brp_delete_all_imported');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'dryrun');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            deleteAllPreviewBtn.disabled = false;
                            if (!data.success) {
                                if (deleteAllPreviewText) deleteAllPreviewText.textContent = 'Error: ' + (data.data || 'unknown');
                                return;
                            }
                            deleteAllCounts = data.data;
                            if (deleteAllPreviewText) {
                                deleteAllPreviewText.textContent = 'Found: ' + deleteAllCounts.root_reviews + ' root reviews + ' + deleteAllCounts.child_comments + ' child comments = ' + deleteAllCounts.total + ' total (ALL statuses including Trash).';
                            }
                            if (deleteAllCounts.total === 0) return;
                            if (deleteAllConfirmText) {
                                deleteAllConfirmText.textContent = 'Will permanently delete ' + deleteAllCounts.root_reviews + ' root reviews and ' + deleteAllCounts.child_comments + ' child/reply comments. ';
                            }
                            if (deleteAllConfirmDiv) deleteAllConfirmDiv.style.display = 'block';
                        })
                        .catch(function() { deleteAllPreviewBtn.disabled = false; if (deleteAllPreviewText) deleteAllPreviewText.textContent = ''; });
                });
            }

            if (deleteAllConfirmBtn) {
                deleteAllConfirmBtn.addEventListener('click', function() {
                    deleteAllConfirmBtn.disabled = true;
                    if (deleteAllResult) { deleteAllResult.textContent = 'Deleting…'; deleteAllResult.style.color = '#646970'; deleteAllResult.style.display = 'block'; }
                    var fd = new FormData();
                    fd.append('action', 'brp_delete_all_imported');
                    fd.append('nonce',  brpAjax.nonce);
                    fd.append('mode',   'execute');
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            deleteAllConfirmBtn.disabled = false;
                            if (deleteAllConfirmDiv) deleteAllConfirmDiv.style.display = 'none';
                            if (deleteAllPreviewText) deleteAllPreviewText.textContent = '';
                            if (deleteAllResult) {
                                if (data.success) {
                                    var d = data.data;
                                    deleteAllResult.textContent = 'Done: ' + d.deleted_reviews + ' root reviews + ' + d.deleted_replies + ' child comments permanently deleted. ' + d.products_recalculated + ' product ratings recalculated.';
                                    deleteAllResult.style.color = '#00a32a';
                                } else {
                                    deleteAllResult.textContent = 'Error: ' + (data.data || 'unknown');
                                    deleteAllResult.style.color = '#d63638';
                                }
                                deleteAllResult.style.display = 'block';
                            }
                        })
                        .catch(function() { deleteAllConfirmBtn.disabled = false; });
                });
            }

            if (deleteAllCancelBtn) {
                deleteAllCancelBtn.addEventListener('click', function() {
                    if (deleteAllConfirmDiv) deleteAllConfirmDiv.style.display = 'none';
                    if (deleteAllPreviewText) deleteAllPreviewText.textContent = '';
                });
            }
        })();
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function get_stats(): array {
        global $wpdb;
        $join = "FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->commentmeta} cm
                     ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                 WHERE c.comment_parent = 0
                   AND c.comment_approved NOT IN ('trash','spam')";

        $total    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT c.comment_ID) {$join}" ); // phpcs:ignore
        $approved = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT c.comment_ID) {$join} AND c.comment_approved = '1'" ); // phpcs:ignore
        return [
            'total'    => $total,
            'approved' => $approved,
            'pending'  => max( 0, $total - $approved ),
        ];
    }
}
