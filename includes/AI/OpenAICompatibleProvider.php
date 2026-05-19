<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

use SimilarRouteTrip\Logging\LogRepository;

defined( 'ABSPATH' ) || exit;

class OpenAICompatibleProvider implements ProviderInterface {
	protected array $config;
	protected string $api_key;

	public function __construct( array $config ) {
		$this->config  = $config;
		$this->api_key = (string) ( $config['api_key_plain'] ?? '' );
		if ( '' === $this->api_key ) {
			$this->api_key = AIConfig::api_key();
		}
	}

	public function generate_text( string $prompt, array $args = [] ): array {
		if ( '' === $this->api_key || empty( $this->config['model_content'] ) ) {
			return [ 'success' => false, 'content' => '', 'error' => 'Missing API key or content model.' ];
		}

		$body = [
			'model'       => $this->config['model_content'],
			'messages'    => [
				[ 'role' => 'system', 'content' => 'You are a Vietnamese taxi SEO content writer. Return practical, conversion-focused content.' ],
				[ 'role' => 'user', 'content' => $prompt ],
			],
			'temperature' => (float) $this->config['temperature'],
			'max_tokens'  => (int) $this->config['max_tokens'],
		];

		$response = wp_remote_post( $this->endpoint( '/chat/completions' ), [
			'timeout' => (int) $this->config['timeout'],
			'headers' => $this->headers(),
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			LogRepository::add( 'error', 'ai_text_error', $response->get_error_message(), [ 'provider' => $this->config['provider'] ] );
			return [ 'success' => false, 'content' => '', 'error' => $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$error = is_array( $json ) ? wp_json_encode( $json ) : 'HTTP ' . $code;
			LogRepository::add( 'error', 'ai_text_http_error', $error, [ 'provider' => $this->config['provider'], 'code' => $code ] );
			return [ 'success' => false, 'content' => '', 'error' => $error ];
		}

		return [
			'success' => true,
			'content' => (string) ( $json['choices'][0]['message']['content'] ?? '' ),
			'usage'   => $json['usage'] ?? [],
			'model'   => $json['model'] ?? $this->config['model_content'],
		];
	}

	public function generate_image( string $prompt, array $args = [] ): array {
		if ( empty( $this->config['model_image'] ) ) {
			return [ 'success' => false, 'url' => '', 'error' => 'Missing image model.' ];
		}
		$body = [
			'model'  => $this->config['model_image'],
			'prompt' => $prompt,
			'size'   => $args['size'] ?? '1024x1024',
		];
		$response = wp_remote_post( $this->endpoint( '/images/generations' ), [
			'timeout' => (int) $this->config['timeout'],
			'headers' => $this->headers(),
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'url' => '', 'error' => $response->get_error_message() ];
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$url  = (string) ( $json['data'][0]['url'] ?? '' );
		return '' !== $url ? [ 'success' => true, 'url' => $url ] : [ 'success' => false, 'url' => '', 'error' => 'Image URL not returned.' ];
	}

	public function test_connection(): array {
		if ( '' === $this->api_key ) {
			return [ 'success' => false, 'message' => '', 'error' => 'Missing API key.' ];
		}
		$models = $this->get_models();
		if ( empty( $models ) ) {
			return [ 'success' => false, 'message' => '', 'error' => 'Connection checked but no models returned.' ];
		}
		return [ 'success' => true, 'message' => 'Connection checked.', 'models' => $models ];
	}

	public function get_models(): array {
		if ( '' === $this->api_key ) {
			return [];
		}
		$response = wp_remote_get( $this->endpoint( '/models' ), [
			'timeout' => (int) $this->config['timeout'],
			'headers' => $this->headers( false ),
		] );
		if ( is_wp_error( $response ) ) {
			return [];
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = $json['data'] ?? [];
		return is_array( $items ) ? array_values( array_filter( array_map( static fn( $m ) => is_array( $m ) ? ( $m['id'] ?? '' ) : '', $items ) ) ) : [];
	}

	protected function endpoint( string $path ): string {
		$base = rtrim( (string) $this->config['base_url'], '/' );
		if ( 'shopaikey_compatible' === ( $this->config['provider'] ?? '' ) && ! preg_match( '#/v1$#', $base ) && in_array( $path, [ '/chat/completions', '/models', '/images/generations' ], true ) ) {
			$base .= '/v1';
		}
		return $base . $path;
	}

	protected function headers( bool $json = true ): array {
		$headers = [ 'Authorization' => 'Bearer ' . $this->api_key ];
		if ( $json ) {
			$headers['Content-Type'] = 'application/json';
		}
		return $headers;
	}
}
