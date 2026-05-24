<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

defined( 'ABSPATH' ) || exit;

final class SRT_Unsplash_Provider implements SRT_Image_Provider_Interface {
	public function generate_images( string $prompt, array $args = [] ): array {
		return [ 'success' => false, 'images' => [], 'error' => 'Unsplash only supports stock search.' ];
	}

	public function search_images( string $query, array $args = [] ): array {
		$key = SRT_Image_Source_Config::api_key( 'unsplash' );
		if ( '' === $key ) {
			return [ 'success' => false, 'images' => [], 'error' => 'Missing Unsplash access key.' ];
		}
		$config = SRT_Image_Source_Config::get();

		$url = add_query_arg(
			array_filter(
				[
				'query'       => $query,
				'per_page'    => max( 1, min( 15, (int) ( $args['count'] ?? 5 ) ) ),
				'orientation' => (string) ( $args['orientation'] ?? $config['unsplash_orientation'] ),
				'order_by'    => (string) ( $args['order_by'] ?? $config['unsplash_order_by'] ),
				'content_filter' => (string) ( $args['content_filter'] ?? $config['unsplash_content_filter'] ),
				'color'       => (string) ( $args['color'] ?? $config['unsplash_color'] ),
				],
				static fn( $value ): bool => '' !== (string) $value
			),
			'https://api.unsplash.com/search/photos'
		);
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 20,
				'headers' => [ 'Authorization' => 'Client-ID ' . $key ],
			]
		);
		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'images' => [], 'error' => $response->get_error_message() ];
		}
		$json  = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = [];
		foreach ( (array) ( $json['results'] ?? [] ) as $photo ) {
			$user = (string) ( $photo['user']['name'] ?? '' );
			$items[] = [
				'url'         => (string) ( $photo['urls']['regular'] ?? $photo['urls']['full'] ?? '' ),
				'source'      => 'unsplash',
				'html_url'    => (string) ( $photo['links']['html'] ?? '' ),
				'download_location' => (string) ( $photo['links']['download_location'] ?? '' ),
				'credit'      => '' !== $user ? 'Photo: ' . $user . ' / Unsplash' : 'Unsplash',
				'caption'     => '' !== $user ? 'Photo by ' . $user . ' on Unsplash' : 'Photo from Unsplash',
				'description' => 'Stock image from Unsplash.',
			];
		}
		return [ 'success' => ! empty( $items ), 'images' => $items, 'error' => empty( $items ) ? 'No Unsplash images found.' : '' ];
	}

	public function download_image( string $image_url, array $args = [] ): array {
		return [
			'success'  => '' !== $image_url,
			'url'      => $image_url,
			'filename' => (string) ( $args['filename'] ?? basename( parse_url( $image_url, PHP_URL_PATH ) ?: 'srt-unsplash-image.jpg' ) ),
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

	public static function trigger_download_tracking( array $candidate ): void {
		$download_location = esc_url_raw( (string) ( $candidate['download_location'] ?? '' ) );
		$key = SRT_Image_Source_Config::api_key( 'unsplash' );
		if ( '' === $download_location || '' === $key ) {
			return;
		}
		wp_remote_get(
			$download_location,
			[
				'timeout' => 15,
				'headers' => [ 'Authorization' => 'Client-ID ' . $key ],
			]
		);
	}
}
