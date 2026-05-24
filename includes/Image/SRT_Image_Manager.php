<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

use SimilarRouteTrip\AI\AIRuntimeConfig;
use SimilarRouteTrip\AI\ImageProviderRegistry;
use SimilarRouteTrip\Database\RouteRepository;
use SimilarRouteTrip\Logging\LogRepository;
use SimilarRouteTrip\Queue\QueueRepository;

defined( 'ABSPATH' ) || exit;

final class SRT_Image_Manager {
	public static function generate_for_post( int $route_id, int $post_id, array $args = [] ): array {
		$route = RouteRepository::get_by_id( $route_id );
		$post  = get_post( $post_id );
		if ( ! $route || ! $post ) {
			return [ 'success' => false, 'error' => 'Route or post not found.' ];
		}

		$settings = self::resolve_settings( $args );
		$count    = self::image_count( $settings, $args );
		if ( 'disabled' === $settings['image_source_mode'] || $count <= 0 || empty( $settings['enable_image'] ) ) {
			return [ 'success' => true, 'skipped' => true, 'message' => 'Image generation disabled.' ];
		}

		if ( empty( $post->post_content ) ) {
			return [ 'success' => false, 'error' => 'Post content is empty, skipping image generation.' ];
		}

		$overwrite = ! empty( $args['overwrite'] ) || ! empty( $settings['overwrite_existing_images'] );
		$existing_generated = array_filter( array_map( 'absint', (array) get_post_meta( $post_id, '_srt_generated_image_ids', true ) ) );
		if ( ! $overwrite && ( has_post_thumbnail( $post_id ) || ! empty( $existing_generated ) ) ) {
			return [ 'success' => true, 'skipped' => true, 'message' => 'Existing images kept because overwrite is disabled.' ];
		}

		$queue_id = (int) ( $args['queue_id'] ?? 0 );
		self::update_queue_status( $queue_id, 'generating' );

		$post_context = [
			'post_title'   => (string) get_the_title( $post_id ),
			'post_excerpt' => wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 38, '...' ),
			'topic'        => (string) get_post_meta( $post_id, '_srt_topic', true ),
		];

		$prompt = ! empty( $args['prompt'] )
			? sanitize_textarea_field( (string) $args['prompt'] )
			: self::image_prompt( $route, $post_id, $post_context, $settings );
		$query = sprintf( '%s %s mekong delta vietnam taxi travel', (string) ( $route['from_city'] ?? '' ), (string) ( $route['to_city'] ?? '' ) );

		LogRepository::add( 'info', 'image_prompt_generated', 'Image prompt prepared.', [ 'route_id' => $route_id, 'post_id' => $post_id, 'provider' => '', 'prompt_hash' => hash( 'sha256', $prompt ) ] );

		$candidates = self::collect_candidates( $prompt, $query, $count, $settings, $args );
		if ( empty( $candidates ) ) {
			self::update_queue_status( $queue_id, 'failed', 'No image candidates returned.' );
			RouteRepository::update_generation_meta( $route_id, [ 'ai_status' => 'image_failed', 'ai_error' => 'No image candidates returned.' ] );
			LogRepository::add( 'warning', 'image_candidates_empty', 'No image candidates returned for post.', [ 'route_id' => $route_id, 'post_id' => $post_id ] );
			return [ 'success' => false, 'error' => 'No image candidates returned.' ];
		}

