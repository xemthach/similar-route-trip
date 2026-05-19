<?php
/**
 * REST API controller.
 *
 * @package SimilarRouteTrip\REST
 */

declare( strict_types=1 );

namespace SimilarRouteTrip\REST;

use SimilarRouteTrip\Database\RouteRepository;
use SimilarRouteTrip\AI\AIService;
use SimilarRouteTrip\Content\ContentGenerator;
use SimilarRouteTrip\Image\ImageGenerator;
use SimilarRouteTrip\Routes\RouteImporter;
use SimilarRouteTrip\Routes\RouteCreator;
use SimilarRouteTrip\Routes\SimilarRouteFinder;
use SimilarRouteTrip\SEO\PromptBuilder;

defined( 'ABSPATH' ) || exit;

final class RestController {

	private const NS = 'similar-route-trip/v1';

	public static function register(): void {
		register_rest_route(
			self::NS,
			'/routes',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'routes' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NS,
			'/routes/(?P<slug>[a-z0-9][a-z0-9-]*)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'route' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NS,
			'/routes/(?P<slug>[a-z0-9][a-z0-9-]*)/similar',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'similar' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NS,
			'/routes/(?P<slug>[a-z0-9][a-z0-9-]*)/prompt',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'prompt' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NS,
			'/import',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'import' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NS,
			'/sync-prices',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'sync_prices' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		$admin_routes = [
			'/ai/test'                  => 'ai_test',
			'/routes/generate-preview'  => 'routes_generate_preview',
			'/routes/bulk-create'       => 'routes_bulk_create',
			'/content/generate-preview' => 'content_generate_preview',
			'/content/create-post'      => 'content_create_post',
			'/image/generate'           => 'image_generate',
		];

		foreach ( $admin_routes as $route => $callback ) {
			register_rest_route(
				self::NS,
				$route,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, $callback ],
					'permission_callback' => [ self::class, 'can_manage_with_nonce' ],
				]
			);
		}
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function can_manage_with_nonce( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$nonce = (string) ( $request->get_header( 'x_wp_nonce' ) ?: $request->get_param( '_wpnonce' ) );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'srt_rest_nonce_failed', __( 'REST nonce is required.', 'similar-route-trip' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public static function routes( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			[
				'routes' => array_map( [ self::class, 'format_route' ], RouteRepository::all() ),
				'total'  => RouteRepository::count(),
			]
		);
	}

	public static function route( \WP_REST_Request $request ) {
		$route = RouteRepository::get( (string) $request['slug'] );
		if ( ! $route ) {
			return new \WP_Error( 'srt_route_not_found', __( 'Route not found.', 'similar-route-trip' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( self::format_route( $route ) );
	}

	public static function similar( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = isset( $request['limit'] ) ? (int) $request['limit'] : 6;
		return rest_ensure_response(
			[
				'routes' => array_map( [ self::class, 'format_route' ], SimilarRouteFinder::find( (string) $request['slug'], $limit ) ),
			]
		);
	}

	public static function prompt( \WP_REST_Request $request ) {
		$route = RouteRepository::get( (string) $request['slug'] );
		if ( ! $route ) {
			return new \WP_Error( 'srt_route_not_found', __( 'Route not found.', 'similar-route-trip' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response(
			[
				'article_prompt' => PromptBuilder::route_article_prompt( $route ),
				'meta_prompt'    => PromptBuilder::meta_prompt( $route ),
			]
		);
	}

	public static function import( \WP_REST_Request $request ): \WP_REST_Response {
		$source = sanitize_key( (string) ( $request->get_param( 'source' ) ?: 'theme' ) );
		$result = 'tre' === $source ? RouteImporter::import_taxi_route_engine() : RouteImporter::import_theme_options();

		return rest_ensure_response( $result );
	}

	public static function sync_prices(): \WP_REST_Response {
		return rest_ensure_response( RouteImporter::sync_prices() );
	}

	public static function ai_test(): \WP_REST_Response {
		return rest_ensure_response(
			[
				'active' => AIService::provider()->test_connection(),
				'keys'   => AIService::test_all_keys(),
			]
		);
	}

	public static function routes_generate_preview( \WP_REST_Request $request ): \WP_REST_Response {
		$to_locations = self::lines( (string) $request->get_param( 'to_locations' ) );
		return rest_ensure_response(
			[
				'routes' => RouteCreator::preview(
					(string) $request->get_param( 'from_location' ),
					$to_locations,
					self::route_args( $request )
				),
			]
		);
	}

	public static function routes_bulk_create( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			RouteCreator::bulk_create(
				(string) $request->get_param( 'from_location' ),
				self::lines( (string) $request->get_param( 'to_locations' ) ),
				self::route_args( $request )
			)
		);
	}

	public static function content_generate_preview( \WP_REST_Request $request ) {
		$route = self::route_from_request( $request );
		if ( is_wp_error( $route ) ) {
			return $route;
		}
		return rest_ensure_response(
			ContentGenerator::preview(
				$route,
				sanitize_key( (string) ( $request->get_param( 'template' ) ?: 'route_landing' ) ),
				(bool) $request->get_param( 'use_ai' ),
				[
					'topic_id' => sanitize_key( (string) ( $request->get_param( 'topic' ) ?: 'route_landing' ) ),
					'content_length' => sanitize_key( (string) ( $request->get_param( 'content_length' ) ?: 'standard' ) ),
					'min_words' => (int) $request->get_param( 'min_words' ),
					'max_words' => (int) $request->get_param( 'max_words' ),
					'primary_keyword' => sanitize_text_field( (string) ( $request->get_param( 'primary_keyword' ) ?: '' ) ),
					'secondary_keywords' => sanitize_text_field( (string) ( $request->get_param( 'secondary_keywords' ) ?: '' ) ),
				]
			)
		);
	}

	public static function content_create_post( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			ContentGenerator::create_post(
				(int) $request->get_param( 'route_id' ),
				[
					'post_type'  => sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'post' ) ),
					'status'     => sanitize_key( (string) ( $request->get_param( 'status' ) ?: 'draft' ) ),
					'template'   => sanitize_key( (string) ( $request->get_param( 'template' ) ?: 'route_landing' ) ),
					'topic'      => sanitize_key( (string) ( $request->get_param( 'topic' ) ?: 'route_landing' ) ),
					'content_length' => sanitize_key( (string) ( $request->get_param( 'content_length' ) ?: 'standard' ) ),
					'min_words'  => (int) $request->get_param( 'min_words' ),
					'max_words'  => (int) $request->get_param( 'max_words' ),
					'primary_keyword' => sanitize_text_field( (string) ( $request->get_param( 'primary_keyword' ) ?: '' ) ),
					'secondary_keywords' => sanitize_text_field( (string) ( $request->get_param( 'secondary_keywords' ) ?: '' ) ),
					'use_ai'     => (bool) $request->get_param( 'use_ai' ),
					'regenerate' => (bool) $request->get_param( 'regenerate' ),
				]
			)
		);
	}

	public static function image_generate( \WP_REST_Request $request ) {
		$route = self::route_from_request( $request );
		if ( is_wp_error( $route ) ) {
			return $route;
		}
		return rest_ensure_response(
			ImageGenerator::generate_for_route(
				$route,
				(int) $request->get_param( 'post_id' ),
				[
					'external_url' => esc_url_raw( (string) $request->get_param( 'external_url' ) ),
					'overwrite'    => (bool) $request->get_param( 'overwrite' ),
				]
			)
		);
	}

	private static function format_route( array $route ): array {
		return [
			'slug'           => $route['slug'] ?? '',
			'from'           => $route['from_city'] ?? '',
			'to'             => $route['to_city'] ?? '',
			'distance_km'    => (float) ( $route['distance_km'] ?? 0 ),
			'duration_min'   => (int) ( $route['duration_min'] ?? 0 ),
			'price_min'      => (int) ( $route['price_min'] ?? 0 ),
			'price_display'  => $route['price_display'] ?? '',
			'vehicle_prices' => json_decode( (string) ( $route['vehicle_prices_json'] ?? '[]' ), true ) ?: [],
			'booking_url'    => $route['booking_url'] ?? '',
			'landing_url'    => $route['landing_url'] ?? '',
			'source'         => $route['source'] ?? '',
			'is_active'      => (bool) ( $route['is_active'] ?? true ),
			'post_id'        => (int) ( $route['post_id'] ?? 0 ),
			'post_status'    => $route['post_status'] ?? '',
			'ai_status'      => $route['ai_status'] ?? '',
		];
	}

	private static function lines( string $value ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R+/', $value ) ?: [] ) ) );
	}

	private static function route_args( \WP_REST_Request $request ): array {
		return [
			'distance_km'    => (float) $request->get_param( 'distance_km' ),
			'duration_min'   => (int) $request->get_param( 'duration_min' ),
			'price_min'      => (int) $request->get_param( 'price_min' ),
			'detect_reverse' => (bool) $request->get_param( 'detect_reverse' ),
			'overwrite'      => (bool) $request->get_param( 'overwrite' ),
		];
	}

	private static function route_from_request( \WP_REST_Request $request ) {
		$route_id = (int) $request->get_param( 'route_id' );
		$route = $route_id > 0 ? RouteRepository::get_by_id( $route_id ) : RouteRepository::get( (string) $request->get_param( 'slug' ) );
		if ( ! $route ) {
			return new \WP_Error( 'srt_route_not_found', __( 'Route not found.', 'similar-route-trip' ), [ 'status' => 404 ] );
		}
		return $route;
	}
}
