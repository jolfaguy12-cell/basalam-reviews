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
        $d = self::defaults();
        return [
            'api_key'               => sanitize_text_field( $input['api_key']               ?? $d['api_key'] ),
            'plugin_secret'         => sanitize_text_field( $input['plugin_secret']         ?? $d['plugin_secret'] ),
            'customer_name_prefix'  => sanitize_text_field( $input['customer_name_prefix']  ?? '' ),
            'customer_name_suffix'  => sanitize_text_field( $input['customer_name_suffix']  ?? '' ),
            'admin_name_randomizer' => ! empty( $input['admin_name_randomizer'] ),
            'admin_name_pool'       => sanitize_textarea_field( $input['admin_name_pool']   ?? $d['admin_name_pool'] ),
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

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s     = array_merge( self::defaults(), (array) get_option( BRP_OPTION_KEY, [] ) );
        $stats = self::get_stats();
        $ok    = ! empty( $s['api_key'] ) && ! empty( $s['plugin_secret'] );
        ?>
        <div class="wrap">

            <h1>
                <?php esc_html_e( 'Basalam Review Connector', 'basalam-review-plugin' ); ?>
                <span class="title-count theme-count">v<?php echo esc_html( BRP_VERSION ); ?></span>
                <?php if ( $ok ) : ?>
                    <span class="brp-badge brp-badge-ok"><?php esc_html_e( 'Active', 'basalam-review-plugin' ); ?></span>
                <?php else : ?>
                    <span class="brp-badge brp-badge-warn"><?php esc_html_e( 'Setup required', 'basalam-review-plugin' ); ?></span>
                <?php endif; ?>
            </h1>

            <?php if ( $stats['total'] > 0 ) : ?>
            <div class="brp-stats-bar">
                <div class="brp-stat">
                    <strong><?php echo esc_html( $stats['total'] ); ?></strong>
                    <span><?php esc_html_e( 'Total', 'basalam-review-plugin' ); ?></span>
                </div>
                <div class="brp-stat is-ok">
                    <strong><?php echo esc_html( $stats['approved'] ); ?></strong>
                    <span><?php esc_html_e( 'Approved', 'basalam-review-plugin' ); ?></span>
                </div>
                <div class="brp-stat is-warn">
                    <strong><?php echo esc_html( $stats['pending'] ); ?></strong>
                    <span><?php esc_html_e( 'Pending', 'basalam-review-plugin' ); ?></span>
                </div>
                <div class="brp-stat">
                    <strong style="font-size:16px;padding-top:5px"><?php echo esc_html( $stats['last_sync'] ); ?></strong>
                    <span><?php esc_html_e( 'Last Import', 'basalam-review-plugin' ); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( ! $ok ) : ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e( 'Generate an API Key and Plugin Secret below, then copy both values into the Server 2 .env file.', 'basalam-review-plugin' ); ?></p>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'brp_settings_group' ); ?>

                <!-- ── Security ─────────────────────────────────────────── -->
                <h2><?php esc_html_e( 'Security Credentials', 'basalam-review-plugin' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>

                        <tr>
                            <th scope="row">
                                <label for="brp-api-key"><?php esc_html_e( 'API Key', 'basalam-review-plugin' ); ?></label>
                            </th>
                            <td>
                                <div class="brp-key-row">
                                    <input type="password" id="brp-api-key"
                                           name="<?php echo BRP_OPTION_KEY; ?>[api_key]"
                                           value="<?php echo esc_attr( $s['api_key'] ); ?>"
                                           autocomplete="off" />
                                    <button type="button" class="brp-icon-btn" id="brp-eye-key" title="<?php esc_attr_e( 'Show/Hide', 'basalam-review-plugin' ); ?>" data-target="brp-api-key">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                    <button type="button" class="brp-icon-btn" title="<?php esc_attr_e( 'Copy', 'basalam-review-plugin' ); ?>" data-copy="brp-api-key">
                                        <span class="dashicons dashicons-admin-page"></span>
                                    </button>
                                    <button type="button" class="button" data-gen="brp-api-key">
                                        <?php esc_html_e( '↻ Generate', 'basalam-review-plugin' ); ?>
                                    </button>
                                </div>
                                <p class="description"><?php esc_html_e( 'Set as WORDPRESS_API_KEY in the Server 2 .env file.', 'basalam-review-plugin' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="brp-plugin-secret"><?php esc_html_e( 'Plugin Secret', 'basalam-review-plugin' ); ?></label>
                            </th>
                            <td>
                                <div class="brp-key-row">
                                    <input type="password" id="brp-plugin-secret"
                                           name="<?php echo BRP_OPTION_KEY; ?>[plugin_secret]"
                                           value="<?php echo esc_attr( $s['plugin_secret'] ); ?>"
                                           autocomplete="off" />
                                    <button type="button" class="brp-icon-btn" id="brp-eye-secret" title="<?php esc_attr_e( 'Show/Hide', 'basalam-review-plugin' ); ?>" data-target="brp-plugin-secret">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                    <button type="button" class="brp-icon-btn" title="<?php esc_attr_e( 'Copy', 'basalam-review-plugin' ); ?>" data-copy="brp-plugin-secret">
                                        <span class="dashicons dashicons-admin-page"></span>
                                    </button>
                                    <button type="button" class="button" data-gen="brp-plugin-secret">
                                        <?php esc_html_e( '↻ Generate', 'basalam-review-plugin' ); ?>
                                    </button>
                                </div>
                                <p class="description"><?php esc_html_e( 'Set as WORDPRESS_PLUGIN_SECRET in the Server 2 .env file. Used for HMAC-SHA256 request signing.', 'basalam-review-plugin' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"></th>
                            <td>
                                <button type="button" class="button" id="brp-regen-both">
                                    <?php esc_html_e( '↻ Regenerate Both Keys', 'basalam-review-plugin' ); ?>
                                </button>
                                <p class="description"><?php esc_html_e( 'After regenerating, save this page and update the Server 2 .env before the next sync.', 'basalam-review-plugin' ); ?></p>
                            </td>
                        </tr>

                    </tbody>
                </table>

                <!-- ── Review Display ───────────────────────────────────── -->
                <h2><?php esc_html_e( 'Review Display', 'basalam-review-plugin' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>

                        <tr>
                            <th scope="row">
                                <label for="brp-name-prefix"><?php esc_html_e( 'Name Prefix', 'basalam-review-plugin' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="brp-name-prefix"
                                       name="<?php echo BRP_OPTION_KEY; ?>[customer_name_prefix]"
                                       value="<?php echo esc_attr( $s['customer_name_prefix'] ); ?>"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e( 'e.g. خریدار', 'basalam-review-plugin' ); ?>" />
                                <p class="description"><?php esc_html_e( 'Prepended to each reviewer\'s display name.', 'basalam-review-plugin' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="brp-name-suffix"><?php esc_html_e( 'Name Suffix', 'basalam-review-plugin' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="brp-name-suffix"
                                       name="<?php echo BRP_OPTION_KEY; ?>[customer_name_suffix]"
                                       value="<?php echo esc_attr( $s['customer_name_suffix'] ); ?>"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e( 'e.g. عزیز', 'basalam-review-plugin' ); ?>" />
                                <p class="description"><?php esc_html_e( 'Appended to each reviewer\'s display name.', 'basalam-review-plugin' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Auto-approve', 'basalam-review-plugin' ); ?></th>
                            <td>
                                <label class="brp-switch-label">
                                    <span class="brp-switch">
                                        <input type="checkbox" name="<?php echo BRP_OPTION_KEY; ?>[auto_approve]"
                                               value="1" <?php checked( $s['auto_approve'] ); ?> />
                                        <span class="brp-slider"></span>
                                    </span>
                                    <span class="brp-switch-text"><?php esc_html_e( 'Approve imported reviews immediately, skip the moderation queue.', 'basalam-review-plugin' ); ?></span>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Attach Product Image', 'basalam-review-plugin' ); ?></th>
                            <td>
                                <label class="brp-switch-label">
                                    <span class="brp-switch">
                                        <input type="checkbox" name="<?php echo BRP_OPTION_KEY; ?>[attach_product_image]"
                                               value="1" <?php checked( $s['attach_product_image'] ); ?> />
                                        <span class="brp-slider"></span>
                                    </span>
                                    <span class="brp-switch-text"><?php esc_html_e( 'Attach the WooCommerce product thumbnail to each imported review.', 'basalam-review-plugin' ); ?></span>
                                </label>
                            </td>
                        </tr>

                    </tbody>
                </table>

                <!-- ── Seller Replies ────────────────────────────────────── -->
                <h2><?php esc_html_e( 'Seller Replies', 'basalam-review-plugin' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Randomize Name', 'basalam-review-plugin' ); ?></th>
                            <td>
                                <label class="brp-switch-label">
                                    <span class="brp-switch">
                                        <input type="checkbox" name="<?php echo BRP_OPTION_KEY; ?>[admin_name_randomizer]"
                                               value="1" <?php checked( $s['admin_name_randomizer'] ); ?> />
                                        <span class="brp-slider"></span>
                                    </span>
                                    <span class="brp-switch-text"><?php esc_html_e( 'Pick a random name from the pool below for each seller reply.', 'basalam-review-plugin' ); ?></span>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="brp-name-pool"><?php esc_html_e( 'Name Pool', 'basalam-review-plugin' ); ?></label>
                            </th>
                            <td>
                                <textarea id="brp-name-pool"
                                          name="<?php echo BRP_OPTION_KEY; ?>[admin_name_pool]"
                                          rows="4" class="large-text"><?php echo esc_textarea( $s['admin_name_pool'] ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'One name per line. Used when the randomizer above is enabled.', 'basalam-review-plugin' ); ?></p>
                            </td>
                        </tr>

                    </tbody>
                </table>

                <?php submit_button( __( 'Save Settings', 'basalam-review-plugin' ) ); ?>
            </form>

            <!-- ── Plugin information (outside form) ─────────────────── -->
            <h2><?php esc_html_e( 'Plugin Information', 'basalam-review-plugin' ); ?></h2>
            <table class="widefat striped brp-info-table">
                <tbody>
                    <tr>
                        <td><?php esc_html_e( 'Version', 'basalam-review-plugin' ); ?></td>
                        <td><code><?php echo esc_html( BRP_VERSION ); ?></code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'WooCommerce', 'basalam-review-plugin' ); ?></td>
                        <td>
                            <?php if ( class_exists( 'WooCommerce' ) ) : ?>
                                <span class="brp-badge brp-badge-ok">
                                    <?php echo esc_html( sprintf( __( 'Active v%s', 'basalam-review-plugin' ), WC()->version ) ); ?>
                                </span>
                            <?php else : ?>
                                <span class="brp-badge brp-badge-warn"><?php esc_html_e( 'Not active', 'basalam-review-plugin' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Authentication', 'basalam-review-plugin' ); ?></td>
                        <td>
                            <?php if ( $ok ) : ?>
                                <span class="brp-badge brp-badge-ok"><?php esc_html_e( 'API key + HMAC-SHA256', 'basalam-review-plugin' ); ?></span>
                            <?php else : ?>
                                <span class="brp-badge brp-badge-warn"><?php esc_html_e( 'Keys not set', 'basalam-review-plugin' ); ?></span>
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

        </div><!-- .wrap -->

        <script>
        (function () {
            'use strict';

            function randHex( n ) {
                var a = new Uint8Array( n );
                window.crypto.getRandomValues( a );
                return Array.from( a ).map( function ( b ) {
                    return b.toString( 16 ).padStart( 2, '0' );
                } ).join( '' );
            }

            // Show / hide
            document.querySelectorAll( '.brp-icon-btn[data-target]' ).forEach( function ( btn ) {
                btn.addEventListener( 'click', function () {
                    var inp  = document.getElementById( this.dataset.target );
                    var icon = this.querySelector( '.dashicons' );
                    if ( ! inp ) return;
                    if ( inp.type === 'password' ) {
                        inp.type = 'text';
                        if ( icon ) icon.classList.replace( 'dashicons-visibility', 'dashicons-hidden' );
                    } else {
                        inp.type = 'password';
                        if ( icon ) icon.classList.replace( 'dashicons-hidden', 'dashicons-visibility' );
                    }
                } );
            } );

            // Copy
            document.querySelectorAll( '.brp-icon-btn[data-copy]' ).forEach( function ( btn ) {
                btn.addEventListener( 'click', function () {
                    var inp = document.getElementById( this.dataset.copy );
                    if ( ! inp || ! inp.value ) return;
                    var self = this;
                    navigator.clipboard.writeText( inp.value ).then( function () {
                        self.classList.add( 'is-copied' );
                        setTimeout( function () { self.classList.remove( 'is-copied' ); }, 1500 );
                    } );
                } );
            } );

            // Generate single
            document.querySelectorAll( '.button[data-gen]' ).forEach( function ( btn ) {
                btn.addEventListener( 'click', function () {
                    var inp = document.getElementById( this.dataset.gen );
                    if ( inp ) inp.value = randHex( 32 );
                } );
            } );

            // Regenerate both
            var regenBtn = document.getElementById( 'brp-regen-both' );
            if ( regenBtn ) {
                regenBtn.addEventListener( 'click', function () {
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
        $last     = $wpdb->get_var( "SELECT MAX(c.comment_date) {$base}" );

        $last_str = '—';
        if ( $last ) {
            $last_str = date_i18n( 'M j', strtotime( $last ) );
        }

        return [
            'total'     => $total,
            'approved'  => $approved,
            'pending'   => max( 0, $total - $approved ),
            'last_sync' => $last_str,
        ];
    }
}
