<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class AIConfigResolver {
	public static function get_config( string $purpose = 'content' ): array {
		$settings = AIConfig::get();
		if ( 'ai_commerce_agent' === ( $settings['mode'] ?? '' ) ) {
			$config = AICommerceAgentConfigAdapter::get_config( $purpose );
			if ( ! empty( $config['ready'] ) ) {
				return $config;
			}
			$config['source'] = 'ai_commerce_agent';
			return $config;
		}

		if ( 'own' !== ( $settings['mode'] ?? 'disabled' ) ) {
			return [
				'ready'  => false,
				'source' => 'disabled',
				'mode'   => 'disabled',
				'errors' => [ 'AI disabled.' ],
			];
		}

		$config = AIConfig::active_provider_config( $purpose );
		$config['ready']  = ! empty( $config['api_key_plain'] ) && ( ! empty( $config['model_content'] ) || 'image' === $purpose );
		$config['source'] = 'own_config';
		$config['errors'] = $config['ready'] ? [] : [ 'Missing API key or model in SRT AI settings.' ];
		return $config;
	}

	public static function get_source(): string {
		return (string) ( self::get_config()['source'] ?? 'disabled' );
	}

	public static function is_ready( string $purpose = 'content' ): bool {
		return ! empty( self::get_config( $purpose )['ready'] );
	}

	public static function get_errors( string $purpose = 'content' ): array {
		return (array) ( self::get_config( $purpose )['errors'] ?? [] );
	}
}
