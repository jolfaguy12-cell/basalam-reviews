<?php
defined( 'ABSPATH' ) || exit;

class BRP_Settings {

    public static function defaults(): array {
        return [
            'api_key'               => '',
            'plugin_secret'         => '',
            'data_hub_endpoint'     => '',
            'data_hub_api_key'      => '',
            'customer_name_prefix'  => '',
            'customer_name_suffix'  => '',
            'admin_name_randomizer' => false,
            'admin_name_pool'       => "علی خلیلی\nپشتیبانی بهداشتیک\nتیم فروش",
            'attach_product_image'  => true,
            'auto_approve'          => true,
        ];
    }

    public static function init(): void {
        add_action( 'admin_menu',            [ self::class, 'add_menu' ] );
        add_action( 'admin_init',            [ self::class, 'register_fields' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'wp_ajax_brp_test_hub',  [ self::class, 'ajax_test_hub' ] );
    }

    public static function add_menu(): void {
        add_options_page(
            'Basalam Review',
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
        $defaults = self::defaults();
        return [
            'api_key'               => sanitize_text_field( $input['api_key'] ?? $defaults['api_key'] ),
            'plugin_secret'         => sanitize_text_field( $input['plugin_secret'] ?? $defaults['plugin_secret'] ),
            'data_hub_endpoint'     => esc_url_raw( trim( $input['data_hub_endpoint'] ?? '' ) ),
            'data_hub_api_key'      => sanitize_text_field( $input['data_hub_api_key'] ?? '' ),
            'customer_name_prefix'  => sanitize_text_field( $input['customer_name_prefix'] ?? '' ),
            'customer_name_suffix'  => sanitize_text_field( $input['customer_name_suffix'] ?? '' ),
            'admin_name_randomizer' => ! empty( $input['admin_name_randomizer'] ),
            'admin_name_pool'       => sanitize_textarea_field( $input['admin_name_pool'] ?? $defaults['admin_name_pool'] ),
            'attach_product_image'  => ! empty( $input['attach_product_image'] ),
            'auto_approve'          => ! empty( $input['auto_approve'] ),
        ];
    }

    public static function enqueue_assets( string $hook ): void {
        if ( $hook !== 'settings_page_basalam-review-plugin' ) {
            return;
        }
        wp_enqueue_style(
            'brp-admin',
            BRP_PLUGIN_URL . 'assets/admin.css',
            [],
            BRP_VERSION
        );
    }

    // ── AJAX: test Data Hub connection ────────────────────────────────────────

    public static function ajax_test_hub(): void {
        check_ajax_referer( 'brp_test_hub' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $endpoint = esc_url_raw( wp_unslash( $_POST['endpoint'] ?? '' ) );
        $api_key  = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

        if ( empty( $endpoint ) ) {
            wp_send_json_error( 'No endpoint configured.' );
        }

        $url      = rtrim( $endpoint, '/' ) . '/api/v1/health';
        $response = wp_remote_get( $url, [
            'headers'   => [ 'Authorization' => 'Bearer ' . $api_key ],
            'timeout'   => 8,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && ( $body['status'] ?? '' ) === 'ok' ) {
            wp_send_json_success( 'Connected' );
        } else {
            wp_send_json_error( 'HTTP ' . $code );
        }
    }

    // ── Page render ───────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s     = array_merge( self::defaults(), (array) get_option( BRP_OPTION_KEY, [] ) );
        $stats = self::get_stats();

        // Determine Data Hub status for the badge
        [ $hub_cls, $hub_txt ] = self::hub_status( $s );
        ?>
        <div class="wrap brp-page">

            <div class="brp-banner">
                <h1 class="brp-banner-title">
                    <span class="brp-banner-icon">⭐</span>
                    Basalam Review
                </h1>
                <span class="brp-version-badge">v<?php echo esc_html( BRP_VERSION ); ?></span>
            </div>

            <div class="brp-stats-row">
                <div class="brp-stat-box">
                    <span class="stat-num"><?php echo esc_html( $stats['total'] ); ?></span>
                    <span class="stat-lbl"><?php esc_html_e( 'Total Reviews', 'basalam-review-plugin' ); ?></span>
                </div>
                <div class="brp-stat-box is-approved">
                    <span class="stat-num"><?php echo esc_html( $stats['approved'] ); ?></span>
                    <span class="stat-lbl"><?php esc_html_e( 'Approved', 'basalam-review-plugin' ); ?></span>
                </div>
                <div class="brp-stat-box is-pending">
                    <span class="stat-num"><?php echo esc_html( $stats['pending'] ); ?></span>
                    <span class="stat-lbl"><?php esc_html_e( 'Pending', 'basalam-review-plugin' ); ?></span>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'brp_settings_group' ); ?>

                <?php /* ── Connection Card ─────────────────────────────────── */ ?>
                <div class="brp-card">
                    <div class="brp-card-header">
                        <span class="dashicons dashicons-lock"></span>
                        <h2 class="brp-card-title"><?php esc_html_e( 'Connection Credentials', 'basalam-review-plugin' ); ?></h2>
                    </div>
                    <div class="brp-card-body">

                        <div class="brp-row">
                            <div class="brp-row-label"><?php esc_html_e( 'API Key', 'basalam-review-plugin' ); ?></div>
                            <div class="brp-row-control">
                                <div class="brp-secret-wrap">
                                    <input type="password" id="brp-api-key"
                                           name="<?php echo BRP_OPTION_KEY; ?>[api_key]"
                                           value="<?php echo esc_attr( $s['api_key'] ); ?>"
                                           class="regular-text" autocomplete="off" />
                                    <button type="button" class="brp-icon-btn brp-eye-btn" data-target="brp-api-key" title="Show / Hide">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                    <button type="button" class="brp-icon-btn brp-copy-btn" data-target="brp-api-key" title="Copy">
                                        <span class="dashicons dashicons-admin-page"></span>
                                    </button>
                                    <button type="button" class="button brp-gen-btn" data-field="brp-api-key">
                                        <?php esc_html_e( '↻ Generate', 'basalam-review-plugin' ); ?>
                                    </button>
                                </div>
                                <p class="brp-row-desc"><?php esc_html_e( 'Copy into WORDPRESS_API_KEY in backend .env', 'basalam-review-plugin' ); ?></p>
                            </div>
                        </div>

                        <div class="brp-row">
                            <div class="brp-row-label"><?php esc_html_e( 'Plugin Secret', 'basalam-review-plugin' ); ?></div>
                            <div class="brp-row-control">
                                <div class="brp-secret-wrap">
                                    <input type="password" id="brp-plugin-secret"
                                           name="<?php echo BRP_OPTION_KEY; ?>[plugin_secret]"
                                           value="<?php echo esc_attr( $s['plugin_secret'] ); ?>"
                                           class="regular-text" autocomplete="off" />
                                    <button type="button" class="brp-icon-btn brp-eye-btn" data-target="brp-plugin-secret" title="Show / Hide">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                    <button type="button" class="brp-icon-btn brp-copy-btn" data-target="brp-plugin-secret" title="Copy">
                                        <span class="dashicons dashicons-admin-page"></span>
                                    </button>
                                    <button type="button" class="button brp-gen-btn" data-field="brp-plugin-secret">
                                        <?php esc_html_e( '↻ Generate', 'basalam-review-plugin' ); ?>
                                    </button>
                                </div>
                                <p class="brp-row-desc"><?php esc_html_e( 'Copy into WORDPRESS_PLUGIN_SECRET in backend .env', 'basalam-review-plugin' ); ?></p>
                            </div>
                        </div>

                        <div class="brp-row brp-regen-row">
                            <div class="brp-row-label"></div>
                            <div class="brp-row-control">
                                <button type="button" id="brp-regen-both" class="button button-secondary">
                                    <?php esc_html_e( '↻ Regenerate Both Keys', 'basalam-review-plugin' ); ?>
                                </button>
                            </div>
                        </div>

                    </div>
                </div>

                <?php /* ── Data Hub Card ─────────────────────────────────── */ ?>
                <div class="brp-card">
                    <div class="brp-card-header">
                        <span class="dashicons dashicons-cloud"></span>
                        <h2 class="brp-card-title"><?php esc_html_e( 'Data Hub', 'basalam-review-plugin' ); ?></h2>
                        <span id="brp-hub-badge" class="brp-hub-badge <?php echo esc_attr( $hub_cls ); ?>">
                            <span class="dot"></span><?php echo esc_html( $hub_txt ); ?>
                        </span>
                    </div>
                    <div class="brp-card-body">

                        <div class="brp-row">
                            <div class="brp-row-label"><?php esc_html_e( 'Endpoint URL', 'basalam-review-plugin' ); ?></div>
                            <div class="brp-row-control">
                                <input type="url" id="brp-hub-endpoint"
                                       name="<?php echo BRP_OPTION_KEY; ?>[data_hub_endpoint]"
                                       value="<?php echo esc_attr( $s['data_hub_endpoint'] ); ?>"
                                       class="regular-text"
                                       placeholder="https://your-datahub.example.com" />
                                <p class="brp-row-desc"><?php esc_html_e( 'Base URL of the Data Hub service (Basalam → WooCommerce product ID matching)', 'basalam-review-plugin' ); ?></p>
                            </div>
                        </div>

                        <div class="brp-row">
                            <div class="brp-row-label"><?php esc_html_e( 'API Key', 'basalam-review-plugin' ); ?></div>
                            <div class="brp-row-control">
                                <div class="brp-secret-wrap">
                                    <input type="password" id="brp-hub-key"
                                           name="<?php echo BRP_OPTION_KEY; ?>[data_hub_api_key]"
                                           value="<?php echo esc_attr( $s['data_hub_api_key'] ); ?>"
                                           class="regular-text" autocomplete="off" />
                                    <button type="button" class="brp-icon-btn brp-eye-btn" data-target="brp-hub-key" title="Show / Hide">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                    <button type="button" class="brp-icon-btn brp-copy-btn" data-target="brp-hub-key" title="Copy">
                                        <span class="dashicons dashicons-admin-page"></span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="brp-row">
                            <div class="brp-row-label"></div>
                            <div class="brp-row-control">
                                <div class="brp-test-wrap">
                                    <button type="button" id="brp-test-hub" class="button button-secondary">
                                        <?php esc_html_e( 'Test Connection', 'basalam-review-plugin' ); ?>
                                    </button>
                                    <span id="brp-test-msg"></span>
                                </div>
                                <p class="brp-row-desc"><?php esc_html_e( 'The backend service reads these credentials automatically via the plugin REST API.', 'basalam-review-plugin' ); ?></p>
                            </div>
                        </div>

                    </div>
                </div>

                <?php /* ── Review Import Card ───────────────────────────── */ ?>
                <div class="brp-card">
                    <div class="brp-card-header">
                        <span class="dashicons dashicons-star-filled"></span>
                        <h2 class="brp-card-title"><?php esc_html_e( 'Review Import', 'basalam-review-plugin' ); ?></h2>
                    </div>
                    <div class="brp-card-body">

                        <div class="brp-row">
                            <div class="brp-row-label"><?php esc_html_e( 'Name Prefix', 'basalam-review-plugin' ); ?></div>
                            <div class="brp-row-control">
                                <input type="text"
                                       name="<?php echo BRP_OPTION_KEY; ?>[customer_name_prefix]"
                                       value="<?php echo esc_attr( $s['customer_name_prefix'] ); ?>"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e( 'e.g. خریدار', 'basalam-review-plugin' ); ?>" />
                                <p class="brp-row-desc"><?php esc_html_e( 'Prepended to each reviewer\'s display name', 'basalam-review-plugin' ); ?></p>
                            </div>
                        </div>

                        <div class="brp-row">
                            <div class="brp-row-label"><?php esc_html_e( 'Name Suffix', 'basalam-review-plugin' ); ?></div>
                            <div class="brp-row-control">
                                <input type="text"
                                       name="<?php echo BRP_OPTION_KEY; ?>[customer_name_suffix]"
                                       value="<?php echo esc_attr( $s['customer_name_suffix'] ); ?>"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e( 'e.g. عزیز', 'basalam-review-plugin' ); ?>" />
                                <p class="brp-row-desc"><?php esc_html_e( 'Appended to each reviewer\'s display name', 'basalam-review-plugin' ); ?></p>
                            </div>
                        </div>

                        <div class="brp-row">
                            <div class="brp-row-label"><?php esc_html_e( 'Auto-approve', 'basalam-review-plugin' ); ?></div>
                            <div class="brp-row-control">
                                <div class="brp-toggle-wrap">
                                    <label class="brp-switch">
                                        <input type="checkbox" name="<?php echo BRP_OPTION_KEY; ?>[auto_approve]"
                                               value="1" <?php checked( $s['auto_approve'] ); ?> />
                                        <span class="brp-switch-track"></span>
                                    </label>
                                    <span class="brp-toggle-lbl"><?php esc_html_e( 'Approve imported reviews immediately (skip moderation queue)', 'basalam-review-plugin' ); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="brp-row">
                            <div class="brp-row-label"><?php esc_html_e( 'Attach Product Image', 'basalam-review-plugin' ); ?></div>
                            <div class="brp-row-control">
                                <div class="brp-toggle-wrap">
                                    <label class="brp-switch">
                                        <input type="checkbox" name="<?php echo BRP_OPTION_KEY; ?>[attach_product_image]"
                                               value="1" <?php checked( $s['attach_product_image'] ); ?> />
                                        <span class="brp-switch-track"></span>
                                    </label>
                                    <span class="brp-toggle-lbl"><?php esc_html_e( 'Attach the WooCommerce product thumbnail to each imported review', 'basalam-review-plugin' ); ?></span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <?php /* ── Seller Replies Card ─────────────────────────── */ ?>
                <div class="brp-card">
                    <div class="brp-card-header">
                        <span class="dashicons dashicons-admin-comments"></span>
                        <h2 class="brp-card-title"><?php esc_html_e( 'Seller Replies', 'basalam-review-plugin' ); ?></h2>
                    </div>
                    <div class="brp-card-body">

                        <div class="brp-row">
                            <div class="brp-row-label"><?php esc_html_e( 'Randomize Name', 'basalam-review-plugin' ); ?></div>
                            <div class="brp-row-control">
                                <div class="brp-toggle-wrap">
                                    <label class="brp-switch">
                                        <input type="checkbox" name="<?php echo BRP_OPTION_KEY; ?>[admin_name_randomizer]"
                                               value="1" <?php checked( $s['admin_name_randomizer'] ); ?> />
                                        <span class="brp-switch-track"></span>
                                    </label>
                                    <span class="brp-toggle-lbl"><?php esc_html_e( 'Pick a random name from the pool below for each seller reply', 'basalam-review-plugin' ); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="brp-row">
                            <div class="brp-row-label"><?php esc_html_e( 'Name Pool', 'basalam-review-plugin' ); ?></div>
                            <div class="brp-row-control">
                                <textarea name="<?php echo BRP_OPTION_KEY; ?>[admin_name_pool]"
                                          rows="4" class="large-text"><?php echo esc_textarea( $s['admin_name_pool'] ); ?></textarea>
                                <p class="brp-row-desc"><?php esc_html_e( 'One name per line. Used when the randomizer is enabled.', 'basalam-review-plugin' ); ?></p>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="brp-actions">
                    <?php submit_button( __( 'Save Settings', 'basalam-review-plugin' ), 'primary', 'submit', false ); ?>
                </div>

            </form>
        </div>

        <script>
        (function () {
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'brp_test_hub' ) ); ?>;

            function randHex(bytes) {
                var arr = new Uint8Array(bytes);
                window.crypto.getRandomValues(arr);
                return Array.from(arr).map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
            }

            // Show / hide
            document.querySelectorAll('.brp-eye-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var inp = document.getElementById(this.dataset.target);
                    if (!inp) return;
                    var icon = this.querySelector('.dashicons');
                    if (inp.type === 'password') {
                        inp.type = 'text';
                        icon.classList.replace('dashicons-visibility', 'dashicons-hidden');
                    } else {
                        inp.type = 'password';
                        icon.classList.replace('dashicons-hidden', 'dashicons-visibility');
                    }
                });
            });