		self::update_queue_status( $queue_id, 'downloading' );
		$uploaded = [];
		foreach ( array_slice( $candidates, 0, $count ) as $index => $candidate ) {
			$alt = SRT_Image_Prompt_Builder::build_alt_text( $route, $post_context, (string) $settings['alt_text_mode'], $index );
			$filename = SRT_Image_Prompt_Builder::seo_filename( $route, $index, self::candidate_extension( $candidate ) );
			$result = MediaUploader::import_generated_image(
				$candidate,
				$post_id,
				[
					'alt'          => $alt,
					'filename'     => $filename,
					'title'        => (string) $post->post_title,
					'caption'      => self::candidate_caption( $candidate, $settings ),
					'description'  => (string) ( $candidate['description'] ?? '' ),
					'credit'       => (string) ( $candidate['credit'] ?? '' ),
					'prompt'       => $prompt,
					'route_slug'   => (string) ( $route['slug'] ?? '' ),
					'source'       => (string) ( $candidate['source'] ?? 'unknown' ),
					'post_id'      => $post_id,
				]
			);
			if ( empty( $result['success'] ) ) {
				LogRepository::add( 'warning', 'image_upload_failed', (string) ( $result['error'] ?? 'Media upload failed.' ), [ 'route_id' => $route_id, 'post_id' => $post_id, 'provider' => (string) ( $candidate['source'] ?? '' ) ] );
				continue;
			}
			LogRepository::add( 'info', 'image_upload_success', 'Image uploaded to Media Library.', [ 'route_id' => $route_id, 'post_id' => $post_id, 'provider' => (string) ( $candidate['source'] ?? '' ), 'attachment_id' => (int) $result['attachment_id'] ] );

			$uploaded[] = [
				'attachment_id' => (int) $result['attachment_id'],
				'src'           => (string) wp_get_attachment_url( (int) $result['attachment_id'] ),
				'alt'           => $alt,
				'caption'       => (string) ( $result['caption'] ?? '' ),
				'source'        => (string) ( $candidate['source'] ?? '' ),
			];
		}

		if ( empty( $uploaded ) ) {
			self::update_queue_status( $queue_id, 'failed', 'No images uploaded.' );
			RouteRepository::update_generation_meta( $route_id, [ 'ai_status' => 'image_failed', 'ai_error' => 'No images uploaded.' ] );
			return [ 'success' => false, 'error' => 'No images uploaded.' ];
		}

		self::update_queue_status( $queue_id, 'attaching' );
		$featured_id = self::apply_featured_image( $post_id, $route_id, $uploaded, $settings );
		$content_updated = false;
		if ( ! empty( $settings['insert_images_into_content'] ) ) {
			$content_updated = self::insert_into_post_content( $post, $uploaded, $settings );
		}

		$attachment_ids = array_values( array_map( static fn( array $item ): int => (int) $item['attachment_id'], $uploaded ) );
		update_post_meta( $post_id, '_srt_generated_image_ids', $attachment_ids );
		update_post_meta( $post_id, '_srt_featured_image_id', $featured_id );
		update_post_meta( $post_id, '_srt_image_source_mode', (string) $settings['image_source_mode'] );
		if ( ! empty( $settings['save_image_prompt_to_meta'] ) ) {
			update_post_meta( $post_id, '_srt_image_prompt', $prompt );
		}

		RouteRepository::update_generation_meta(
			$route_id,
			[
				'image_id'   => $featured_id,
				'ai_status'  => 'image_completed',
				'ai_error'   => '',
			]
		);

		if ( $featured_id > 0 ) {
			LogRepository::add( 'info', 'featured_image_set', 'Featured image assigned.', [ 'route_id' => $route_id, 'post_id' => $post_id, 'attachment_id' => $featured_id ] );
		}
		if ( $content_updated ) {
			LogRepository::add( 'info', 'content_images_inserted', 'Content images inserted into article.', [ 'route_id' => $route_id, 'post_id' => $post_id ] );
		}

