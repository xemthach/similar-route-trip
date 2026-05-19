<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

use SimilarRouteTrip\AI\AIConfig;
use SimilarRouteTrip\AI\AIService;
use SimilarRouteTrip\Content\PlaceholderResolver;
use SimilarRouteTrip\Content\PromptTemplateManager;
use SimilarRouteTrip\Database\RouteRepository;

defined( 'ABSPATH' ) || exit;

final class ImageGenerator {
	public static function generate_for_route( array $route, int $post_id = 0, array $args = [] ): array {
		$route_id = (int) ( $route['id'] ?? 0 );
		if ( $route_id <= 0 ) {
			return [ 'success' => false, 'error' => 'Route not found.' ];
		}
		$post_id = $post_id > 0 ? $post_id : (int) ( $route['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return [ 'success' => false, 'error' => 'Post not found for image assignment.' ];
		}
		return self::generate_for_post(
			$route_id,
			$post_id,
			! empty( $args['overwrite'] ),
			esc_url_raw( (string) ( $args['external_url'] ?? '' ) )
		);
	}

	public static function generate_for_post( int $route_id, int $post_id, bool $overwrite = false, string $external_url = '' ): array {
		if ( has_post_thumbnail( $post_id ) && ! $overwrite ) {
			return [ 'success' => false, 'error' => 'Featured image already exists.' ];
		}
		$route = RouteRepository::get_by_id( $route_id );
		if ( ! $route ) {
			return [ 'success' => false, 'error' => 'Route not found.' ];
		}
		$url = $external_url;
		if ( '' === $url ) {
			$config = AIConfig::get();
			if ( empty( $config['enable_image'] ) ) {
				return [ 'success' => false, 'error' => 'AI image generation disabled.' ];
			}
			$prompt = PlaceholderResolver::resolve( PromptTemplateManager::get()['image_prompt'], $route );
			$result = AIService::image_provider()->generate_image( $prompt );
			if ( empty( $result['success'] ) ) {
				return $result;
			}
			$url = (string) $result['url'];
		}
		$alt = sprintf( 'Taxi %s di %s', $route['from_city'], $route['to_city'] );
		$image_id = MediaUploader::sideload( $url, $post_id, $alt );
		if ( ! $image_id ) {
			return [ 'success' => false, 'error' => 'Image upload failed.' ];
		}
		set_post_thumbnail( $post_id, $image_id );
		RouteRepository::update_generation_meta( $route_id, [ 'image_id' => $image_id ] );
		return [ 'success' => true, 'image_id' => $image_id ];
	}
}
