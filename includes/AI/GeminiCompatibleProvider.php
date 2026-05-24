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
		if ( '' === $this->api_key && empty( $config['provider_id'] ) && empty( $config['key_id'] ) ) {
			$this->api_key = AIConfig::api_key();
		}
	}

	public function generate_text( string $prompt, array $args = [] ): array {
		if ( '' === $this->api_key || empty( $this->config['model_content'] ) ) {
			return [ 'success' => false, 'content' => '', 'error' => 'Missing Gemini API key or model.' ];
		}

		$response = wp_remote_post(
			$this->endpoint( (string) ( $this->config['content_endpoint'] ?? '/v1beta/models/{model}:generateContent?key={api_key}' ), (string) $this->config['model_content'] ),
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
		if ( '' === $this->api_key || empty( $this->config['model_image'] ) ) {
			return [ 'success' => false, 'url' => '', 'images' => [], 'error' => 'Missing Gemini image API key or model.' ];
		}

		$response = wp_remote_post(
			$this->endpoint( (string) ( $this->config['image_endpoint'] ?? '/v1beta/models/{model}:generateContent?key={api_key}' ), (string) $this->config['model_image'] ),
			[
				'timeout' => (int) ( $this->config['timeout'] ?? 45 ),
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'contents' => [
							[
								'role'  => 'user',
								'parts' => [
									[ 'text' => $prompt ],
								],
							],
						],
						'generationConfig' => [
							'responseModalities' => [ 'TEXT', 'IMAGE' ],
							'imageConfig' => [
								'imageSize' => (string) ( $args['size'] ?? '1024x1024' ),
							],
						],
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			LogRepository::add( 'error', 'ai_gemini_image_error', $response->get_error_message(), [ 'provider' => 'gemini_compatible' ] );
			return [ 'success' => false, 'url' => '', 'images' => [], 'error' => $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$error = $json['error']['message'] ?? wp_json_encode( $json );
			return [ 'success' => false, 'url' => '', 'images' => [], 'error' => (string) $error ];
		}

		$images = $this->extract_images_from_response( is_array( $json ) ? $json : [] );
		return ! empty( $images )
			? [ 'success' => true, 'url' => '', 'images' => $images ]
			: [ 'success' => false, 'url' => '', 'images' => [], 'error' => 'Gemini image response is empty.' ];
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

	private function endpoint( string $path, string $model ): string {
		if ( preg_match( '#^https?://#i', $path ) ) {
			return str_replace(
				[ '{model}', '{api_key}' ],
				[ rawurlencode( $model ), rawurlencode( $this->api_key ) ],
				$path
			);
		}
		$base = rtrim( (string) ( $this->config['base_url'] ?? 'https://generativelanguage.googleapis.com' ), '/' );
		$path = str_replace(
			[ '{model}', '{api_key}' ],
			[ rawurlencode( $model ), rawurlencode( $this->api_key ) ],
			$path
		);
		return $base . '/' . ltrim( $path, '/' );
	}

	private function extract_images_from_response( array $node ): array {
		$images = [];
		$this->walk_for_images( $node, $images );
		return $images;
	}

	private function walk_for_images( $node, array &$images ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['inlineData']['data'] ) && is_string( $node['inlineData']['data'] ) && '' !== $node['inlineData']['data'] ) {
			$images[] = [ 'base64_data' => (string) $node['inlineData']['data'], 'mime_type' => (string) ( $node['inlineData']['mimeType'] ?? 'image/png' ) ];
		}
		if ( isset( $node['inline_data']['data'] ) && is_string( $node['inline_data']['data'] ) && '' !== $node['inline_data']['data'] ) {
			$images[] = [ 'base64_data' => (string) $node['inline_data']['data'], 'mime_type' => (string) ( $node['inline_data']['mime_type'] ?? 'image/png' ) ];
		}
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->walk_for_images( $value, $images );
			}
		}
	}
}
