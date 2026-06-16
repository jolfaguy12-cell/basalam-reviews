<?php
defined( 'ABSPATH' ) || exit;

class BRP_Security {

    /**
     * Validate the HMAC-SHA256 signature sent by the backend service.
     *
     * Header: X-BRP-Signature: sha256=<hex>
     */
    public static function verify_signature( WP_REST_Request $request ): bool {
        $settings  = get_option( BRP_OPTION_KEY, BRP_Settings::defaults() );
        $secret    = $settings['plugin_secret'] ?? '';

        if ( empty( $secret ) ) {
            return false;
        }

        $header = $request->get_header( 'X-BRP-Signature' );
        if ( ! $header || ! str_starts_with( $header, 'sha256=' ) ) {
            return false;
        }

        $provided = substr( $header, 7 );
        $body     = $request->get_body();
        $expected = hash_hmac( 'sha256', $body, $secret );

        return hash_equals( $expected, $provided );
    }

    /**
     * Validate the API key sent by the backend service.
     *
     * Header: X-BRP-API-Key: <key>
     */
    public static function verify_api_key( WP_REST_Request $request ): bool {
        $settings = get_option( BRP_OPTION_KEY, BRP_Settings::defaults() );
        $key      = $settings['api_key'] ?? '';

        if ( empty( $key ) ) {
            return false;
        }

        $provided = $request->get_header( 'X-BRP-API-Key' );
        return hash_equals( $key, (string) $provided );
    }

    /**
     * Full request authentication: must pass both API key and signature.
     */
    public static function authenticate( WP_REST_Request $request ): bool {
        return self::verify_api_key( $request ) && self::verify_signature( $request );
    }

    /**
     * Generate a cryptographically secure random secret.
     */
    public static function generate_secret( int $length = 48 ): string {
        return bin2hex( random_bytes( $length ) );
    }
}
