<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class AIConfig {
	public const OPTION = 'srt_ai_settings';

	public static function defaults(): array {
		return [
			'mode'                   => 'disabled',
			'provider'               => 'openai_compatible',
			'base_url'               => 'https://api.shopaikey.com/v1',
			'api_key'                => '',
			'model_content'          => '',
			'model_image'            => '',
			'active_key_id'          => '',
			'selected_content_model' => '',
			'selected_image_model'   => '',
			'keys'                   => [],
			'temperature'            => 0.4,
			'max_tokens'             => 1800,
			'timeout'                => 45,
			'enable_content'         => 0,
			'enable_image'           => 0,
			'enable_auto_post'       => 0,
			'enable_featured_image'  => 0,
			'image_source_mode'      => 'disabled',
			'images_per_post'        => 1,
			'images_per_post_custom' => 1,
			'featured_image_mode'    => 'first_generated',
			'insert_images_into_content' => 0,
			'image_placement'        => 'after_intro',
			'image_heading_interval' => 2,
			'image_size'             => '1024x576',
			'image_size_custom'      => '',
			'image_style'            => 'realistic',
			'image_style_custom'     => '',
			'image_quality'          => 'standard',
			'image_style_preset'     => '',
			'image_response_format'  => 'auto',
			'image_endpoint'         => '/images/generations',
			'image_edit_endpoint'    => '/images/edits',
			'image_api_format'       => 'openai_images',
			'alt_text_mode'          => 'route-based',
			'overwrite_existing_images' => 0,
			'save_image_prompt_to_meta' => 1,
		];
	}

	public static function get(): array {
		$settings = get_option( self::OPTION, [] );
		$settings = wp_parse_args( is_array( $settings ) ? $settings : [], self::defaults() );
		foreach ( self::defaults() as $key => $default ) {
			if ( null === $settings[ $key ] ) {
				$settings[ $key ] = $default;
			}
		}
		if ( ! in_array( $settings['mode'], [ 'disabled', 'own', 'ai_commerce_agent' ], true ) ) {
			$settings['mode'] = 'disabled';
		}
		if ( ! in_array( $settings['provider'], [ 'openai_compatible', 'shopaikey_compatible', 'gemini_compatible', 'custom_openai_compatible' ], true ) ) {
			$settings['provider'] = 'openai_compatible';
		}
		$settings['model_content'] = self::normalize_model_field( $settings['model_content'] ?? '' );
		$settings['model_image'] = self::normalize_model_field( $settings['model_image'] ?? '' );
		$settings['selected_content_model'] = is_scalar( $settings['selected_content_model'] ?? '' ) ? sanitize_text_field( (string) $settings['selected_content_model'] ) : '';
		$settings['selected_image_model'] = is_scalar( $settings['selected_image_model'] ?? '' ) ? sanitize_text_field( (string) $settings['selected_image_model'] ) : '';
		return $settings;
	}

	public static function save( array $input ): void {
		$current = self::get();
		$api_key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		$data    = [
			'mode'                  => in_array( $input['mode'] ?? 'disabled', [ 'disabled', 'own', 'ai_commerce_agent' ], true ) ? $input['mode'] : 'disabled',
			'provider'              => in_array( $input['provider'] ?? 'openai_compatible', [ 'openai_compatible', 'shopaikey_compatible', 'gemini_compatible', 'custom_openai_compatible' ], true ) ? $input['provider'] : 'openai_compatible',
			'base_url'              => esc_url_raw( (string) ( $input['base_url'] ?? '' ) ),
			'api_key'               => '' !== $api_key ? AIKeyVault::encrypt( $api_key ) : (string) ( $current['api_key'] ?? '' ),
			'model_content'         => self::normalize_model_field( $input['model_content'] ?? '' ),
			'model_image'           => self::normalize_model_field( $input['model_image'] ?? '' ),
			'active_key_id'         => sanitize_key( (string) ( $input['active_key_id'] ?? '' ) ),
			'selected_content_model' => sanitize_text_field( (string) ( $input['selected_content_model'] ?? '' ) ),
			'selected_image_model'  => sanitize_text_field( (string) ( $input['selected_image_model'] ?? '' ) ),
			'keys'                  => self::sanitize_keys( $input['keys'] ?? [], $current['keys'] ?? [] ),
			'temperature'           => max( 0, min( 2, (float) ( $input['temperature'] ?? 0.4 ) ) ),
			'max_tokens'            => max( 100, min( 16000, (int) ( $input['max_tokens'] ?? 1800 ) ) ),
			'timeout'               => max( 5, min( 180, (int) ( $input['timeout'] ?? 45 ) ) ),
			'enable_content'        => ! empty( $input['enable_content'] ) ? 1 : 0,
			'enable_image'          => ! empty( $input['enable_image'] ) ? 1 : 0,
			'enable_auto_post'      => ! empty( $input['enable_auto_post'] ) ? 1 : 0,
			'enable_featured_image' => ! empty( $input['enable_featured_image'] ) ? 1 : 0,
			'image_source_mode'     => self::sanitize_enum( (string) ( $input['image_source_mode'] ?? 'disabled' ), [ 'disabled', 'ai_generated', 'free_stock', 'mixed_ai_first', 'mixed_stock_first' ], 'disabled' ),
			'images_per_post'       => self::sanitize_image_count_option( $input['images_per_post'] ?? 1 ),
			'images_per_post_custom'=> max( 0, min( 8, absint( $input['images_per_post_custom'] ?? 1 ) ) ),
			'featured_image_mode'   => self::sanitize_enum( (string) ( $input['featured_image_mode'] ?? 'first_generated' ), [ 'first_generated', 'best_matched', 'manual_only', 'disabled' ], 'first_generated' ),
			'insert_images_into_content' => ! empty( $input['insert_images_into_content'] ) ? 1 : 0,
			'image_placement'       => self::sanitize_enum( (string) ( $input['image_placement'] ?? 'after_intro' ), [ 'after_intro', 'before_first_h2', 'after_every_n_headings', 'end_of_article', 'shortcode_placeholder' ], 'after_intro' ),
			'image_heading_interval' => max( 1, min( 10, absint( $input['image_heading_interval'] ?? 2 ) ) ),
			'image_size'            => self::sanitize_enum( (string) ( $input['image_size'] ?? '1024x576' ), [ '1024x576', '1200x675', '1024x1024', 'custom' ], '1024x576' ),
			'image_size_custom'     => sanitize_text_field( (string) ( $input['image_size_custom'] ?? '' ) ),
			'image_style'           => self::sanitize_enum( (string) ( $input['image_style'] ?? 'realistic' ), [ 'realistic', 'local_travel', 'taxi_service', 'documentary', 'clean_banner', 'custom' ], 'realistic' ),
			'image_style_custom'    => sanitize_text_field( (string) ( $input['image_style_custom'] ?? '' ) ),
			'image_quality'         => self::sanitize_enum( (string) ( $input['image_quality'] ?? 'standard' ), [ 'auto', 'standard', 'high', 'low' ], 'standard' ),
			'image_style_preset'    => sanitize_text_field( (string) ( $input['image_style_preset'] ?? '' ) ),
			'image_response_format' => self::sanitize_enum( (string) ( $input['image_response_format'] ?? 'auto' ), [ 'auto', 'url', 'b64_json' ], 'auto' ),
			'image_endpoint'        => self::sanitize_path_or_url( $input['image_endpoint'] ?? '/images/generations', '/images/generations' ),
			'image_edit_endpoint'   => self::sanitize_path_or_url( $input['image_edit_endpoint'] ?? '/images/edits', '/images/edits' ),
			'image_api_format'      => self::sanitize_enum( (string) ( $input['image_api_format'] ?? 'openai_images' ), [ 'openai_images', 'google_genai_image' ], 'openai_images' ),
			'alt_text_mode'         => self::sanitize_enum( (string) ( $input['alt_text_mode'] ?? 'route-based' ), [ 'ai-generated', 'route-based', 'title-based' ], 'route-based' ),
			'overwrite_existing_images' => ! empty( $input['overwrite_existing_images'] ) ? 1 : 0,
			'save_image_prompt_to_meta' => ! empty( $input['save_image_prompt_to_meta'] ) ? 1 : 0,
		];
		update_option( self::OPTION, $data, false );
		self::sync_new_runtime_config( $data );
		self::sync_new_provider_registries( $data );
	}

	public static function api_key(): string {
		return AIKeyVault::decrypt( (string) ( self::get()['api_key'] ?? '' ) );
	}

	public static function keys( bool $enabled_only = false, bool $decrypt = false ): array {
		$settings = self::get();
		$keys     = is_array( $settings['keys'] ?? null ) ? $settings['keys'] : [];

		if ( empty( $keys ) && ! empty( $settings['api_key'] ) ) {
			$keys[] = [
				'id'             => 'legacy',
				'label'          => 'Legacy key',
				'provider'       => $settings['provider'],
				'base_url'       => $settings['base_url'],
				'api_key'        => $settings['api_key'],
				'content_models' => self::split_models( (string) $settings['model_content'] ),
				'image_models'   => self::split_models( (string) $settings['model_image'] ),
				'image_endpoint' => (string) ( $settings['image_endpoint'] ?? '/images/generations' ),
				'image_edit_endpoint' => (string) ( $settings['image_edit_endpoint'] ?? '/images/edits' ),
				'image_api_format' => (string) ( $settings['image_api_format'] ?? 'openai_images' ),
				'enabled'        => 1,
				'priority'       => 10,
				'weight'         => 1,
			];
		}

		$clean = [];
		foreach ( $keys as $key ) {
			if ( $enabled_only && empty( $key['enabled'] ) ) {
				continue;
			}
			$key['api_key_plain'] = $decrypt ? AIKeyVault::decrypt( (string) ( $key['api_key'] ?? '' ) ) : '';
			$clean[] = $key;
		}

		usort(
			$clean,
			static fn( array $a, array $b ): int => (int) ( $a['priority'] ?? 10 ) <=> (int) ( $b['priority'] ?? 10 )
		);

		return $clean;
	}

	public static function active_provider_config( string $purpose = 'content' ): array {
		$settings = self::get();
		$keys     = self::keys( true, true );
		if ( empty( $keys ) ) {
			return $settings;
		}

		$active_id = (string) ( $settings['active_key_id'] ?? '' );
		$selected  = null;
		foreach ( $keys as $key ) {
			if ( '' !== $active_id && $active_id === (string) ( $key['id'] ?? '' ) ) {
				$selected = $key;
				break;
			}
		}
		if ( ! $selected ) {
			$pool     = self::weighted_pool( $keys );
			$selected = $keys[ $pool[ array_rand( $pool ) ] ] ?? $keys[0];
		}

		$model_list = 'image' === $purpose ? (array) ( $selected['image_models'] ?? [] ) : (array) ( $selected['content_models'] ?? [] );
		$model      = 'image' === $purpose ? (string) ( $settings['selected_image_model'] ?? '' ) : (string) ( $settings['selected_content_model'] ?? '' );
		if ( '' === $model || ! in_array( $model, $model_list, true ) ) {
			$model = (string) ( $model_list[0] ?? '' );
		}

		return wp_parse_args(
			[
				'provider'      => $selected['provider'] ?? $settings['provider'],
				'base_url'      => $selected['base_url'] ?? $settings['base_url'],
				'api_key_plain' => $selected['api_key_plain'] ?? '',
				'model_content' => 'image' === $purpose ? (string) ( $settings['model_content'] ?? '' ) : $model,
				'model_image'   => 'image' === $purpose ? $model : (string) ( $settings['model_image'] ?? '' ),
				'image_endpoint' => (string) ( $selected['image_endpoint'] ?? $settings['image_endpoint'] ?? '/images/generations' ),
				'image_edit_endpoint' => (string) ( $selected['image_edit_endpoint'] ?? $settings['image_edit_endpoint'] ?? '/images/edits' ),
				'image_api_format' => (string) ( $selected['image_api_format'] ?? $settings['image_api_format'] ?? 'openai_images' ),
				'key_id'        => $selected['id'] ?? '',
				'key_label'     => $selected['label'] ?? '',
			],
			$settings
		);
	}

	public static function update_key_status( string $id, array $status ): void {
		$settings = self::get();
		$keys     = is_array( $settings['keys'] ?? null ) ? $settings['keys'] : [];
		foreach ( $keys as &$key ) {
			if ( (string) ( $key['id'] ?? '' ) !== $id ) {
				continue;
			}
			$key['last_status']  = ! empty( $status['success'] ) ? 'ok' : 'error';
			$key['last_message'] = sanitize_text_field( (string) ( $status['message'] ?? $status['error'] ?? '' ) );
			$key['last_checked'] = current_time( 'mysql' );
			$key['last_models']  = array_slice( array_map( 'sanitize_text_field', (array) ( $status['models'] ?? [] ) ), 0, 200 );
		}
		unset( $key );
		$settings['keys'] = $keys;
		update_option( self::OPTION, $settings, false );
	}

	private static function sanitize_keys( $input, array $current ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}
		$current_by_id = [];
		foreach ( $current as $existing ) {
			if ( ! empty( $existing['id'] ) ) {
				$current_by_id[ (string) $existing['id'] ] = $existing;
			}
		}

		$keys = [];
		foreach ( $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$raw_key = trim( (string) ( $row['api_key'] ?? '' ) );
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = sanitize_key( substr( md5( $label . microtime( true ) . wp_rand() ), 0, 12 ) );
			}
			$existing = $current_by_id[ $id ] ?? [];
			if ( '' === $label && '' === $raw_key && empty( $existing['api_key'] ) ) {
				continue;
			}
			$provider = in_array( $row['provider'] ?? 'shopaikey_compatible', [ 'openai_compatible', 'shopaikey_compatible', 'gemini_compatible', 'custom_openai_compatible' ], true ) ? $row['provider'] : 'shopaikey_compatible';
			$base_url = esc_url_raw( (string) ( $row['base_url'] ?? '' ) );
			if ( '' === $base_url ) {
				$base_url = 'shopaikey_compatible' === $provider ? 'https://api.shopaikey.com/v1' : 'https://api.openai.com/v1';
			}
			$keys[] = [
				'id'             => $id,
				'label'          => '' !== $label ? $label : $id,
				'provider'       => $provider,
				'base_url'       => $base_url,
				'api_key'        => '' !== $raw_key ? AIKeyVault::encrypt( $raw_key ) : (string) ( $existing['api_key'] ?? '' ),
				'content_models' => self::split_models( $row['content_models'] ?? '' ),
				'image_models'   => self::split_models( $row['image_models'] ?? '' ),
				'image_endpoint' => self::sanitize_path_or_url( $row['image_endpoint'] ?? ( $existing['image_endpoint'] ?? '/images/generations' ), '/images/generations' ),
				'image_edit_endpoint' => self::sanitize_path_or_url( $row['image_edit_endpoint'] ?? ( $existing['image_edit_endpoint'] ?? '/images/edits' ), '/images/edits' ),
				'image_api_format' => self::sanitize_enum( (string) ( $row['image_api_format'] ?? ( $existing['image_api_format'] ?? 'openai_images' ) ), [ 'openai_images', 'google_genai_image' ], 'openai_images' ),
				'enabled'        => ! empty( $row['enabled'] ) ? 1 : 0,
				'priority'       => max( 1, min( 100, (int) ( $row['priority'] ?? 10 ) ) ),
				'weight'         => max( 1, min( 100, (int) ( $row['weight'] ?? 1 ) ) ),
				'last_status'    => sanitize_key( (string) ( $existing['last_status'] ?? '' ) ),
				'last_message'   => sanitize_text_field( (string) ( $existing['last_message'] ?? '' ) ),
				'last_checked'   => sanitize_text_field( (string) ( $existing['last_checked'] ?? '' ) ),
				'last_models'    => array_map( 'sanitize_text_field', (array) ( $existing['last_models'] ?? [] ) ),
			];
		}
		return $keys;
	}

	private static function split_models( $value ): array {
		if ( is_array( $value ) ) {
			$items = [];
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					$items[] = trim( (string) $item );
				}
			}
			return array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_text_field', $items ),
						static fn( string $item ): bool => '' !== $item && 'array' !== strtolower( $item )
					)
				)
			);
		}

		$value = is_scalar( $value ) ? (string) $value : '';
		return array_values(
			array_unique(
				array_filter(
					array_map( 'trim', preg_split( '/[\r\n,]+/', $value ) ?: [] ),
					static fn( string $item ): bool => '' !== $item && 'array' !== strtolower( $item )
				)
			)
		);
	}

	private static function normalize_model_field( $value ): string {
		if ( is_array( $value ) ) {
			return implode( "\n", self::split_models( $value ) );
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
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

	private static function weighted_pool( array $keys ): array {
		$pool = [];
		foreach ( $keys as $index => $key ) {
			for ( $i = 0; $i < max( 1, (int) ( $key['weight'] ?? 1 ) ); $i++ ) {
				$pool[] = $index;
			}
		}
		return $pool ?: [ 0 ];
	}

	private static function sanitize_enum( string $value, array $allowed, string $default ): string {
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private static function sanitize_image_count_option( $value ) {
		if ( 'custom' === $value ) {
			return 'custom';
		}
		$allowed = [ 0, 1, 2, 3, 5 ];
		$value   = absint( $value );
		return in_array( $value, $allowed, true ) ? $value : 1;
	}

	private static function sync_new_runtime_config( array $data ): void {
		AIRuntimeConfig::save(
			[
				'mode'                      => (string) ( $data['mode'] ?? 'disabled' ),
				'enable_content_generation' => ! empty( $data['enable_content'] ) ? 1 : 0,
				'enable_image_generation'   => ! empty( $data['enable_image'] ) ? 1 : 0,
				'temperature'               => (float) ( $data['temperature'] ?? 0.4 ),
				'max_tokens'                => (int) ( $data['max_tokens'] ?? 1800 ),
				'timeout'                   => (int) ( $data['timeout'] ?? 45 ),
				'enable_auto_post'          => ! empty( $data['enable_auto_post'] ) ? 1 : 0,
				'enable_featured_image'     => ! empty( $data['enable_featured_image'] ) ? 1 : 0,
			]
		);
	}

	private static function sync_new_provider_registries( array $data ): void {
		$content_providers = [];
		$image_providers   = [];

		foreach ( (array) ( $data['keys'] ?? [] ) as $key ) {
			if ( ! is_array( $key ) ) {
				continue;
			}
			$key_id = sanitize_key( (string) ( $key['id'] ?? '' ) );
			if ( '' === $key_id ) {
				continue;
			}
			$content_models = self::split_models( $key['content_models'] ?? [] );
			$image_models   = self::split_models( $key['image_models'] ?? [] );
			$provider_type  = in_array( $key['provider'] ?? 'shopaikey_compatible', [ 'openai_compatible', 'shopaikey_compatible', 'gemini_compatible', 'custom_openai_compatible' ], true ) ? (string) $key['provider'] : 'shopaikey_compatible';

			if ( ! empty( $content_models ) ) {
				$content_providers[] = [
					'id'                    => $key_id,
					'label'                 => (string) ( $key['label'] ?? $key_id ),
					'provider_type'         => $provider_type,
					'base_url'              => (string) ( $key['base_url'] ?? '' ),
					'api_key'               => (string) ( $key['api_key'] ?? '' ),
					'content_endpoint'      => 'gemini_compatible' === $provider_type ? '/v1beta/models/{model}:generateContent?key={api_key}' : '/chat/completions',
					'content_models'        => $content_models,
					'content_model'         => (string) ( $content_models[0] ?? '' ),
					'supports_shared_image' => ! empty( $image_models ) ? 1 : 0,
					'shared_image_model'    => (string) ( $image_models[0] ?? '' ),
					'shared_image_endpoint' => (string) ( $key['image_endpoint'] ?? '/images/generations' ),
					'shared_image_edit_endpoint' => (string) ( $key['image_edit_endpoint'] ?? '/images/edits' ),
					'shared_image_api_format' => (string) ( $key['image_api_format'] ?? 'openai_images' ),
					'shared_image_response_format' => (string) ( $data['image_response_format'] ?? 'auto' ),
					'shared_image_quality'  => (string) ( $data['image_quality'] ?? 'standard' ),
					'shared_image_style_preset' => (string) ( $data['image_style_preset'] ?? '' ),
					'enabled'               => ! empty( $key['enabled'] ) ? 1 : 0,
					'priority'              => (int) ( $key['priority'] ?? 10 ),
					'weight'                => (int) ( $key['weight'] ?? 1 ),
					'daily_limit'           => 0,
					'cooldown_after_error'  => 15,
				];
			}

			if ( ! empty( $image_models ) ) {
				$image_providers[] = [
					'id'                   => 'img_' . $key_id,
					'label'                => 'Shared key: ' . (string) ( $key['label'] ?? $key_id ),
					'provider_mode'        => ! empty( $content_models ) ? 'use_content_provider_key' : 'own',
					'provider_type'        => $provider_type,
					'content_provider_id'  => ! empty( $content_models ) ? $key_id : '',
					'base_url'             => empty( $content_models ) ? (string) ( $key['base_url'] ?? '' ) : '',
					'api_key'              => empty( $content_models ) ? (string) ( $key['api_key'] ?? '' ) : '',
					'image_endpoint'       => (string) ( $key['image_endpoint'] ?? '/images/generations' ),
					'image_edit_endpoint'  => (string) ( $key['image_edit_endpoint'] ?? '/images/edits' ),
					'image_api_format'     => (string) ( $key['image_api_format'] ?? 'openai_images' ),
					'image_model'          => (string) ( $image_models[0] ?? '' ),
					'response_type'        => (string) ( $data['image_response_format'] ?? 'auto' ),
					'default_size'         => 'custom' === (string) ( $data['image_size'] ?? '' ) ? (string) ( $data['image_size_custom'] ?? '1024x576' ) : (string) ( $data['image_size'] ?? '1024x576' ),
					'quality'              => (string) ( $data['image_quality'] ?? 'standard' ),
					'style_preset'         => (string) ( $data['image_style_preset'] ?? '' ),
					'enabled'              => ! empty( $key['enabled'] ) ? 1 : 0,
					'priority'             => (int) ( $key['priority'] ?? 10 ),
					'weight'               => (int) ( $key['weight'] ?? 1 ),
					'daily_limit'          => 0,
					'cooldown_after_error' => 15,
				];
			}
		}

		ContentProviderRegistry::save( [ 'providers' => $content_providers ] );
		ImageProviderRegistry::save(
			[
				'image_source_strategy'      => self::map_legacy_image_mode( (string) ( $data['image_source_mode'] ?? 'disabled' ) ),
				'images_per_post'            => $data['images_per_post'] ?? 1,
				'images_per_post_custom'     => $data['images_per_post_custom'] ?? 1,
				'featured_image_mode'        => self::map_legacy_featured_mode( (string) ( $data['featured_image_mode'] ?? 'first_generated' ) ),
				'insert_images_into_content' => ! empty( $data['insert_images_into_content'] ) ? 1 : 0,
				'image_placement'            => (string) ( $data['image_placement'] ?? 'after_intro' ),
				'image_heading_interval'     => (int) ( $data['image_heading_interval'] ?? 2 ),
				'image_size'                 => (string) ( $data['image_size'] ?? '1024x576' ),
				'image_size_custom'          => (string) ( $data['image_size_custom'] ?? '' ),
				'image_style'                => (string) ( $data['image_style'] ?? 'realistic' ),
				'image_style_custom'         => (string) ( $data['image_style_custom'] ?? '' ),
				'alt_text_mode'              => (string) ( $data['alt_text_mode'] ?? 'route-based' ),
				'overwrite_existing_images'  => ! empty( $data['overwrite_existing_images'] ) ? 1 : 0,
				'save_image_prompt_to_meta'  => ! empty( $data['save_image_prompt_to_meta'] ) ? 1 : 0,
				'providers'                  => $image_providers,
			]
		);
	}

	private static function map_legacy_image_mode( string $mode ): string {
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
}
