<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

defined( 'ABSPATH' ) || exit;

final class SRT_Placeholder_Provider implements SRT_Image_Provider_Interface {
	public function generate_images( string $prompt, array $args = [] ): array {
		return [
			'success' => true,
			'images'  => [
				[
					'url'         => '',
					'local_file'  => SRT_PLUGIN_DIR . 'assets/images/placeholder-route.svg',
					'source'      => 'placeholder',
					'credit'      => '',
					'caption'     => '',
					'description' => 'Internal placeholder illustration.',
				],
			],
		];
	}

	public function search_images( string $query, array $args = [] ): array {
		return $this->generate_images( $query, $args );
	}

	public function download_image( string $image_url, array $args = [] ): array {
		return [
			'success'    => true,
			'local_file' => SRT_PLUGIN_DIR . 'assets/images/placeholder-route.svg',
			'filename'   => 'srt-placeholder-route.svg',
		];
	}

	public function test_connection(): array {
		return [ 'success' => file_exists( SRT_PLUGIN_DIR . 'assets/images/placeholder-route.svg' ), 'message' => 'Placeholder source ready.' ];
	}

	public function supports_generation(): bool {
		return true;
	}

	public function supports_search(): bool {
		return true;
	}
}
