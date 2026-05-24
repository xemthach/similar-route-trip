<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class ContentProviderRegistry {
	public const OPTION = 'srt_content_provider_registry';

	public static function get(): array {
		self::maybe_migrate();
		$settings = get_option( self::OPTION, [] );
		$settings = is_array( $settings ) ? $settings : [];
		$settings['providers'] = self::sanitize_provider_rows( $settings['providers'] ?? [], self::existing_by_id( $settings['providers'] ?? [] ) );
		return wp_parse_args( $settings, [ 'providers' => [] ] );
	}

	public static function providers( bool $enabled_only = false, bool $decrypt = false ): array {
		$settings = self::get();
		$rows     = [];
		foreach ( (array) ( $settings['providers'] ?? [] ) as $row ) {
			if ( $enabled_only && empty( $row['enabled'] ) ) {
				continue;
			}
			if ( empty( $row['content_models'] ) ) {
				continue;
			}
			if ( $decrypt ) {
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
			'providers' => self::sanitize_provider_rows( $input['providers'] ?? [], self::existing_by_id( $current['providers'] ?? [] ) ),
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
			$row['last_models']  = array_slice( array_map( 'sanitize_text_field', (array) ( $status['models'] ?? [] ) ), 0, 200 );
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

		$legacy_settings = AIConfig::get();
		$legacy_keys     = AIConfig::keys( false, false );
		$providers       = [];

		foreach ( $legacy_keys as $key ) {
			if ( empty( $key['content_models'] ) ) {
				continue;
			}
			$providers[] = self::map_legacy_key( $key );
		}

		if ( empty( $providers ) && ! empty( $legacy_settings['api_key'] ) && '' !== trim( (string) ( $legacy_settings['model_content'] ?? '' ) ) ) {
			$providers[] = self::map_legacy_key(
				[
					'id'               => 'legacy',
					'label'            => 'Legacy content provider',
					'provider'         => (string) ( $legacy_settings['provider'] ?? 'openai_compatible' ),
					'base_url'         => (string) ( $legacy_settings['base_url'] ?? '' ),
					'api_key'          => (string) ( $legacy_settings['api_key'] ?? '' ),
					'content_models'   => preg_split( '/[\r\n,]+/', (string) ( $legacy_settings['model_content'] ?? '' ) ) ?: [],
					'image_models'     => preg_split( '/[\r\n,]+/', (string) ( $legacy_settings['model_image'] ?? '' ) ) ?: [],
					'image_endpoint'   => (string) ( $legacy_settings['image_endpoint'] ?? '/images/generations' ),
					'image_edit_endpoint' => (string) ( $legacy_settings['image_edit_endpoint'] ?? '/images/edits' ),
					'image_api_format' => (string) ( $legacy_settings['image_api_format'] ?? 'openai_images' ),
					'enabled'          => 1,
					'priority'         => 10,
					'weight'           => 1,
					'last_status'      => '',
					'last_message'     => '',
					'last_checked'     => '',
					'last_models'      => [],
				]
			);
		}

		update_option( self::OPTION, [ 'providers' => $providers ], false );
	}

	private static function map_legacy_key( array $key ): array {
		$content_models = self::split_models( $key['content_models'] ?? [] );
		$image_models   = self::split_models( $key['image_models'] ?? [] );
		return [
			'id'                    => sanitize_key( (string) ( $key['id'] ?? '' ) ),
			'label'                 => sanitize_text_field( (string) ( $key['label'] ?? $key['id'] ?? 'provider' ) ),
			'provider_type'         => self::sanitize_provider_type( (string) ( $key['provider'] ?? 'openai_compatible' ) ),
			'base_url'              => esc_url_raw( (string) ( $key['base_url'] ?? '' ) ),
			'api_key'               => (string) ( $key['api_key'] ?? '' ),
			'content_endpoint'      => '/chat/completions',
			'content_models'        => $content_models,
			'content_model'         => (string) ( $content_models[0] ?? '' ),
			'supports_shared_image' => ! empty( $image_models ) ? 1 : 0,
			'shared_image_model'    => (string) ( $image_models[0] ?? '' ),
			'shared_image_endpoint' => sanitize_text_field( (string) ( $key['image_endpoint'] ?? '/images/generations' ) ),
			'shared_image_edit_endpoint' => sanitize_text_field( (string) ( $key['image_edit_endpoint'] ?? '/images/edits' ) ),
			'shared_image_api_format' => sanitize_text_field( (string) ( $key['image_api_format'] ?? 'openai_images' ) ),
			'shared_image_response_format' => 'auto',
			'shared_image_quality'  => 'standard',
			'shared_image_style_preset' => '',
			'enabled'               => ! empty( $key['enabled'] ) ? 1 : 0,
			'priority'              => max( 1, min( 100, (int) ( $key['priority'] ?? 10 ) ) ),
			'weight'                => max( 1, min( 100, (int) ( $key['weight'] ?? 1 ) ) ),
			'daily_limit'           => 0,
			'cooldown_after_error'  => 15,
			'last_status'           => sanitize_key( (string) ( $key['last_status'] ?? '' ) ),
			'last_message'          => sanitize_text_field( (string) ( $key['last_message'] ?? '' ) ),
			'last_checked'          => sanitize_text_field( (string) ( $key['last_checked'] ?? '' ) ),
			'last_models'           => array_map( 'sanitize_text_field', (array) ( $key['last_models'] ?? [] ) ),
		];
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
			$label   = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$raw_key = trim( (string) ( $row['api_key'] ?? '' ) );
			$stored_key = self::sanitize_secret( $raw_key, (string) ( $current['api_key'] ?? '' ) );
			$content_models = self::split_models( $row['content_models'] ?? [] );
			if ( '' === $label && '' === $raw_key && empty( $current['api_key'] ) && empty( $content_models ) ) {
				continue;
			}
			$provider_type = self::sanitize_provider_type( (string) ( $row['provider_type'] ?? $row['provider'] ?? 'openai_compatible' ) );
			$base_url      = esc_url_raw( (string) ( $row['base_url'] ?? '' ) );
			if ( '' === $base_url ) {
				$base_url = self::default_base_url( $provider_type );
			}
			$content_endpoint = self::sanitize_path_or_url(
				$row['content_endpoint'] ?? ( $current['content_endpoint'] ?? self::default_content_endpoint( $provider_type ) ),
				self::default_content_endpoint( $provider_type )
			);

			$providers[] = [
				'id'                    => $id,
				'label'                 => '' !== $label ? $label : $id,
				'provider_type'         => $provider_type,
				'base_url'              => $base_url,
				'api_key'               => $stored_key,
				'content_endpoint'      => $content_endpoint,
				'content_models'        => $content_models,
				'content_model'         => sanitize_text_field( (string) ( $row['content_model'] ?? $current['content_model'] ?? ( $content_models[0] ?? '' ) ) ),
				'supports_shared_image' => ! empty( $row['supports_shared_image'] ) || ! empty( $current['supports_shared_image'] ) ? 1 : 0,
				'shared_image_model'    => sanitize_text_field( (string) ( $row['shared_image_model'] ?? $current['shared_image_model'] ?? '' ) ),
				'shared_image_endpoint' => self::sanitize_path_or_url( $row['shared_image_endpoint'] ?? ( $current['shared_image_endpoint'] ?? '/images/generations' ), '/images/generations' ),
				'shared_image_edit_endpoint' => self::sanitize_path_or_url( $row['shared_image_edit_endpoint'] ?? ( $current['shared_image_edit_endpoint'] ?? '/images/edits' ), '/images/edits' ),
				'shared_image_api_format' => sanitize_text_field( (string) ( $row['shared_image_api_format'] ?? $current['shared_image_api_format'] ?? 'openai_images' ) ),
				'shared_image_response_format' => sanitize_text_field( (string) ( $row['shared_image_response_format'] ?? $current['shared_image_response_format'] ?? 'auto' ) ),
				'shared_image_quality'  => sanitize_text_field( (string) ( $row['shared_image_quality'] ?? $current['shared_image_quality'] ?? 'standard' ) ),
				'shared_image_style_preset' => sanitize_text_field( (string) ( $row['shared_image_style_preset'] ?? $current['shared_image_style_preset'] ?? '' ) ),
				'enabled'               => ! empty( $row['enabled'] ) ? 1 : 0,
				'priority'              => max( 1, min( 100, (int) ( $row['priority'] ?? $current['priority'] ?? 10 ) ) ),
				'weight'                => max( 1, min( 100, (int) ( $row['weight'] ?? $current['weight'] ?? 1 ) ) ),
				'daily_limit'           => max( 0, (int) ( $row['daily_limit'] ?? $current['daily_limit'] ?? 0 ) ),
				'cooldown_after_error'  => max( 0, min( 1440, (int) ( $row['cooldown_after_error'] ?? $current['cooldown_after_error'] ?? 15 ) ) ),
				'last_status'           => sanitize_key( (string) ( $current['last_status'] ?? '' ) ),
				'last_message'          => sanitize_text_field( (string) ( $current['last_message'] ?? '' ) ),
				'last_checked'          => sanitize_text_field( (string) ( $current['last_checked'] ?? '' ) ),
				'last_models'           => array_map( 'sanitize_text_field', (array) ( $current['last_models'] ?? [] ) ),
			];
		}

		return $providers;
	}

	private static function split_models( $value ): array {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_map( 'strval', $value ) );
		}
		$value = is_scalar( $value ) ? (string) $value : '';
		return array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_text_field', array_map( 'trim', preg_split( '/[\r\n,]+/', $value ) ?: [] ) ),
					static fn( string $item ): bool => '' !== $item && 'array' !== strtolower( $item )
				)
			)
		);
	}

	private static function sanitize_provider_type( string $value ): string {
		$allowed = [ 'shopaikey_compatible', 'openai_compatible', 'gemini_compatible', 'custom_openai_compatible', 'custom' ];
		return in_array( $value, $allowed, true ) ? $value : 'shopaikey_compatible';
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

	private static function default_base_url( string $provider_type ): string {
		if ( 'gemini_compatible' === $provider_type ) {
			return 'https://generativelanguage.googleapis.com';
		}
		if ( 'shopaikey_compatible' === $provider_type ) {
			return 'https://api.shopaikey.com/v1';
		}
		return 'https://api.openai.com/v1';
	}

	private static function default_content_endpoint( string $provider_type ): string {
		if ( 'gemini_compatible' === $provider_type ) {
			return '/v1beta/models/{model}:generateContent?key={api_key}';
		}
		return '/chat/completions';
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
