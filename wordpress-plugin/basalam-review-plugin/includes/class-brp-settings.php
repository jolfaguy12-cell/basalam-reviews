<?php
defined( 'ABSPATH' ) || exit;

class BRP_Settings {

    public static function defaults(): array {
        return [
            'api_key'               => '',
            'plugin_secret'         => '',
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
    }

    public static function add_menu(): void {
        add_options_page(
            __( 'Basalam Review Connector', 'basalam-review-plugin' ),
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
            'api_key'               => sanitize_text_field( $input['api_key']              ?? $defaults['api_key'] ),
            'plugin_secret'         => sanitize_text_field( $input['plugin_secret']        ?? $defaults['plugin_secret'] ),
            'customer_name_prefix'  => sanitize_text_field( $input['customer_name_prefix'] ?? '' ),
            'customer_name_suffix'  => sanitize_text_field( $input['customer_name_suffix'] ?? '' ),
            'admin_name_randomizer' => ! empty( $input['admin_name_randomizer'] ),
            'admin_name_pool'       => sanitize_textarea_field( $input['admin_name_pool']  ?? $defaults['admin_name_pool'] ),
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

    // ── Page render ───────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s     = array_merge( self::defaults(), (array) get_option( BRP_OPTION_KEY, [] ) );
        $stats = self::get_stats();

        $keys_ok = ! empty( $s['api_key'] ) && ! empty( $s['plugin_secret'] );
        ?>
        <div class="wrap brp-page">

            <!-- ── Banner ──────────────────────────────────────────── -->
            <div class="brp-banner">
                <h1 class="brp-banner-title">
                    <span class="brp-banner-icon">⭐</span>
                    <?php esc_html_e( 'Basalam Review Connector', 'basalam-review-plugin' ); ?>
                </h1>
                <span class="brp-version-badge">v<?php echo esc_html( BRP_VERSION ); ?></span>
            </div>

            <!-- ── Stats ───────────────────────────────────────────── -->
            <div class="brp-stats-row">
                <div class="brp-stat-box">
                    <span class="stat-num"><?php echo esc_html( $stats['total'] ); ?></span>
                    <span class="stat-lbl"><?php esc_html_e( 'Total', 'basalam-review-plugin' ); ?></span>
                </div>
                <div class="brp-stat-box is-approved">
                    <span class="stat-num"><?php echo esc_html( $stats['approved'] ); ?></span>
                    <span class="stat-lbl"><?php esc_html_e( 'Approved', 'basalam-review-plugin' ); ?></span>
                </div>
                <div class="brp-stat-box is-pending">
                    <span class="stat-num"><?php echo esc_html( $stats['pending'] ); ?></span>
                    <span class="stat-lbl"><?php esc_html_e( 'Pending', 'basalam-review-plugin' ); ?></span>
                </div>
                <div class="brp-stat-box is-sync">
                    <span class="stat-num stat-num--small"><?php echo esc_html( $stats['last_sync'] ); ?></span>
                    <span class="stat-lbl"><?php esc_html_e( 'Last Import', 'basalam-review-plugin' ); ?></span>
                </div>
            </div>

            <!-- ── Setup notice if keys not configured ──────────────── -->
            <?php if ( ! $keys_ok ) : ?>
            <div class="notice notice-warning brp-notice">
                <p>
                    <strong><?php esc_html_e( 'Setup required:', 'basalam-review-plugin' ); ?></strong>
                    <?php esc_html_e( 'Generate an API Key and Plugin Secret below, then copy them into the backend .env file on Server 2.', 'basalam-review-plugin' ); ?>
                </p>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'brp_settings_group' ); ?>

                <!-- ── Security Credentials ──────────────────────────── -->
                <div class="brp-card">
                    <div class="brp-card-header">
                        <span class="dashicons dashicons-lock"></span>
                        <h2 class="brp-card-title"><?php esc_html_e( 'Security Credentials', 'basalam-review-plugin' ); ?></h2>
                        <span class="brp-card-meta">
                            <?php if ( $keys_ok ) : ?>
                                <span class="brp-pill brp-pill--ok"><?php esc_html_e( 'Configured', 'basalam-review-plugin' ); ?></span>
                            <?php else : ?>
                                <span class="brp-pill brp-pill--warn"><?php esc_html_e( 'Not configured', 'basalam-review-plugin' ); ?></span>
                            <?php endif; ?>
                        </span>
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
                                    <button type="button" class="brp-icon-btn brp-eye-btn" data-target="brp-api-key" title="<?php esc_attr_e( 'Show / Hide', 'basalam-review-plugin' ); ?>">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                    <button type="button" class="brp-icon-btn brp-copy-btn" data-target="brp-api-key" title="<?php esc_attr_e( 'Copy', 'basalam-review-plugin' ); ?>">
                                        <span class="dashicons dashicons-admin-page"></span>
                                    </button>
                                    <button type="button" class="button brp-gen-btn" data-field="brp-api-key">
                                        <?php esc_html_e( '↻ Generate', 'basalam-review-plugin' ); ?>
                                    </button>
                                </div>
                                <p class="brp-row-desc"><?php esc_html_e( 'Set as WORDPRESS_API_KEY in the Server 2 .env file.', 'basalam-review-plugin' ); ?></p>
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
                                    <button type="button" class="brp-icon-btn brp-eye-btn" data-target="brp-plugin-secret" title="<?php esc_attr_e( 'Show / Hide', 'basalam-review-plugin' ); ?>">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                    <button type="button" class="brp-icon-btn brp-copy-btn" data-target="brp-plugin-secret" title="<?php esc_attr_e( 'Copy', 'basalam-review-plugin' ); ?>">
                                        <span class="dashicons dashicons-admin-page"></span>
                                    </button>
                                    <button type="button" class="button brp-gen-btn" data-field="brp-plugin-secret">
                                        <?php esc_html_e( '↻ Generate', 'basalam-review-plugin' ); ?>
                                    </button>
                                </div>
                                <p class="brp-row-desc"><?php esc_html_e( 'Set as WORDPRESS_PLUGIN_SECRET in the Server 2 .env file. Used for HMAC-SHA256 request signing.', 'basalam-review-plugin' ); ?></p>
                            </div>
                        </div>

                        <div class="brp-row brp-regen-row">
                            <div class="brp-row-label"></div>
                            <div class="brp-row-control">
                                <button type="button" id="brp-regen-both" class="button">
                                    <?php esc_html_e( '↻ Regenerate Both Keys', 'basalam-review-plugin' ); ?>
                                </button>
                                <p class="brp-row-desc"><?php esc_html_e( 'After regenerating, save this page and update the Server 2 .env file before the next sync.', 'basalam-review-plugin' ); ?></p>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Review Import Settings ─────────────────────────── -->
                <div class="brp-card">
                    <div class="brp-card-header">
                        <span class="dashicons dashicons-star-filled"></span>
                        <h2 class="brp-card-title"><?php esc_html_e( 'Review Display', 'basalam-review-plugin' ); ?></h2>
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
                                <p class="brp-row-desc"><?php esc_html_e( 'Prepended to the reviewer\'s display name.', 'basalam-review-plugin' ); ?></p>
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
                                <p class="brp-row-desc"><?php esc_html_e( 'Appended to the reviewer\'s display name.', 'basalam-review-plugin' ); ?></p>
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
                                    <span class="brp-toggle-lbl"><?php esc_html_e( 'Approve imported reviews immediately, skip the moderation queue.', 'basalam-review-plugin' ); ?></span>
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
                                    <span class="brp-toggle-lbl"><?php esc_html_e( 'Attach the WooCommerce product thumbnail to each imported review.', 'basalam-review-plugin' ); ?></span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Seller Reply Settings ──────────────────────────── -->
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
                                    <span class="brp-toggle-lbl"><?php esc_html_e( 'Pick a random name from the pool below for each seller reply.', 'basalam-review-plugin' ); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="brp-row">
                            <div class="brp-row-label"><?php esc_html_e( 'Name Pool', 'basalam-review-plugin' ); ?></div>
                            <div class="brp-row-control">
                                <textarea name="<?php echo BRP_OPTION_KEY; ?>[admin_name_pool]"
                                          rows="4" class="large-text"><?php echo esc_textarea( $s['admin_name_pool'] ); ?></textarea>
                                <p class="brp-row-desc"><?php esc_html_e( 'One name per line. Used when the randomizer above is enabled.', 'basalam-review-plugin' ); ?></p>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="brp-actions">
                    <?php submit_button( __( 'Save Settings', 'basalam-review-plugin' ), 'primary', 'submit', false ); ?>
                </div>

            </form>

            <!-- ── Plugin Status (outside form, no heavy queries) ───── -->
            <div class="brp-card brp-status-card">
                <div class="brp-card-header">
                    <span class="dashicons dashicons-info"></span>
                    <h2 class="brp-card-title"><?php esc_html_e( 'Plugin Information', 'basalam-review-plugin' ); ?></h2>
                </div>
                <div class="brp-card-body">
                    <table class="widefat brp-info-table">
                        <tbody>
                            <tr>
                                <td><?php esc_html_e( 'Plugin Version', 'basalam-review-plugin' ); ?></td>
                                <td><code><?php echo esc_html( BRP_VERSION ); ?></code></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'WooCommerce', 'basalam-review-plugin' ); ?></td>
                                <td>
                                <?php if ( class_exists( 'WooCommerce' ) ) : ?>
                                    <span class="brp-pill brp-pill--ok">
                                        <?php echo esc_html( sprintf( __( 'Active v%s', 'basalam-review-plugin' ), WC()->version ) ); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="brp-pill brp-pill--err"><?php esc_html_e( 'Not active', 'basalam-review-plugin' ); ?></span>
                                <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Authentication', 'basalam-review-plugin' ); ?></td>
                                <td>
                                    <?php if ( $keys_ok ) : ?>
                                    <span class="brp-pill brp-pill--ok"><?php esc_html_e( 'API key + HMAC-SHA256 active', 'basalam-review-plugin' ); ?></span>
                                    <?php else : ?>
                                    <span class="brp-pill brp-pill--warn"><?php esc_html_e( 'Keys not set — plugin will reject all requests', 'basalam-review-plugin' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Health endpoint', 'basalam-review-plugin' ); ?></td>
                                <td><code><?php echo esc_html( home_url( '/wp-json/basalam-review/v1/health' ) ); ?></code></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Receive endpoint', 'basalam-review-plugin' ); ?></td>
                                <td><code><?php echo esc_html( home_url( '/wp-json/basalam-review/v1/receive' ) ); ?></code></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- .brp-page -->

        <script>
        (function () {
            function randHex( bytes ) {
                var arr = new Uint8Array( bytes );
                window.crypto.getRandomValues( arr );
                return Array.from( arr ).map( function ( b ) { return b.toString( 16 ).padStart( 2, '0' ); } ).join( '' );
            }

            // Show / hide password fields
            document.querySelectorAll( '.brp-eye-btn' ).forEach( function ( btn ) {
                btn.addEventListener( 'click', function () {
                    var inp  = document.getElementById( this.dataset.target );
                    var icon = this.querySelector( '.dashicons' );
                    if ( ! inp ) return;
                    if ( inp.type === 'password' ) {
                        inp.type = 'text';
                        icon.classList.replace( 'dashicons-visibility', 'dashicons-hidden' );
                    } else {
                        inp.type = 'password';
                        icon.classList.replace( 'dashicons-hidden', 'dashicons-visibility' );
                    }
                } );
            } );

            // Copy to clipboard
            document.querySelectorAll( '.brp-copy-btn' ).forEach( function ( btn ) {
                btn.addEventListener( 'click', function () {
                    var inp = document.getElementById( this.dataset.target );
                    if ( ! inp || ! inp.value ) return;
                    var self = this;
                    navigator.clipboard.writeText( inp.value ).then( function () {
                        self.classList.add( 'copied' );
                        setTimeout( function () { self.classList.remove( 'copied' ); }, 1500 );
                    } );
                } );
            } );

            // Generate single key
            document.querySelectorAll( '.brp-gen-btn' ).forEach( function ( btn ) {
                btn.addEventListener( 'click', function () {
                    var inp = document.getElementById( this.dataset.field );
                    if ( inp ) inp.value = randHex( 32 );
                } );
            } );

            // Regenerate both keys
            var regenBoth = document.getElementById( 'brp-regen-both' );
            if ( regenBoth ) {
                regenBoth.addEventListener( 'click', function () {
                    var k = document.getElementById( 'brp-api-key' );
                    var s = document.getElementById( 'brp-plugin-secret' );
                    if ( k ) k.value = randHex( 32 );
                    if ( s ) s.value = randHex( 32 );
                } );
            }
        } )();
        </script>
        <?php
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function get_stats(): array {
        global $wpdb;

        $base = "FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->commentmeta} cm
                     ON c.comment_ID = cm.comment_id AND cm.meta_key = 'basalam_review_id'
                 WHERE c.comment_parent = 0";

        $total    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT c.comment_ID) {$base}" );
        $approved = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT c.comment_ID) {$base} AND c.comment_approved = '1'" );

        $last = $wpdb->get_var( "SELECT MAX(c.comment_date) {$base}" );
        if ( $last ) {
            $dt       = new DateTime( $last );
            $last_str = $dt->format( 'M j' );
        } else {
            $last_str = '—';
        }

        return [
            'total'     => $total,
            'approved'  => $approved,
            'pending'   => $total - $approved,
            'last_sync' => $last_str,
        ];
    }
}
