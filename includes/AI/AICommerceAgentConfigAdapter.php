<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class AICommerceAgentConfigAdapter {
	public static function is_available(): bool {
		return defined( 'AICA_VERSION' ) && self::table_exists();
	}

	public static function get_config( string $purpose = 'content' ): array {
		global $wpdb;

		if ( ! self::is_available() ) {
			return [ 'ready' => false, 'errors' => [ 'AI Commerce Agent is not active or provider table is missing.' ] ];
		}

		$table = $wpdb->prefix . 'ai_provider_settings';
		$row = $wpdb->get_row(
			"SELECT * FROM {$table}
			 WHERE priority > 0
			   AND status = 'active'
			   AND (cooldown_until IS NULL OR cooldown_until < NOW())
			 ORDER BY priority ASC, weight DESC, id ASC
			 LIMIT 1"
		);

		if ( ! $row ) {
			return [ 'ready' => false, 'errors' => [ 'No active AI Commerce Agent provider is available.' ] ];
		}

		$api_key = function_exists( 'aica_decrypt_api_key' ) ? aica_decrypt_api_key( (string) $row->api_key ) : '';
		if ( '' === $api_key && 'ollama' !== (string) $row->provider ) {
			return [ 'ready' => false, 'errors' => [ 'Selected AI Commerce Agent provider has no readable API key.' ] ];
		}

		$provider = self::map_provider( (string) $row->provider );
		$model    = (string) $row->model;

		return [
			'ready'          => true,
			'source'         => 'ai_commerce_agent',
			'mode'           => 'own',
			'provider'       => $provider,
			'base_url'       => self::base_url( (string) $row->provider, (string) $row->endpoint_url ),
			'api_key_plain'  => $api_key,
			'model_content'  => 'image' === $purpose ? '' : $model,
			'model_image'    => 'image' === $purpose ? $model : '',
			'temperature'    => (float) ( AIRuntimeConfig::get()['temperature'] ?? 0.4 ),
			'max_tokens'     => (int) ( AIRuntimeConfig::get()['max_tokens'] ?? 1800 ),
			'timeout'        => (int) ( AIRuntimeConfig::get()['timeout'] ?? 45 ),
			'image_endpoint' => '/images/generations',
			'image_edit_endpoint' => '/images/edits',
			'image_api_format' => 'openai_images',
			'aica_provider_id' => (int) $row->id,
			'aica_provider'  => (string) $row->provider,
		];
	}

	public static function get_errors(): array {
		$config = self::get_config();
		return (array) ( $config['errors'] ?? [] );
	}

	private static function table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'ai_provider_settings';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private static function map_provider( string $provider ): string {
		if ( 'shopaikey' === $provider ) {
			return 'shopaikey_compatible';
		}
		if ( 'gemini' === $provider ) {
			return 'gemini_compatible';
		}
		return 'openai_compatible';
	}

	private static function base_url( string $provider, string $endpoint ): string {
		if ( '' !== $endpoint ) {
			return $endpoint;
		}
		if ( 'shopaikey' === $provider ) {
			return 'https://api.shopaikey.com/v1';
		}
		if ( 'groq' === $provider ) {
			return 'https://api.groq.com/openai/v1';
		}
		if ( 'ollama' === $provider ) {
			return 'http://localhost:11434/v1';
		}
		return 'https://api.openai.com/v1';
	}
}
