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
        add_action( 'wp_ajax_brp_trash_star_only',   [ self::class, 'ajax_trash_star_only' ] );
        add_action( 'wp_ajax_brp_migrate_emails',    [ self::class, 'ajax_migrate_emails' ] );
        add_action( 'wp_ajax_brp_check_connection',  [ self::class, 'ajax_check_connection' ] );
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
        $count = brp_unapprove_star_only_reviews();
        wp_send_json_success( [ 'updated' => $count ] );
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
            $count = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT c.comment_ID)
                 FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->commentmeta} cm
                     ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                 WHERE c.comment_type     = 'review'
                   AND c.comment_content  = ''
                   AND c.comment_approved NOT IN ('trash', 'spam')
                   AND c.comment_parent   = 0"
            );
            $products = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT c.comment_post_ID)
                 FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->commentmeta} cm
                     ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                 WHERE c.comment_type     = 'review'
                   AND c.comment_content  = ''
                   AND c.comment_approved NOT IN ('trash', 'spam')
                   AND c.comment_parent   = 0"
            );
            wp_send_json_success( [ 'reviews' => $count, 'products' => $products ] );
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
                     WHERE c.comment_type     = 'review'
                       AND c.comment_content  = ''
                       AND c.comment_approved NOT IN ('trash', 'spam')
                       AND c.comment_parent   = 0
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

        $resp = wp_remote_post( $endpoint . '/sync', [
            'timeout'   => 10,
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
            wp_send_json_error( "Backend returned HTTP {$code}" );
        }

        wp_send_json_success( $body );
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
                        <span id="brp-sync-status" style="font-size:12px; margin-left:8px; color:#646970;"></span>
                        <p class="brp-field-desc"><?php esc_html_e( 'Starts an incremental sync immediately on the backend server without waiting for the next scheduled run.', 'basalam-review-plugin' ); ?></p>
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
                                fixResult.textContent = data.data.updated + ' reviews set to pending.';
                                fixResult.style.display = 'block';
                            }
                        })
                        .catch(function() { fixBtn.disabled = false; });
                });
            }

            var syncBtn    = document.getElementById('brp-trigger-sync');
            var syncStatus = document.getElementById('brp-sync-status');
            if (syncBtn) {
                syncBtn.addEventListener('click', function() {
                    syncBtn.disabled = true;
                    if (syncStatus) syncStatus.textContent = 'Starting…';
                    var fd = new FormData();
                    fd.append('action', 'brp_trigger_sync');
                    fd.append('nonce',  brpAjax.nonce);
                    fetch(brpAjax.url, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            syncBtn.disabled = false;
                            if (syncStatus) {
                                if (data.success) {
                                    syncStatus.textContent = data.data.status === 'already_running'
                                        ? 'Sync already running.'
                                        : 'Sync started on server.';
                                } else {
                                    var msg = data.data || 'Unknown error';
                                    syncStatus.textContent = (typeof msg === 'string' && msg.indexOf('not configured') !== -1)
                                        ? 'Error: ' + msg + ' — see Debug Logs card above.'
                                        : 'Error: ' + msg;
                                }
                                setTimeout(function() { syncStatus.textContent = ''; }, 6000);
                            }
                        })
                        .catch(function() {
                            syncBtn.disabled = false;
                            if (syncStatus) syncStatus.textContent = 'Network error.';
                        });
                });
            }

            // ── Check backend connection ─────────────────────────────────────
            var connBtn    = document.getElementById('brp-check-conn');
            var connStatus = document.getElementById('brp-conn-status');
            var connResult = document.getElementById('brp-conn-result');

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
                                connResult.textContent =
                                    'Backend environment : ' + (d.env || '?') + '\n' +
                                    'Database            : ' + (d.db_path || '?') + '\n' +
                                    'WordPress endpoint  : ' + (d.wordpress || '(not set)');
                                connResult.style.display = 'block';
                                // Warn if backend env does not match plugin env label
                                if (d.env && d.env !== brpAjax.env) {
                                    connResult.textContent += '\n\n⚠ MISMATCH: Plugin is labelled "' + brpAjax.env + '" but backend reports "' + d.env + '".';
                                    connResult.style.color = '#d63638';
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
                                if (d.reviews === 0) {
                                    trashResult.textContent = 'No star-only imported reviews found.';
                                    trashResult.style.color = '#00a32a';
                                } else {
                                    trashResult.textContent = d.reviews + ' star-only imported reviews across ' + d.products + ' products will be moved to Trash.';
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
                 WHERE c.comment_parent = 0";

        $total    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT c.comment_ID) {$join}" );
        $approved = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT c.comment_ID) {$join} AND c.comment_approved = '1'" );
        return [
            'total'    => $total,
            'approved' => $approved,
            'pending'  => max( 0, $total - $approved ),
        ];
    }
}