            // Copy to clipboard
            document.querySelectorAll('.brp-copy-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var inp = document.getElementById(this.dataset.target);
                    if (!inp || !inp.value) return;
                    var self = this;
                    navigator.clipboard.writeText(inp.value).then(function() {
                        self.classList.add('copied');
                        setTimeout(function(){ self.classList.remove('copied'); }, 1500);
                    });
                });
            });

            // Generate single
            document.querySelectorAll('.brp-gen-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var inp = document.getElementById(this.dataset.field);
                    if (inp) inp.value = randHex(32);
                });
            });

            // Regenerate both
            var regenBoth = document.getElementById('brp-regen-both');
            if (regenBoth) {
                regenBoth.addEventListener('click', function() {
                    var k = document.getElementById('brp-api-key');
                    var s = document.getElementById('brp-plugin-secret');
                    if (k) k.value = randHex(32);
                    if (s) s.value = randHex(32);
                });
            }

            // Test Data Hub connection
            var testBtn = document.getElementById('brp-test-hub');
            if (testBtn) {
                testBtn.addEventListener('click', function() {
                    var msg      = document.getElementById('brp-test-msg');
                    var badge    = document.getElementById('brp-hub-badge');
                    var endpoint = document.getElementById('brp-hub-endpoint');
                    var key      = document.getElementById('brp-hub-key');

                    msg.textContent = '<?php echo esc_js( __( 'Testing…', 'basalam-review-plugin' ) ); ?>';
                    msg.className   = '';
                    testBtn.disabled = true;

                    var fd = new FormData();
                    fd.append('action',   'brp_test_hub');
                    fd.append('nonce',    nonce);
                    fd.append('endpoint', endpoint ? endpoint.value : '');
                    fd.append('api_key',  key ? key.value : '');

                    fetch(ajaxUrl, { method: 'POST', body: fd })
                        .then(function(r){ return r.json(); })
                        .then(function(res) {
                            if (res.success) {
                                msg.textContent = '✓ <?php echo esc_js( __( 'Connected', 'basalam-review-plugin' ) ); ?>';
                                msg.className   = 'is-ok';
                                badge.className = 'brp-hub-badge is-ok';
                                badge.innerHTML = '<span class="dot"></span><?php echo esc_js( __( 'Connected', 'basalam-review-plugin' ) ); ?>';
                            } else {
                                msg.textContent = '✗ ' + (res.data || '<?php echo esc_js( __( 'Failed', 'basalam-review-plugin' ) ); ?>');
                                msg.className   = 'is-err';
                                badge.className = 'brp-hub-badge is-err';
                                badge.innerHTML = '<span class="dot"></span><?php echo esc_js( __( 'Error', 'basalam-review-plugin' ) ); ?>';
                            }
                        })
                        .catch(function() {
                            msg.textContent = '✗ <?php echo esc_js( __( 'Request failed', 'basalam-review-plugin' ) ); ?>';
                            msg.className   = 'is-err';
                        })
                        .finally(function(){ testBtn.disabled = false; });
                });
            }
        })();
        </script>
        <?php
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function hub_status( array $s ): array {
        if ( empty( $s['data_hub_endpoint'] ) ) {
            return [ 'is-none', __( 'Not configured', 'basalam-review-plugin' ) ];
        }
        $url      = rtrim( $s['data_hub_endpoint'], '/' ) . '/api/v1/health';
        $response = wp_remote_get( $url, [
            'headers'   => [ 'Authorization' => 'Bearer ' . ( $s['data_hub_api_key'] ?? '' ) ],
            'timeout'   => 4,
            'sslverify' => true,
        ] );
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            return [ 'is-ok', __( 'Connected', 'basalam-review-plugin' ) ];
        }
        return [ 'is-err', __( 'Connection failed', 'basalam-review-plugin' ) ];
    }

    private static function get_stats(): array {
        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT c.comment_ID)
             FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_id
             WHERE cm.meta_key = 'basalam_review_id'"
        );
        $approved = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT c.comment_ID)
             FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_id
             WHERE cm.meta_key = 'basalam_review_id' AND c.comment_approved = '1'"
        );
        return [
            'total'    => $total,
            'approved' => $approved,
            'pending'  => $total - $approved,
        ];
    }
}
