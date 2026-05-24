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
		if ( '' === $this->api_key && empty( $config['provider_id'] ) && empty( $config['key_id'] ) ) {
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

		$response = wp_remote_post( $this->endpoint( (string) ( $this->config['content_endpoint'] ?? '/chat/completions' ) ), [
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
			return [ 'success' => false, 'url' => '', 'images' => [], 'error' => 'Missing image model.' ];
		}
		$format = (string) ( $this->config['image_api_format'] ?? 'openai_images' );
		if ( 'google_genai_image' === $format ) {
			return $this->generate_google_genai_image( $prompt, $args );
		}

		$body = array_filter(
			[
				'model'           => $this->config['model_image'],
				'prompt'          => $prompt,
				'n'               => max( 1, min( 10, (int) ( $args['n'] ?? 1 ) ) ),
				'size'            => (string) ( $args['size'] ?? '1024x1024' ),
				'quality'         => (string) ( $args['quality'] ?? ( $this->config['image_quality'] ?? '' ) ),
				'style'           => (string) ( $args['style'] ?? ( $this->config['image_style_preset'] ?? '' ) ),
				'response_format' => $this->resolve_response_format( (string) ( $args['response_format'] ?? ( $this->config['image_response_format'] ?? 'auto' ) ) ),
			],
			static fn( $value ): bool => '' !== (string) $value && null !== $value
		);
		$response = wp_remote_post( $this->endpoint( (string) ( $this->config['image_endpoint'] ?? '/images/generations' ) ), [
			'timeout' => (int) $this->config['timeout'],
			'headers' => $this->headers(),
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $response ) ) {
			LogRepository::add( 'error', 'ai_image_error', $response->get_error_message(), [ 'provider' => $this->config['provider'] ] );
			return [ 'success' => false, 'url' => '', 'error' => $response->get_error_message() ];
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$error = $this->extract_error_message( $json, $code );
			LogRepository::add( 'error', 'ai_image_http_error', $error, [ 'provider' => $this->config['provider'], 'code' => $code ] );
			return [ 'success' => false, 'url' => '', 'images' => [], 'error' => $error ];
		}
		$images = $this->extract_images_from_response( $json );
		if ( empty( $images ) ) {
			return [ 'success' => false, 'url' => '', 'images' => [], 'error' => 'Image URL or base64 payload not returned.', 'raw' => $json ];
		}
		return [ 'success' => true, 'url' => (string) ( $images[0]['url'] ?? '' ), 'images' => $images, 'raw' => $json ];
	}

	public function test_connection(): array {
		if ( '' === $this->api_key ) {
			return [ 'success' => false, 'message' => '', 'error' => 'Missing API key.' ];
		}
		$models = $this->get_models();
		if ( ! empty( $models ) ) {
			return [ 'success' => true, 'message' => 'Connection checked.', 'models' => $models ];
		}

		if ( $this->probe_text_endpoint() ) {
			return [ 'success' => true, 'message' => 'Connection checked via content endpoint.', 'models' => [] ];
		}
		if ( $this->probe_image_endpoint() ) {
			return [ 'success' => true, 'message' => 'Connection checked via image endpoint.', 'models' => [] ];
		}
		return [ 'success' => false, 'message' => '', 'error' => 'Connection checked but no models returned and probe failed.' ];
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
		if ( preg_match( '#^https?://#i', $path ) ) {
			return $path;
		}
		$base = rtrim( (string) $this->config['base_url'], '/' );
		if ( 0 === strpos( $path, '/v1beta/' ) && preg_match( '#/v1$#', $base ) ) {
			$base = preg_replace( '#/v1$#', '', $base ) ?: $base;
		}
		if ( 'shopaikey_compatible' === ( $this->config['provider'] ?? '' ) && ! preg_match( '#/v1(?:beta)?$#', $base ) && in_array( $path, [ '/chat/completions', '/models', '/images/generations' ], true ) ) {
			$base .= '/v1';
		}
		return $base . '/' . ltrim( $path, '/' );
	}

	protected function headers( bool $json = true ): array {
		$headers = [ 'Authorization' => 'Bearer ' . $this->api_key ];
		if ( $json ) {
			$headers['Content-Type'] = 'application/json';
		}
		return $headers;
	}

	protected function resolve_response_format( string $format ): string {
		return in_array( $format, [ 'url', 'b64_json' ], true ) ? $format : 'url';
	}

	protected function extract_error_message( $json, int $code ): string {
		if ( is_array( $json ) ) {
			$message = (string) ( $json['error']['message'] ?? $json['message'] ?? $json['detail'] ?? '' );
			$type    = (string) ( $json['error']['type'] ?? $json['error']['code'] ?? $json['code'] ?? '' );
			if ( '' !== $message ) {
				return '' !== $type ? $type . ': ' . $message : $message;
			}
			return wp_json_encode( $json );
		}
		return 'HTTP ' . $code;
	}

	protected function extract_images_from_response( array $json ): array {
		$images = [];
		$this->walk_for_images( $json, $images );
		return $images;
	}

	protected function walk_for_images( $node, array &$images ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['url'] ) && is_string( $node['url'] ) && '' !== $node['url'] ) {
			$images[] = [ 'url' => esc_url_raw( (string) $node['url'] ) ];
		}
		if ( isset( $node['b64_json'] ) && is_string( $node['b64_json'] ) && '' !== $node['b64_json'] ) {
			$images[] = [ 'base64_data' => (string) $node['b64_json'], 'mime_type' => (string) ( $node['mime_type'] ?? 'image/png' ) ];
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

	protected function generate_google_genai_image( string $prompt, array $args = [] ): array {
		$model = rawurlencode( (string) $this->config['model_image'] );
		$path  = str_replace( '{model}', $model, (string) ( $this->config['image_endpoint'] ?? '/v1beta/models/{model}:generateContent' ) );
		$response = wp_remote_post(
			$this->endpoint( $path ),
			[
				'timeout' => (int) $this->config['timeout'],
				'headers' => $this->headers(),
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
								'imageSize'   => (string) ( $args['size'] ?? '1024x1024' ),
								'aspectRatio' => $this->aspect_ratio_from_size( (string) ( $args['size'] ?? '1024x1024' ) ),
							],
						],
					]
				),
			]
		);
		if ( is_wp_error( $response ) ) {
			LogRepository::add( 'error', 'ai_image_error', $response->get_error_message(), [ 'provider' => $this->config['provider'] ] );
			return [ 'success' => false, 'url' => '', 'images' => [], 'error' => $response->get_error_message() ];
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$error = $this->extract_error_message( $json, $code );
			LogRepository::add( 'error', 'ai_image_http_error', $error, [ 'provider' => $this->config['provider'], 'code' => $code ] );
			return [ 'success' => false, 'url' => '', 'images' => [], 'error' => $error ];
		}
		$images = $this->extract_images_from_response( $json );
		return ! empty( $images )
			? [ 'success' => true, 'url' => '', 'images' => $images, 'raw' => $json ]
			: [ 'success' => false, 'url' => '', 'images' => [], 'error' => 'Gemini image payload not returned.', 'raw' => $json ];
	}

	protected function aspect_ratio_from_size( string $size ): string {
		if ( preg_match( '/^(\d+)x(\d+)$/', $size, $m ) ) {
			$width = max( 1, (int) $m[1] );
			$height = max( 1, (int) $m[2] );
			$gcd = $this->gcd( $width, $height );
			return (int) ( $width / $gcd ) . ':' . (int) ( $height / $gcd );
		}
		return '16:9';
	}

	protected function gcd( int $a, int $b ): int {
		while ( 0 !== $b ) {
			$temp = $b;
			$b = $a % $b;
			$a = $temp;
		}
		return max( 1, $a );
	}

	private function probe_text_endpoint(): bool {
		$model = trim( (string) ( $this->config['model_content'] ?? '' ) );
		if ( '' === $model ) {
			return false;
		}
		$body = [
			'model' => $model,
			'messages' => [
				[ 'role' => 'user', 'content' => 'ping' ],
			],
			'max_tokens' => 1,
			'temperature' => 0,
		];
		$response = wp_remote_post(
			$this->endpoint( (string) ( $this->config['content_endpoint'] ?? '/chat/completions' ) ),
			[
				'timeout' => max( 10, (int) $this->config['timeout'] ),
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $body ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	private function probe_image_endpoint(): bool {
		$model = trim( (string) ( $this->config['model_image'] ?? '' ) );
		if ( '' === $model ) {
			return false;
		}
		$body = [
			'model' => $model,
			'prompt' => 'simple test image, no text',
			'n' => 1,
			'size' => '1024x1024',
			'response_format' => 'url',
		];
		$response = wp_remote_post(
			$this->endpoint( (string) ( $this->config['image_endpoint'] ?? '/images/generations' ) ),
			[
				'timeout' => max( 10, (int) $this->config['timeout'] ),
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $body ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}