		self::update_queue_status( $queue_id, 'completed' );
		return [
			'success'        => true,
			'attachment_ids' => $attachment_ids,
			'featured_id'    => $featured_id,
			'content_updated'=> $content_updated,
			'prompt'         => $prompt,
		];
	}

	public static function preview_candidates( int $route_id, int $post_id = 0, array $args = [] ): array {
		$route = RouteRepository::get_by_id( $route_id );
		if ( ! $route ) {
			return [ 'success' => false, 'error' => 'Route not found.' ];
		}
		$settings = self::resolve_settings( $args );
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		$post_context = [
			'post_title'   => $post ? (string) $post->post_title : sprintf( 'Taxi %s di %s', (string) ( $route['from_city'] ?? '' ), (string) ( $route['to_city'] ?? '' ) ),
			'post_excerpt' => $post ? wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 38, '...' ) : '',
			'topic'        => $post_id > 0 ? (string) get_post_meta( $post_id, '_srt_topic', true ) : 'route_landing',
		];
		$prompt = ! empty( $args['prompt'] ) ? sanitize_textarea_field( (string) $args['prompt'] ) : self::image_prompt( $route, $post_id, $post_context, $settings );
		$query  = sprintf( '%s %s mekong delta vietnam taxi travel', (string) ( $route['from_city'] ?? '' ), (string) ( $route['to_city'] ?? '' ) );
		$count  = self::image_count( $settings, $args );
		return [
			'success'    => true,
			'prompt'     => $prompt,
			'candidates' => self::collect_candidates( $prompt, $query, max( 1, $count ), $settings, $args ),
		];
	}

	public static function search_stock_images( string $provider_key, string $query, array $args = [] ): array {
		$provider = self::provider( $provider_key );
		if ( ! $provider || ! $provider->supports_search() ) {
			return [ 'success' => false, 'images' => [], 'error' => 'Provider does not support stock search.' ];
		}
		return $provider->search_images( $query, $args );
	}

	public static function test_source( string $provider_key ): array {
		$provider = self::provider( $provider_key );
		return $provider ? $provider->test_connection() : [ 'success' => false, 'error' => 'Provider not found.' ];
	}

	private static function collect_candidates( string $prompt, string $query, int $count, array $settings, array $args = [] ): array {
		if ( ! empty( $args['external_url'] ) ) {
			return [
				[
					'url'         => esc_url_raw( (string) $args['external_url'] ),
					'source'      => 'external',
					'credit'      => '',
					'caption'     => '',
					'description' => 'Externally supplied image.',
				],
			];
		}
		$providers = self::provider_order( $settings );
		$images    = [];
		foreach ( $providers as $provider_key ) {
			$provider = self::provider( $provider_key );
			if ( ! $provider ) {
				continue;
			}
			LogRepository::add( 'info', 'image_provider_selected', 'Image provider selected.', [ 'provider' => $provider_key ] );

			$result = $provider->supports_generation()
				? $provider->generate_images(
					$prompt,
					[
						'count'           => $count,
						'size'            => (string) $settings['image_size'],
						'quality'         => (string) ( $settings['image_quality'] ?? 'standard' ),
						'style'           => (string) ( $settings['image_style_preset'] ?? '' ),
						'response_format' => (string) ( $settings['image_response_format'] ?? 'auto' ),
					]
				)
				: $provider->search_images( $query, [ 'count' => $count ] );

			if ( empty( $result['success'] ) ) {
				LogRepository::add( 'warning', 'image_provider_failed', (string) ( $result['error'] ?? 'Image provider request failed.' ), [ 'provider' => $provider_key ] );
				continue;
			}
			LogRepository::add( 'info', 'image_provider_success', 'Image provider returned candidates.', [ 'provider' => $provider_key, 'count' => count( (array) ( $result['images'] ?? [] ) ) ] );

			foreach ( (array) ( $result['images'] ?? [] ) as $image ) {
				if ( ! empty( $image['url'] ) ) {
					LogRepository::add( 'info', 'image_url_received', 'Image URL received from provider.', [ 'provider' => $provider_key, 'url_hash' => hash( 'sha256', (string) $image['url'] ) ] );
				}
				$images[] = $image;
				if ( count( $images ) >= $count ) {
					break 2;
				}
			}
		}
		return $images;
	}

	private static function apply_featured_image( int $post_id, int $route_id, array $uploaded, array $settings ): int {
		$mode = (string) ( $settings['featured_image_mode'] ?? 'first_generated' );
		if ( in_array( $mode, [ 'disabled', 'manual_only' ], true ) ) {
			return 0;
		}

		$featured = $uploaded[0] ?? [];
		$attachment_id = (int) ( $featured['attachment_id'] ?? 0 );
		if ( $attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $attachment_id );
			RouteRepository::update_generation_meta( $route_id, [ 'image_id' => $attachment_id ] );
		}
		return $attachment_id;
	}

	private static function insert_into_post_content( \WP_Post $post, array $uploaded, array $settings ): bool {
		$extra = $uploaded;
		if ( count( $extra ) > 1 ) {
			array_shift( $extra );
		}
		if ( empty( $extra ) && ! empty( $uploaded ) && 1 === (int) self::image_count( $settings ) ) {
			$extra = [ $uploaded[0] ];
		}
		if ( empty( $extra ) ) {
			return false;
		}
		$content = SRT_Content_Image_Inserter::insert( (string) $post->post_content, $extra, $settings );
		if ( $content === $post->post_content ) {
			return false;
		}
		$result = wp_update_post(
			[
				'ID'           => (int) $post->ID,
				'post_content' => wp_kses_post( $content ),
			],
			true
		);
		return ! is_wp_error( $result );
	}

	private static function image_prompt( array $route, int $post_id, array $post_context, array $settings ): string {
		$stored = $post_id > 0 ? (string) get_post_meta( $post_id, '_srt_featured_image_prompt', true ) : '';
		return '' !== trim( $stored ) ? $stored : SRT_Image_Prompt_Builder::build( $route, $post_context, $settings );
	}

	private static function resolve_settings( array $args = [] ): array {
		$settings = ImageProviderRegistry::get();
		$runtime  = AIRuntimeConfig::get();
		$merged = wp_parse_args(
			$args,
			[
				'image_source_mode'        => self::normalize_source_mode( (string) ( $settings['image_source_strategy'] ?? 'disabled' ) ),
				'image_count'              => self::image_count( $settings ),
				'featured_image_mode'      => self::normalize_featured_mode( (string) ( $settings['featured_image_mode'] ?? 'first_generated' ) ),
				'insert_images_into_content' => ! empty( $settings['insert_images_into_content'] ) ? 1 : 0,
				'image_placement'          => (string) ( $settings['image_placement'] ?? 'after_intro' ),
				'image_heading_interval'   => (int) ( $settings['image_heading_interval'] ?? 2 ),
				'image_size'               => (string) ( $settings['image_size'] ?? '1024x576' ),
				'image_style'              => 'custom' === (string) ( $settings['image_style'] ?? 'realistic' ) ? (string) ( $settings['image_style_custom'] ?? 'realistic' ) : (string) ( $settings['image_style'] ?? 'realistic' ),
				'image_quality'            => 'standard',
				'image_style_preset'       => '',
				'image_response_format'    => 'auto',
				'alt_text_mode'            => (string) ( $settings['alt_text_mode'] ?? 'route-based' ),
				'overwrite_existing_images' => ! empty( $settings['overwrite_existing_images'] ) ? 1 : 0,
				'save_image_prompt_to_meta'=> ! empty( $settings['save_image_prompt_to_meta'] ) ? 1 : 0,
				'enable_image'             => ! empty( $runtime['enable_image_generation'] ) ? 1 : 0,
			]
		);
		if ( 'custom' === (string) ( $settings['image_size'] ?? '' ) && ! empty( $settings['image_size_custom'] ) ) {
			$merged['image_size'] = (string) $settings['image_size_custom'];
		}
		return $merged;
	}

	private static function image_count( array $settings, array $args = [] ): int {
		$count = isset( $args['image_count'] ) ? (int) $args['image_count'] : (int) ( $settings['images_per_post'] ?? 1 );
		if ( 'custom' === (string) ( $settings['images_per_post'] ?? '' ) ) {
			$count = (int) ( $settings['images_per_post_custom'] ?? $count );
		}
		return max( 0, min( 8, $count ) );
	}

	private static function provider_order( array $settings ): array {
		$mode      = (string) ( $settings['image_source_mode'] ?? 'disabled' );
		$source_settings = SRT_Image_Source_Config::get();
		$priority  = array_values(
			array_filter(
				(array) ( $source_settings['source_priority'] ?? [] ),
				static function ( string $item ) use ( $source_settings ): bool {
					switch ( $item ) {
						case 'unsplash':
							return ! empty( $source_settings['unsplash_enabled'] );
						case 'pexels':
							return ! empty( $source_settings['pexels_enabled'] );
						case 'pixabay':
							return ! empty( $source_settings['pixabay_enabled'] );
						default:
							return true;
					}
				}
			)
		);
		if ( empty( $priority ) ) {
			$priority = [ 'placeholder' ];
		}

		switch ( $mode ) {
			case 'ai_generated':
				return [ 'ai', 'placeholder' ];
			case 'free_stock':
				return array_values( array_filter( $priority, static fn( string $item ): bool => 'ai' !== $item ) );
			case 'mixed_ai_first':
				return $priority;
			case 'mixed_stock_first':
				$without_ai = array_values( array_filter( $priority, static fn( string $item ): bool => 'ai' !== $item ) );
				$without_ai[] = 'ai';
				return array_values( array_unique( $without_ai ) );
			default:
				return [];
		}
	}

	private static function provider( string $provider_key ): ?SRT_Image_Provider_Interface {
		switch ( sanitize_key( $provider_key ) ) {
			case 'ai':
				return new SRT_AI_Image_Provider();
			case 'unsplash':
				return new SRT_Unsplash_Provider();
			case 'pexels':
				return new SRT_Pexels_Provider();
			case 'pixabay':
				return new SRT_Pixabay_Provider();
			case 'placeholder':
				return new SRT_Placeholder_Provider();
		}

		return null;
	}

	private static function update_queue_status( int $queue_id, string $status, string $error = '' ): void {
		if ( $queue_id <= 0 ) {
			return;
		}
		QueueRepository::update_status( $queue_id, $status, $error );
	}

	private static function candidate_caption( array $candidate, array $settings ): string {
		$caption = (string) ( $candidate['caption'] ?? '' );
		$source  = (string) ( $candidate['source'] ?? '' );
		if ( 'unsplash' === $source && 'none' === (string) ( SRT_Image_Source_Config::get()['unsplash_credit_mode'] ?? 'caption' ) ) {
			return '';
		}
		return $caption;
	}

	private static function candidate_extension( array $candidate ): string {
		$url = (string) ( $candidate['url'] ?? '' );
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$ext  = pathinfo( $path, PATHINFO_EXTENSION );
		if ( '' === $ext && ! empty( $candidate['local_file'] ) ) {
			$ext = pathinfo( (string) $candidate['local_file'], PATHINFO_EXTENSION );
		}
		return '' !== $ext ? $ext : 'jpg';
	}

	private static function normalize_source_mode( string $strategy ): string {
		$map = [
			'disabled'                 => 'disabled',
			'ai_only'                  => 'ai_generated',
			'stock_only'               => 'free_stock',
			'ai_first_stock_fallback'  => 'mixed_ai_first',
			'stock_first_ai_fallback'  => 'mixed_stock_first',
			'mixed_rotation'           => 'mixed_ai_first',
		];
		return $map[ $strategy ] ?? 'disabled';
	}

	private static function normalize_featured_mode( string $mode ): string {
		$map = [
			'none'            => 'disabled',
			'first_generated' => 'first_generated',
			'best_matched'    => 'best_matched',
		];
		return $map[ $mode ] ?? 'first_generated';
	}
}
