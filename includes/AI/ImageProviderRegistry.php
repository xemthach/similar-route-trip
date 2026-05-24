<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class ImageProviderRegistry {
	public const OPTION = 'srt_image_provider_registry';

	public static function defaults(): array {
		return [
			'active_provider_id'         => '',
			'image_source_strategy'      => 'disabled',
			'images_per_post'            => 1,
			'images_per_post_custom'     => 1,
			'featured_image_mode'        => 'first_generated',
			'insert_images_into_content' => 0,
			'image_placement'            => 'after_intro',
			'image_heading_interval'     => 2,
			'image_size'                 => '1024x576',
			'image_size_custom'          => '',
			'image_style'                => 'realistic',
			'image_style_custom'         => '',
			'alt_text_mode'              => 'route-based',
			'overwrite_existing_images'  => 0,
			'save_image_prompt_to_meta'  => 1,
			'providers'                  => [],
		];
	}

	public static function get(): array {
		self::maybe_migrate();
		$settings = get_option( self::OPTION, [] );
		$settings = wp_parse_args( is_array( $settings ) ? $settings : [], self::defaults() );
		$settings['providers'] = self::sanitize_provider_rows( $settings['providers'] ?? [], self::existing_by_id( $settings['providers'] ?? [] ) );
		$settings['image_source_strategy'] = self::sanitize_enum( (string) ( $settings['image_source_strategy'] ?? 'disabled' ), [ 'disabled', 'ai_only', 'stock_only', 'ai_first_stock_fallback', 'stock_first_ai_fallback', 'mixed_rotation' ], 'disabled' );
		$settings['featured_image_mode'] = self::sanitize_enum( (string) ( $settings['featured_image_mode'] ?? 'first_generated' ), [ 'none', 'first_generated', 'best_matched' ], 'first_generated' );
		$settings['image_placement'] = self::sanitize_enum( (string) ( $settings['image_placement'] ?? 'after_intro' ), [ 'after_intro', 'before_first_h2', 'after_every_n_headings', 'end_of_article', 'shortcode_placeholder' ], 'after_intro' );
		$settings['image_size'] = self::sanitize_enum( (string) ( $settings['image_size'] ?? '1024x576' ), [ '1024x576', '1200x675', '1024x1024', 'custom' ], '1024x576' );
		$settings['image_style'] = self::sanitize_enum( (string) ( $settings['image_style'] ?? 'realistic' ), [ 'realistic', 'local_travel', 'taxi_service', 'documentary', 'clean_banner', 'custom' ], 'realistic' );
		$settings['alt_text_mode'] = self::sanitize_enum( (string) ( $settings['alt_text_mode'] ?? 'route-based' ), [ 'ai-generated', 'route-based', 'title-based' ], 'route-based' );
		return $settings;
	}

	public static function providers( bool $enabled_only = false, bool $decrypt = false ): array {
		$settings = self::get();
		$rows     = [];
		foreach ( (array) ( $settings['providers'] ?? [] ) as $row ) {
			if ( $enabled_only && empty( $row['enabled'] ) ) {
				continue;
			}
			if ( 'use_content_provider_key' !== (string) ( $row['provider_mode'] ?? '' ) && $decrypt ) {
				$row['api_key_plain'] = AIKeyVault::decrypt( (string) ( $row['api_key'] ?? '' ) );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	public static function get_provider( string $id, bool $decrypt = false ): ?array {
		foreach ( self::providers( false, $decrypt ) as $provider ) {
			if ( (string) ( $provider['id'] ?? '' ) === $id ) {
				return $provider;
			}
		}
		return null;
	}

	public static function save( array $input ): void {
		$current = self::get();
		$data    = [
			'active_provider_id'         => sanitize_key( (string) ( $input['active_provider_id'] ?? $current['active_provider_id'] ?? '' ) ),
			'image_source_strategy'      => self::sanitize_enum( (string) ( $input['image_source_strategy'] ?? $current['image_source_strategy'] ?? 'disabled' ), [ 'disabled', 'ai_only', 'stock_only', 'ai_first_stock_fallback', 'stock_first_ai_fallback', 'mixed_rotation' ], 'disabled' ),
			'images_per_post'            => self::sanitize_image_count_option( $input['images_per_post'] ?? $current['images_per_post'] ?? 1 ),
			'images_per_post_custom'     => max( 0, min( 8, absint( $input['images_per_post_custom'] ?? $current['images_per_post_custom'] ?? 1 ) ) ),
			'featured_image_mode'        => self::sanitize_enum( (string) ( $input['featured_image_mode'] ?? $current['featured_image_mode'] ?? 'first_generated' ), [ 'none', 'first_generated', 'best_matched' ], 'first_generated' ),
			'insert_images_into_content' => ! empty( $input['insert_images_into_content'] ) ? 1 : 0,
			'image_placement'            => self::sanitize_enum( (string) ( $input['image_placement'] ?? $current['image_placement'] ?? 'after_intro' ), [ 'after_intro', 'before_first_h2', 'after_every_n_headings', 'end_of_article', 'shortcode_placeholder' ], 'after_intro' ),
			'image_heading_interval'     => max( 1, min( 10, absint( $input['image_heading_interval'] ?? $current['image_heading_interval'] ?? 2 ) ) ),
			'image_size'                 => self::sanitize_enum( (string) ( $input['image_size'] ?? $current['image_size'] ?? '1024x576' ), [ '1024x576', '1200x675', '1024x1024', 'custom' ], '1024x576' ),
			'image_size_custom'          => sanitize_text_field( (string) ( $input['image_size_custom'] ?? $current['image_size_custom'] ?? '' ) ),
			'image_style'                => self::sanitize_enum( (string) ( $input['image_style'] ?? $current['image_style'] ?? 'realistic' ), [ 'realistic', 'local_travel', 'taxi_service', 'documentary', 'clean_banner', 'custom' ], 'realistic' ),
			'image_style_custom'         => sanitize_text_field( (string) ( $input['image_style_custom'] ?? $current['image_style_custom'] ?? '' ) ),
			'alt_text_mode'              => self::sanitize_enum( (string) ( $input['alt_text_mode'] ?? $current['alt_text_mode'] ?? 'route-based' ), [ 'ai-generated', 'route-based', 'title-based' ], 'route-based' ),
			'overwrite_existing_images'  => ! empty( $input['overwrite_existing_images'] ) ? 1 : 0,
			'save_image_prompt_to_meta'  => ! empty( $input['save_image_prompt_to_meta'] ) ? 1 : 0,
			'providers'                  => self::sanitize_provider_rows( $input['providers'] ?? [], self::existing_by_id( $current['providers'] ?? [] ) ),
		];
		update_option( self::OPTION, $data, false );
	}

	public static function update_provider_status( string $id, array $status ): void {
		$settings = self::get();
		$rows     = (array) ( $settings['providers'] ?? [] );
		foreach ( $rows as &$row ) {
			if ( (string) ( $row['id'] ?? '' ) !== $id ) {
				continue;
			}
			$row['last_status']  = ! empty( $status['success'] ) ? 'ok' : 'error';
			$row['last_message'] = sanitize_text_field( (string) ( $status['message'] ?? $status['error'] ?? '' ) );
			$row['last_checked'] = current_time( 'mysql' );
			break;
		}
		unset( $row );
		$settings['providers'] = $rows;
		update_option( self::OPTION, $settings, false );
	}

	public static function maybe_migrate(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}

		$legacy            = AIConfig::get();
		$content_providers = ContentProviderRegistry::providers( false, false );
		$providers         = [];

		foreach ( $content_providers as $content_provider ) {
			if ( empty( $content_provider['supports_shared_image'] ) || empty( $content_provider['shared_image_model'] ) ) {
				continue;
			}
			$providers[] = [
				'id'                   => 'img_' . sanitize_key( (string) ( $content_provider['id'] ?? '' ) ),
				'label'                => 'Shared key: ' . sanitize_text_field( (string) ( $content_provider['label'] ?? '' ) ),
				'provider_mode'        => 'use_content_provider_key',
				'provider_type'        => sanitize_text_field( (string) ( $content_provider['provider_type'] ?? 'openai_compatible' ) ),
				'content_provider_id'  => sanitize_key( (string) ( $content_provider['id'] ?? '' ) ),
				'base_url'             => '',
				'api_key'              => '',
				'image_endpoint'       => sanitize_text_field( (string) ( $content_provider['shared_image_endpoint'] ?? '/images/generations' ) ),
				'image_edit_endpoint'  => sanitize_text_field( (string) ( $content_provider['shared_image_edit_endpoint'] ?? '/images/edits' ) ),
				'image_api_format'     => sanitize_text_field( (string) ( $content_provider['shared_image_api_format'] ?? 'openai_images' ) ),
				'image_model'          => sanitize_text_field( (string) ( $content_provider['shared_image_model'] ?? '' ) ),
				'response_type'        => sanitize_text_field( (string) ( $content_provider['shared_image_response_format'] ?? 'auto' ) ),
				'default_size'         => sanitize_text_field( (string) ( $legacy['image_size'] ?? '1024x576' ) ),
				'quality'              => sanitize_text_field( (string) ( $content_provider['shared_image_quality'] ?? 'standard' ) ),
				'style_preset'         => sanitize_text_field( (string) ( $content_provider['shared_image_style_preset'] ?? '' ) ),
				'enabled'              => ! empty( $content_provider['enabled'] ) ? 1 : 0,
				'priority'             => max( 1, min( 100, (int) ( $content_provider['priority'] ?? 10 ) ) ),
				'weight'               => max( 1, min( 100, (int) ( $content_provider['weight'] ?? 1 ) ) ),
				'daily_limit'          => 0,
				'cooldown_after_error' => 15,
				'last_status'          => sanitize_key( (string) ( $content_provider['last_status'] ?? '' ) ),
				'last_message'         => sanitize_text_field( (string) ( $content_provider['last_message'] ?? '' ) ),
				'last_checked'         => sanitize_text_field( (string) ( $content_provider['last_checked'] ?? '' ) ),
			];
		}

		update_option(
			self::OPTION,
			wp_parse_args(
				[
					'image_source_strategy'      => self::map_legacy_strategy( (string) ( $legacy['image_source_mode'] ?? 'disabled' ) ),
					'images_per_post'            => $legacy['images_per_post'] ?? 1,
					'images_per_post_custom'     => $legacy['images_per_post_custom'] ?? 1,
					'featured_image_mode'        => self::map_legacy_featured_mode( (string) ( $legacy['featured_image_mode'] ?? 'first_generated' ) ),
					'insert_images_into_content' => ! empty( $legacy['insert_images_into_content'] ) ? 1 : 0,
					'image_placement'            => (string) ( $legacy['image_placement'] ?? 'after_intro' ),
					'image_heading_interval'     => (int) ( $legacy['image_heading_interval'] ?? 2 ),
					'image_size'                 => (string) ( $legacy['image_size'] ?? '1024x576' ),
					'image_size_custom'          => (string) ( $legacy['image_size_custom'] ?? '' ),
					'image_style'                => (string) ( $legacy['image_style'] ?? 'realistic' ),
					'image_style_custom'         => (string) ( $legacy['image_style_custom'] ?? '' ),
					'alt_text_mode'              => (string) ( $legacy['alt_text_mode'] ?? 'route-based' ),
					'overwrite_existing_images'  => ! empty( $legacy['overwrite_existing_images'] ) ? 1 : 0,
					'save_image_prompt_to_meta'  => ! empty( $legacy['save_image_prompt_to_meta'] ) ? 1 : 0,
					'providers'                  => $providers,
				],
				self::defaults()
			),
			false
		);
	}

	private static function sanitize_provider_rows( $rows, array $current_by_id ): array {
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$providers = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = sanitize_key( substr( md5( wp_json_encode( $row ) . microtime( true ) . wp_rand() ), 0, 12 ) );
			}
			$current = $current_by_id[ $id ] ?? [];
			$mode    = self::sanitize_enum( (string) ( $row['provider_mode'] ?? $current['provider_mode'] ?? 'use_content_provider_key' ), [ 'use_content_provider_key', 'own' ], 'use_content_provider_key' );
			$label   = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$raw_key = trim( (string) ( $row['api_key'] ?? '' ) );
			$providers[] = [
				'id'                   => $id,
				'label'                => '' !== $label ? $label : $id,
				'provider_mode'        => $mode,
				'provider_type'        => self::sanitize_provider_type( (string) ( $row['provider_type'] ?? $current['provider_type'] ?? 'shopaikey_compatible' ) ),
				'content_provider_id'  => sanitize_key( (string) ( $row['content_provider_id'] ?? $current['content_provider_id'] ?? '' ) ),
				'base_url'             => 'own' === $mode ? esc_url_raw( (string) ( $row['base_url'] ?? $current['base_url'] ?? '' ) ) : '',
				'api_key'              => 'own' === $mode ? self::sanitize_secret( $raw_key, (string) ( $current['api_key'] ?? '' ) ) : (string) ( $current['api_key'] ?? '' ),
				'image_endpoint'       => self::sanitize_path_or_url( $row['image_endpoint'] ?? ( $current['image_endpoint'] ?? '/images/generations' ), '/images/generations' ),
				'image_edit_endpoint'  => self::sanitize_path_or_url( $row['image_edit_endpoint'] ?? ( $current['image_edit_endpoint'] ?? '/images/edits' ), '/images/edits' ),
				'image_api_format'     => self::sanitize_enum( (string) ( $row['image_api_format'] ?? $current['image_api_format'] ?? 'openai_images' ), [ 'openai_images', 'google_genai_image' ], 'openai_images' ),
				'image_model'          => sanitize_text_field( (string) ( $row['image_model'] ?? $current['image_model'] ?? '' ) ),
				'response_type'        => self::sanitize_enum( (string) ( $row['response_type'] ?? $current['response_type'] ?? 'auto' ), [ 'auto', 'url', 'base64', 'b64_json' ], 'auto' ),
				'default_size'         => sanitize_text_field( (string) ( $row['default_size'] ?? $current['default_size'] ?? '1024x576' ) ),
				'quality'              => sanitize_text_field( (string) ( $row['quality'] ?? $current['quality'] ?? 'standard' ) ),
				'style_preset'         => sanitize_text_field( (string) ( $row['style_preset'] ?? $current['style_preset'] ?? '' ) ),
				'enabled'              => ! empty( $row['enabled'] ) ? 1 : 0,
				'priority'             => max( 1, min( 100, (int) ( $row['priority'] ?? $current['priority'] ?? 10 ) ) ),
				'weight'               => max( 1, min( 100, (int) ( $row['weight'] ?? $current['weight'] ?? 1 ) ) ),
				'daily_limit'          => max( 0, (int) ( $row['daily_limit'] ?? $current['daily_limit'] ?? 0 ) ),
				'cooldown_after_error' => max( 0, min( 1440, (int) ( $row['cooldown_after_error'] ?? $current['cooldown_after_error'] ?? 15 ) ) ),
				'last_status'          => sanitize_key( (string) ( $current['last_status'] ?? '' ) ),
				'last_message'         => sanitize_text_field( (string) ( $current['last_message'] ?? '' ) ),
				'last_checked'         => sanitize_text_field( (string) ( $current['last_checked'] ?? '' ) ),
			];
		}
		return $providers;
	}

	private static function existing_by_id( $rows ): array {
		$map = [];
		if ( ! is_array( $rows ) ) {
			return $map;
		}
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$map[ (string) $row['id'] ] = $row;
			}
		}
		return $map;
	}

	private static function sanitize_provider_type( string $value ): string {
		$allowed = [ 'shopaikey_compatible', 'openai_compatible', 'gemini_compatible', 'custom_openai_compatible', 'custom' ];
		return in_array( $value, $allowed, true ) ? $value : 'shopaikey_compatible';
	}

	private static function sanitize_enum( string $value, array $allowed, string $default ): string {
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private static function sanitize_path_or_url( $value, string $default ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return $default;
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		$value = '/' . ltrim( $value, '/' );
		return preg_replace( '#/{2,}#', '/', $value ) ?: $default;
	}

	private static function map_legacy_strategy( string $mode ): string {
		$map = [
			'disabled'         => 'disabled',
			'ai_generated'     => 'ai_only',
			'free_stock'       => 'stock_only',
			'mixed_ai_first'   => 'ai_first_stock_fallback',
			'mixed_stock_first'=> 'stock_first_ai_fallback',
		];
		return $map[ $mode ] ?? 'disabled';
	}

	private static function map_legacy_featured_mode( string $mode ): string {
		$map = [
			'disabled'        => 'none',
			'manual_only'     => 'none',
			'best_matched'    => 'best_matched',
			'first_generated' => 'first_generated',
		];
		return $map[ $mode ] ?? 'first_generated';
	}

	private static function sanitize_image_count_option( $value ) {
		if ( 'custom' === $value ) {
			return 'custom';
		}
		$allowed = [ 0, 1, 2, 3, 5 ];
		$value   = absint( $value );
		return in_array( $value, $allowed, true ) ? $value : 1;
	}

	private static function sanitize_secret( string $raw, string $current ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return $current;
		}
		if ( $raw === $current ) {
			return $current;
		}
		if ( '' !== AIKeyVault::decrypt( $raw ) ) {
			return $raw;
		}
		return AIKeyVault::encrypt( $raw );
	}
}
