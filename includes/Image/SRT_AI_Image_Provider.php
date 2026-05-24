<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

use SimilarRouteTrip\AI\AIService;

defined( 'ABSPATH' ) || exit;

final class SRT_AI_Image_Provider implements SRT_AI_Image_Provider_Interface {
	public function generate_images( string $prompt, array $args = [] ): array {
		$result = AIService::image_provider()->generate_image(
			$prompt,
			[
				'n'               => max( 1, min( 5, (int) ( $args['count'] ?? 1 ) ) ),
				'size'            => (string) ( $args['size'] ?? '1024x576' ),
				'quality'         => (string) ( $args['quality'] ?? '' ),
				'style'           => (string) ( $args['style'] ?? '' ),
				'response_format' => (string) ( $args['response_format'] ?? '' ),
			]
		);
		if ( empty( $result['success'] ) ) {
			return [
				'success' => false,
				'images'  => [],
				'error'   => (string) ( $result['error'] ?? 'AI image generation failed.' ),
			];
		}

		$images = [];
		foreach ( (array) ( $result['images'] ?? [] ) as $image ) {
			$images[] = [
				'url'         => (string) ( $image['url'] ?? '' ),
				'base64_data' => (string) ( $image['base64_data'] ?? '' ),
				'mime_type'   => (string) ( $image['mime_type'] ?? 'image/png' ),
				'source'      => 'ai',
				'credit'      => '',
				'caption'     => '',
				'description' => 'AI-generated route image.',
			];
		}

		return [ 'success' => ! empty( $images ), 'images' => $images, 'raw' => $result['raw'] ?? [] ];
	}

	public function search_images( string $query, array $args = [] ): array {
		return [ 'success' => false, 'images' => [], 'error' => 'AI provider does not support stock search.' ];
	}

	public function download_image( string $image_url, array $args = [] ): array {
		return [
			'success'  => '' !== $image_url,
			'url'      => $image_url,
			'filename' => (string) ( $args['filename'] ?? basename( parse_url( $image_url, PHP_URL_PATH ) ?: 'srt-ai-image.jpg' ) ),
		];
	}

	public function test_connection(): array {
		return AIService::image_provider()->test_connection();
	}

	public function supports_generation(): bool {
		return true;
	}

	public function supports_search(): bool {
		return false;
	}
}
