<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class AIService {
	public static function provider(): ProviderInterface {
		return self::provider_for( 'content' );
	}

	public static function image_provider(): ProviderInterface {
		return self::provider_for( 'image' );
	}

	public static function test_key( array $key ): array {
		$config = wp_parse_args(
			[
				'mode'          => 'own',
				'provider'      => $key['provider'] ?? 'shopaikey_compatible',
				'base_url'      => $key['base_url'] ?? 'https://api.shopaikey.com',
				'api_key_plain' => $key['api_key_plain'] ?? '',
				'model_content' => (string) ( (array) ( $key['content_models'] ?? [] ) )[0],
			],
			AIConfig::get()
		);
		$result = self::make_provider( $config )->test_connection();
		if ( ! empty( $key['id'] ) ) {
			AIConfig::update_key_status( (string) $key['id'], $result );
		}
		return $result;
	}

	public static function test_all_keys(): array {
		$results = [];
		foreach ( AIConfig::keys( false, true ) as $key ) {
			$results[] = [
				'id'     => $key['id'] ?? '',
				'label'  => $key['label'] ?? '',
				'result' => self::test_key( $key ),
			];
		}
		return $results;
	}

	private static function provider_for( string $purpose ): ProviderInterface {
		$config = AIConfigResolver::get_config( $purpose );
		if ( empty( $config['ready'] ) ) {
			return new NullProvider();
		}
		return self::make_provider( $config );
	}

	private static function make_provider( array $config ): ProviderInterface {
		if ( 'gemini_compatible' === ( $config['provider'] ?? '' ) ) {
			return new GeminiCompatibleProvider( $config );
		}
		return new OpenAICompatibleProvider( $config );
	}
}
