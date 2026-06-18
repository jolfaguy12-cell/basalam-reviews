<?php
defined( 'ABSPATH' ) || exit;

class BRP_API {

    private const NAMESPACE = 'basalam-review/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
    }

    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/health', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ self::class, 'health' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/receive', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ self::class, 'receive' ],
            'permission_callback' => [ self::class, 'authenticate' ],
            'args'                => self::receive_args(),
        ] );
    }

    // ── Permission callback ───────────────────────────────────────────────────

    public static function authenticate( WP_REST_Request $request ): bool|WP_Error {
        if ( BRP_Security::authenticate( $request ) ) {
            return true;
        }
        return new WP_Error(
            'brp_unauthorized',
            __( 'Invalid API key or signature.', 'basalam-review-plugin' ),
            [ 'status' => 401 ]
        );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public static function health(): WP_REST_Response {
        return new WP_REST_Response( [
            'status'  => 'ok',
            'version' => BRP_VERSION,
            'time'    => current_time( 'mysql' ),
        ], 200 );
    }

    public static function receive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $payload = $request->get_json_params();

        if ( empty( $payload ) ) {
            return new WP_Error(
                'brp_empty_payload',
                __( 'Empty or invalid JSON payload.', 'basalam-review-plugin' ),
                [ 'status' => 400 ]
            );
        }

        $wc_comment_id = BRP_Processor::insert( $payload );

        if ( $wc_comment_id === 0 ) {
            return new WP_REST_Response( [
                'status'        => 'failed',
                'wc_comment_id' => null,
                'message'       => 'Insert failed — product not found or DB error.',
            ], 422 );
        }

        return new WP_REST_Response( [
            'status'        => 'ok',
            'wc_comment_id' => $wc_comment_id,
        ], 201 );
    }

    // ── Argument schema ───────────────────────────────────────────────────────

    private static function receive_args(): array {
        return [
            'basalam_review_id' => [
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'wc_product_id' => [
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'user_name' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'star' => [
                'required' => true,
                'type'     => 'integer',
                'minimum'  => 1,
                'maximum'  => 5,
            ],
            'description' => [
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'created_at' => [
                'type'    => 'string',
                'default' => '',
            ],
            'replies' => [
                'type'    => 'array',
                'default' => [],
                'items'   => [
                    'type'       => 'object',
                    'properties' => [
                        'author_name' => [ 'type' => 'string' ],
                        'description' => [ 'type' => 'string' ],
                    ],
                ],
            ],
        ];
    }
}
