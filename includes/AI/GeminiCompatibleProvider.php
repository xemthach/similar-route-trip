<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

use SimilarRouteTrip\Logging\LogRepository;

defined( 'ABSPATH' ) || exit;

final class GeminiCompatibleProvider implements ProviderInterface {
	private array $config;
	private string $api_key;

	public function __construct( array $config ) {
		$this->config  = $config;
		$this->api_key = (string) ( $config['api_key_plain'] ?? '' );
		if ( '' === $this->api_key ) {
			$this->api_key = AIConfig::api_key();
		}
	}

	public function generate_text( string $prompt, array $args = [] ): array {
		if ( '' === $this->api_key || empty( $this->config['model_content'] ) ) {
			return [ 'success' => false, 'content' => '', 'error' => 'Missing Gemini API key or model.' ];
		}

		$url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
			rawurlencode( (string) $this->config['model_content'] ),
			rawurlencode( $this->api_key )
		);
		$response = wp_remote_post(
			$url,
			[
				'timeout' => (int) ( $this->config['timeout'] ?? 45 ),
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'contents'         => [
							[
								'role'  => 'user',
								'parts' => [
									[ 'text' => $prompt ],
								],
							],
						],
						'generationConfig' => [
							'temperature'     => (float) ( $this->config['temperature'] ?? 0.4 ),
							'maxOutputTokens' => (int) ( $this->config['max_tokens'] ?? 1800 ),
						],
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			LogRepository::add( 'error', 'ai_gemini_error', $response->get_error_message(), [ 'provider' => 'gemini_compatible' ] );
			return [ 'success' => false, 'content' => '', 'error' => $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$error = $json['error']['message'] ?? wp_json_encode( $json );
			return [ 'success' => false, 'content' => '', 'error' => (string) $error ];
		}

		$content = (string) ( $json['candidates'][0]['content']['parts'][0]['text'] ?? '' );
		return [
			'success' => '' !== trim( $content ),
			'content' => $content,
			'usage'   => $json['usageMetadata'] ?? [],
			'model'   => $this->config['model_content'],
			'error'   => '' === trim( $content ) ? 'Gemini response is empty.' : '',
		];
	}

	public function generate_image( string $prompt, array $args = [] ): array {
		return [ 'success' => false, 'url' => '', 'error' => 'Gemini image generation is optional and not enabled in this adapter.' ];
	}

	public function test_connection(): array {
		$result = $this->generate_text( 'Say OK in one word.' );
		return ! empty( $result['success'] )
			? [ 'success' => true, 'message' => 'Connection successful. Model: ' . (string) $this->config['model_content'] ]
			: [ 'success' => false, 'message' => '', 'error' => (string) ( $result['error'] ?? 'Gemini test failed.' ) ];
	}

	public function get_models(): array {
		return [];
	}
}
