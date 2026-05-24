<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

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
		$normalized_args = is_array( $args ) ? $args : [];
		$normalized_args['overwrite'] = ! empty( $normalized_args['overwrite'] ) ? 1 : 0;
		if ( isset( $normalized_args['external_url'] ) ) {
			$normalized_args['external_url'] = esc_url_raw( (string) $normalized_args['external_url'] );
		}
		return SRT_Image_Manager::generate_for_post( $route_id, $post_id, $normalized_args );
	}

	public static function generate_for_post( int $route_id, int $post_id, bool $overwrite = false, string $external_url = '' ): array {
		$route = RouteRepository::get_by_id( $route_id );
		if ( ! $route ) {
			return [ 'success' => false, 'error' => 'Route not found.' ];
		}
		$args = [
			'overwrite'    => $overwrite,
			'external_url' => $external_url,
		];
		if ( '' !== $external_url ) {
			$args['image_count'] = 1;
		}
		return SRT_Image_Manager::generate_for_post( $route_id, $post_id, $args );
	}
}
