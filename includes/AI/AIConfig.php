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
			'model_content'         => sanitize_text_field( (string) ( $input['model_content'] ?? '' ) ),
			'model_image'           => sanitize_text_field( (string) ( $input['model_image'] ?? '' ) ),
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
		];
		update_option( self::OPTION, $data, false );
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
				'content_models' => self::split_models( (string) ( $row['content_models'] ?? '' ) ),
				'image_models'   => self::split_models( (string) ( $row['image_models'] ?? '' ) ),
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

	private static function split_models( string $value ): array {
		return array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $value ) ?: [] ) ) ) );
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
}
