<?php
/**
 * Plugin Name: Basalam Review Plugin
 * Plugin URI:  https://github.com/jolfaguy12-cell/basalam-reviews
 * Description: Receives reviews from the Basalam sync service and inserts them into WooCommerce.
 * Version:     1.2.5
 * Author:      Behdashtik
 * Text Domain: basalam-review-plugin
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'BRP_VERSION',    '1.2.5' );
define( 'BRP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BRP_OPTION_KEY', 'brp_settings' );

require_once BRP_PLUGIN_DIR . 'includes/class-brp-security.php';
require_once BRP_PLUGIN_DIR . 'includes/class-brp-settings.php';
require_once BRP_PLUGIN_DIR . 'includes/class-brp-processor.php';
require_once BRP_PLUGIN_DIR . 'includes/class-brp-api.php';

add_action( 'plugins_loaded', function () {
    BRP_Settings::init();
    BRP_API::init();
} );

register_activation_hook( __FILE__, function () {
    if ( ! get_option( BRP_OPTION_KEY ) ) {
        update_option( BRP_OPTION_KEY, BRP_Settings::defaults() );
    }
    brp_cleanup_action_scheduler();
    brp_unapprove_star_only_reviews();
    update_option( 'brp_unapproved_version', BRP_VERSION );
} );

// One-time cleanup of failed/pending action scheduler jobs left by older plugin versions.
// Runs on activation and once after upgrade (tracked by version option).
function brp_cleanup_action_scheduler(): void {
    if ( ! class_exists( 'ActionScheduler_Store' ) ) {
        return;
    }
    $store = ActionScheduler_Store::instance();
    foreach ( [ 'failed', 'pending' ] as $status ) {
        $ids = $store->query_actions( [
            'group'    => 'basalam-review-plugin',
            'status'   => $status,
            'per_page' => -1,
        ] );
        foreach ( $ids as $id ) {
            $store->delete_action( $id );
        }
    }
}

// Set comment_approved=0 for all Basalam-imported reviews that have no text content.
// Runs on activation and on version upgrade. Returns the count of rows updated.
function brp_unapprove_star_only_reviews(): int {
    global $wpdb;
    $offset  = 0;
    $batch   = 50;
    $updated = 0;

    do {
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT c.comment_ID
             FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->commentmeta} cm
                 ON c.comment_ID = cm.comment_id
                 AND cm.meta_key = 'basalam_review_id'
             WHERE c.comment_type     = 'review'
               AND c.comment_content  = ''
               AND c.comment_approved = '1'
               AND c.comment_parent   = 0
             LIMIT %d OFFSET %d",
            $batch,
            $offset
        ) );

        if ( empty( $ids ) ) {
            break;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->comments} SET comment_approved = '0' WHERE comment_ID IN ({$placeholders})",
            ...$ids
        ) );

        $updated += count( $ids );
        $offset  += $batch;

    } while ( count( $ids ) === $batch );

    return $updated;
}

add_action( 'admin_init', function () {
    $cleaned_version = get_option( 'brp_as_cleaned_version', '' );
    if ( $cleaned_version !== BRP_VERSION ) {
        brp_cleanup_action_scheduler();
        update_option( 'brp_as_cleaned_version', BRP_VERSION );
    }

    $unapproved_version = get_option( 'brp_unapproved_version', '' );
    if ( $unapproved_version !== BRP_VERSION ) {
        brp_unapprove_star_only_reviews();
        update_option( 'brp_unapproved_version', BRP_VERSION );
    }
} );

// Fire-and-forget log push to the backend log server.
// Non-blocking: does not delay the caller. No-op when logging is disabled.
function brp_push_log( string $level, string $message, array $context = [] ): void {
    $s        = (array) get_option( BRP_OPTION_KEY, [] );
    if ( empty( $s['log_enabled'] ) ) {
        return;
    }
    $endpoint = rtrim( $s['log_endpoint'] ?? '', '/' );
    $api_key  = $s['log_api_key'] ?: ( $s['api_key'] ?? '' );
    if ( empty( $endpoint ) || empty( $api_key ) ) {
        return;
    }
    wp_remote_post( $endpoint . '/logs', [
        'blocking'  => false,
        'timeout'   => 5,
        'headers'   => [
            'Content-Type'  => 'application/json',
            'X-BRP-API-Key' => $api_key,
        ],
        'body'      => wp_json_encode( compact( 'level', 'message', 'context' ) ),
        'sslverify' => false,
    ] );
}
