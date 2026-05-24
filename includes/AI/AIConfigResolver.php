<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class AIConfigResolver {
	public static function get_config( string $purpose = 'content' ): array {
		$runtime = AIRuntimeConfig::get();
		if ( 'ai_commerce_agent' === ( $runtime['mode'] ?? '' ) ) {
			$config = AICommerceAgentConfigAdapter::get_config( $purpose );
			if ( ! empty( $config['ready'] ) ) {
				return $config;
			}
			$config['source'] = 'ai_commerce_agent';
			return $config;
		}

		if ( 'own' !== ( $runtime['mode'] ?? 'disabled' ) ) {
			return [
				'ready'  => false,
				'source' => 'disabled',
				'mode'   => 'disabled',
				'errors' => [ 'AI disabled.' ],
			];
		}

		$config = 'image' === $purpose
			? AIService::first_image_candidate_config()
			: AIService::first_content_candidate_config();
		$config['ready'] = ! empty( $config['provider_id'] ?? '' );
		$config['source'] = 'image' === $purpose ? 'image_provider_registry' : 'content_provider_registry';
		$config['errors'] = $config['ready'] ? [] : [ 'No enabled provider is ready for this task.' ];
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
