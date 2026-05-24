<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class AIRuntimeConfig {
	public const OPTION = 'srt_ai_runtime_settings';

	public static function defaults(): array {
		return [
			'mode'                     => 'disabled',
			'enable_content_generation'=> 0,
			'enable_image_generation'  => 0,
			'default_post_status'      => 'draft',
			'temperature'              => 0.4,
			'max_tokens'               => 1800,
			'timeout'                  => 45,
			'max_retries'              => 2,
			'failover_enabled'         => 1,
			'stop_on_content_error'    => 1,
			'stop_on_image_error'      => 0,
			'save_logs'                => 1,
			'enable_auto_post'         => 0,
			'enable_featured_image'    => 0,
		];
	}

	public static function get(): array {
		self::maybe_migrate();
		$settings = get_option( self::OPTION, [] );
		$settings = wp_parse_args( is_array( $settings ) ? $settings : [], self::defaults() );
		$settings['mode'] = self::sanitize_enum( (string) ( $settings['mode'] ?? 'disabled' ), [ 'disabled', 'own', 'ai_commerce_agent' ], 'disabled' );
		$settings['default_post_status'] = self::sanitize_enum( (string) ( $settings['default_post_status'] ?? 'draft' ), [ 'draft', 'pending' ], 'draft' );
		$settings['temperature'] = max( 0, min( 2, (float) ( $settings['temperature'] ?? 0.4 ) ) );
		$settings['max_tokens'] = max( 100, min( 16000, (int) ( $settings['max_tokens'] ?? 1800 ) ) );
		$settings['timeout'] = max( 5, min( 180, (int) ( $settings['timeout'] ?? 45 ) ) );
		$settings['max_retries'] = max( 1, min( 5, (int) ( $settings['max_retries'] ?? 2 ) ) );
		return $settings;
	}

	public static function save( array $input ): void {
		$current = self::get();
		$data    = [
			'mode'                     => self::sanitize_enum( (string) ( $input['mode'] ?? $current['mode'] ?? 'disabled' ), [ 'disabled', 'own', 'ai_commerce_agent' ], 'disabled' ),
			'enable_content_generation'=> ! empty( $input['enable_content_generation'] ) ? 1 : 0,
			'enable_image_generation'  => ! empty( $input['enable_image_generation'] ) ? 1 : 0,
			'default_post_status'      => self::sanitize_enum( (string) ( $input['default_post_status'] ?? $current['default_post_status'] ?? 'draft' ), [ 'draft', 'pending' ], 'draft' ),
			'temperature'              => max( 0, min( 2, (float) ( $input['temperature'] ?? $current['temperature'] ?? 0.4 ) ) ),
			'max_tokens'               => max( 100, min( 16000, (int) ( $input['max_tokens'] ?? $current['max_tokens'] ?? 1800 ) ) ),
			'timeout'                  => max( 5, min( 180, (int) ( $input['timeout'] ?? $current['timeout'] ?? 45 ) ) ),
			'max_retries'              => max( 1, min( 5, (int) ( $input['max_retries'] ?? $current['max_retries'] ?? 2 ) ) ),
			'failover_enabled'         => ! empty( $input['failover_enabled'] ) ? 1 : 0,
			'stop_on_content_error'    => ! empty( $input['stop_on_content_error'] ) ? 1 : 0,
			'stop_on_image_error'      => ! empty( $input['stop_on_image_error'] ) ? 1 : 0,
			'save_logs'                => ! empty( $input['save_logs'] ) ? 1 : 0,
			'enable_auto_post'         => ! empty( $input['enable_auto_post'] ) ? 1 : 0,
			'enable_featured_image'    => ! empty( $input['enable_featured_image'] ) ? 1 : 0,
		];
		update_option( self::OPTION, $data, false );
	}

	public static function maybe_migrate(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}

		$legacy = AIConfig::get();
		$data   = [
			'mode'                     => (string) ( $legacy['mode'] ?? 'disabled' ),
			'enable_content_generation'=> ! empty( $legacy['enable_content'] ) ? 1 : 0,
			'enable_image_generation'  => ! empty( $legacy['enable_image'] ) ? 1 : 0,
			'default_post_status'      => 'draft',
			'temperature'              => (float) ( $legacy['temperature'] ?? 0.4 ),
			'max_tokens'               => (int) ( $legacy['max_tokens'] ?? 1800 ),
			'timeout'                  => (int) ( $legacy['timeout'] ?? 45 ),
			'max_retries'              => 2,
			'failover_enabled'         => 1,
			'stop_on_content_error'    => 1,
			'stop_on_image_error'      => 0,
			'save_logs'                => 1,
			'enable_auto_post'         => ! empty( $legacy['enable_auto_post'] ) ? 1 : 0,
			'enable_featured_image'    => ! empty( $legacy['enable_featured_image'] ) ? 1 : 0,
		];
		update_option( self::OPTION, wp_parse_args( $data, self::defaults() ), false );
	}

	private static function sanitize_enum( string $value, array $allowed, string $default ): string {
		return in_array( $value, $allowed, true ) ? $value : $default;
	}
}
