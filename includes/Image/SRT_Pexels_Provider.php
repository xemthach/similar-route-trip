<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

defined( 'ABSPATH' ) || exit;

final class SRT_Pexels_Provider implements SRT_Image_Provider_Interface {
	public function generate_images( string $prompt, array $args = [] ): array {
		return [ 'success' => false, 'images' => [], 'error' => 'Pexels only supports stock search.' ];
	}

	public function search_images( string $query, array $args = [] ): array {
		$key = SRT_Image_Source_Config::api_key( 'pexels' );
		if ( '' === $key ) {
			return [ 'success' => false, 'images' => [], 'error' => 'Missing Pexels API key.' ];
		}
		$config = SRT_Image_Source_Config::get();

		$url = add_query_arg(
			array_filter(
				[
				'query'       => $query,
				'per_page'    => max( 1, min( 15, (int) ( $args['count'] ?? 5 ) ) ),
				'orientation' => (string) ( $args['orientation'] ?? $config['pexels_orientation'] ),
				'size'        => (string) ( $args['size'] ?? $config['pexels_size'] ),
				'color'       => (string) ( $args['color'] ?? $config['pexels_color'] ),
				'locale'      => (string) ( $args['locale'] ?? $config['pexels_locale'] ),
				],
				static fn( $value ): bool => '' !== (string) $value
			),
			'https://api.pexels.com/v1/search'
		);
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 20,
				'headers' => [ 'Authorization' => $key ],
			]
		);
		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'images' => [], 'error' => $response->get_error_message() ];
		}
		$json  = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = [];
		foreach ( (array) ( $json['photos'] ?? [] ) as $photo ) {
			$items[] = [
				'url'         => (string) ( $photo['src']['large2x'] ?? $photo['src']['large'] ?? '' ),
				'source'      => 'pexels',
				'html_url'    => (string) ( $photo['url'] ?? '' ),
				'credit'      => ! empty( $photo['photographer'] ) ? 'Photo: ' . (string) $photo['photographer'] . ' / Pexels' : 'Pexels',
				'caption'     => ! empty( $photo['photographer'] ) ? 'Photo by ' . (string) $photo['photographer'] . ' on Pexels' : 'Photo from Pexels',
				'description' => (string) ( $photo['alt'] ?? 'Stock image from Pexels.' ),
			];
		}
		return [ 'success' => ! empty( $items ), 'images' => $items, 'error' => empty( $items ) ? 'No Pexels images found.' : '' ];
	}

	public function download_image( string $image_url, array $args = [] ): array {
		return [
			'success'  => '' !== $image_url,
			'url'      => $image_url,
			'filename' => (string) ( $args['filename'] ?? basename( parse_url( $image_url, PHP_URL_PATH ) ?: 'srt-pexels-image.jpg' ) ),
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
