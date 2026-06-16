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
        add_action( 'admin_menu', [ self::class, 'add_menu' ] );
        add_action( 'admin_init', [ self::class, 'register_fields' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
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

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = get_option( BRP_OPTION_KEY, self::defaults() );
        $stats    = self::get_stats();
        ?>
        <div class="wrap brp-wrap">
            <h1><?php esc_html_e( 'Basalam Review Plugin', 'basalam-review-plugin' ); ?></h1>

            <div class="brp-stats-bar">
                <span><strong><?php echo esc_html( $stats['total'] ); ?></strong> <?php esc_html_e( 'Total Reviews', 'basalam-review-plugin' ); ?></span>
                <span><strong><?php echo esc_html( $stats['approved'] ); ?></strong> <?php esc_html_e( 'Approved', 'basalam-review-plugin' ); ?></span>
                <span><strong><?php echo esc_html( $stats['pending'] ); ?></strong> <?php esc_html_e( 'Pending', 'basalam-review-plugin' ); ?></span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'brp_settings_group' ); ?>

                <h2><?php esc_html_e( 'Connection', 'basalam-review-plugin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'API Key', 'basalam-review-plugin' ); ?></th>
                        <td>
                            <input type="text" name="<?php echo BRP_OPTION_KEY; ?>[api_key]"
                                   value="<?php echo esc_attr( $settings['api_key'] ); ?>"
                                   class="regular-text" />
                            <button type="button" class="button brp-generate" data-target="api_key">
                                <?php esc_html_e( 'Generate', 'basalam-review-plugin' ); ?>
                            </button>
                            <p class="description"><?php esc_html_e( 'Copy this key into WORDPRESS_API_KEY in your backend .env file.', 'basalam-review-plugin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Plugin Secret', 'basalam-review-plugin' ); ?></th>
                        <td>
                            <input type="text" name="<?php echo BRP_OPTION_KEY; ?>[plugin_secret]"
                                   value="<?php echo esc_attr( $settings['plugin_secret'] ); ?>"
                                   class="regular-text" />
                            <button type="button" class="button brp-generate" data-target="plugin_secret">
                                <?php esc_html_e( 'Generate', 'basalam-review-plugin' ); ?>
                            </button>
                            <p class="description"><?php esc_html_e( 'Copy this secret into WORDPRESS_PLUGIN_SECRET in your backend .env file.', 'basalam-review-plugin' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Customer Name', 'basalam-review-plugin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Name Prefix', 'basalam-review-plugin' ); ?></th>
                        <td><input type="text" name="<?php echo BRP_OPTION_KEY; ?>[customer_name_prefix]"
                                   value="<?php echo esc_attr( $settings['customer_name_prefix'] ); ?>"
                                   class="regular-text" placeholder="<?php esc_attr_e( 'e.g. خریدار', 'basalam-review-plugin' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Name Suffix', 'basalam-review-plugin' ); ?></th>
                        <td><input type="text" name="<?php echo BRP_OPTION_KEY; ?>[customer_name_suffix]"
                                   value="<?php echo esc_attr( $settings['customer_name_suffix'] ); ?>"
                                   class="regular-text" placeholder="<?php esc_attr_e( 'e.g. عزیز', 'basalam-review-plugin' ); ?>" /></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Admin / Seller Replies', 'basalam-review-plugin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Randomize Admin Name', 'basalam-review-plugin' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo BRP_OPTION_KEY; ?>[admin_name_randomizer]"
                                       value="1" <?php checked( $settings['admin_name_randomizer'] ); ?> />
                                <?php esc_html_e( 'Pick a random name from the pool below for each seller reply', 'basalam-review-plugin' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Admin Name Pool', 'basalam-review-plugin' ); ?></th>
                        <td>
                            <textarea name="<?php echo BRP_OPTION_KEY; ?>[admin_name_pool]"
                                      rows="5" class="large-text"><?php echo esc_textarea( $settings['admin_name_pool'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'One name per line. Used when randomizer is enabled.', 'basalam-review-plugin' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Image & Import', 'basalam-review-plugin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Attach Product Image', 'basalam-review-plugin' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo BRP_OPTION_KEY; ?>[attach_product_image]"
                                       value="1" <?php checked( $settings['attach_product_image'] ); ?> />
                                <?php esc_html_e( 'Attach the WooCommerce product image to imported reviews', 'basalam-review-plugin' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Auto-approve Reviews', 'basalam-review-plugin' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo BRP_OPTION_KEY; ?>[auto_approve]"
                                       value="1" <?php checked( $settings['auto_approve'] ); ?> />
                                <?php esc_html_e( 'Approve imported reviews immediately (no moderation queue)', 'basalam-review-plugin' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>

        <script>
        document.querySelectorAll('.brp-generate').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var target = this.dataset.target;
                var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                var result = '';
                var arr = new Uint8Array(48);
                window.crypto.getRandomValues(arr);
                arr.forEach(function(v) { result += chars[v % chars.length]; });
                var input = this.closest('td').querySelector('input[type="text"]');
                if (input) input.value = result;
            });
        });
        </script>
        <?php
    }

    private static function get_stats(): array {
        global $wpdb;
        $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'review' AND comment_meta.meta_key = 'basalam_review_id'" );
        // Simpler cross-compatible query
        $total    = (int) $wpdb->get_var(
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
