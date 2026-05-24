<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

defined( 'ABSPATH' ) || exit;

final class SRT_Pixabay_Provider implements SRT_Image_Provider_Interface {
	public function generate_images( string $prompt, array $args = [] ): array {
		return [ 'success' => false, 'images' => [], 'error' => 'Pixabay only supports stock search.' ];
	}

	public function search_images( string $query, array $args = [] ): array {
		$key = SRT_Image_Source_Config::api_key( 'pixabay' );
		if ( '' === $key ) {
			return [ 'success' => false, 'images' => [], 'error' => 'Missing Pixabay API key.' ];
		}
		$config = SRT_Image_Source_Config::get();
		$requested_count = max( 1, min( 15, (int) ( $args['count'] ?? 5 ) ) );

		$url = add_query_arg(
			array_filter(
				[
				'key'         => $key,
				'q'           => $query,
				'per_page'    => max( 3, min( 200, $requested_count ) ),
				'image_type'  => (string) ( $args['image_type'] ?? $config['pixabay_image_type'] ),
				'orientation' => (string) ( $args['orientation'] ?? $config['pixabay_orientation'] ),
				'safesearch'  => ! empty( $args['safesearch'] ) || ! empty( $config['pixabay_safesearch'] ) ? 'true' : 'false',
				'order'       => (string) ( $args['order'] ?? $config['pixabay_order'] ),
				'category'    => (string) ( $args['category'] ?? $config['pixabay_category'] ),
				'colors'      => (string) ( $args['colors'] ?? $config['pixabay_colors'] ),
				'editors_choice' => ! empty( $args['editors_choice'] ) || ! empty( $config['pixabay_editors_choice'] ) ? 'true' : 'false',
				],
				static fn( $value ): bool => '' !== (string) $value
			),
			'https://pixabay.com/api/'
		);
		$response = wp_remote_get( $url, [ 'timeout' => 20 ] );
		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'images' => [], 'error' => $response->get_error_message() ];
		}
		$json  = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = [];
		foreach ( array_slice( (array) ( $json['hits'] ?? [] ), 0, $requested_count ) as $photo ) {
			$items[] = [
				'url'         => (string) ( $photo['largeImageURL'] ?? $photo['webformatURL'] ?? '' ),
				'source'      => 'pixabay',
				'html_url'    => (string) ( $photo['pageURL'] ?? '' ),
				'credit'      => ! empty( $photo['user'] ) ? 'Photo: ' . (string) $photo['user'] . ' / Pixabay' : 'Pixabay',
				'caption'     => ! empty( $photo['user'] ) ? 'Photo by ' . (string) $photo['user'] . ' on Pixabay' : 'Photo from Pixabay',
				'description' => 'Stock image from Pixabay.',
			];
		}
		return [ 'success' => ! empty( $items ), 'images' => $items, 'error' => empty( $items ) ? 'No Pixabay images found.' : '' ];
	}

	public function download_image( string $image_url, array $args = [] ): array {
		return [
			'success'  => '' !== $image_url,
			'url'      => $image_url,
			'filename' => (string) ( $args['filename'] ?? basename( parse_url( $image_url, PHP_URL_PATH ) ?: 'srt-pixabay-image.jpg' ) ),
		];
	}

	public function test_connection(): array {
		return $this->search_images( 'mekong delta taxi', [ 'count' => 1 ] );
	}

	public function supports_generation(): bool {
		return false;
	}

	public function supports_search(): bool {
		return true;
	}
}
