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

	public static function test_key( array $key, string $purpose = 'content' ): array {
		$model_content = '';
		$model_image   = '';
		if ( 'image' === $purpose ) {
			$model_image = (string) ( $key['image_model'] ?? ( (array) ( $key['image_models'] ?? [] ) )[0] ?? '' );
		} else {
			$model_content = (string) ( $key['content_model'] ?? ( (array) ( $key['content_models'] ?? [] ) )[0] ?? '' );
		}
		$config = wp_parse_args(
			[
				'mode'          => 'own',
				'provider'      => $key['provider_type'] ?? $key['provider'] ?? 'shopaikey_compatible',
				'base_url'      => $key['base_url'] ?? 'https://api.shopaikey.com',
				'api_key_plain' => $key['api_key_plain'] ?? '',
				'model_content' => $model_content,
				'model_image'   => $model_image,
				'content_endpoint' => (string) ( $key['content_endpoint'] ?? '/chat/completions' ),
				'image_endpoint' => (string) ( $key['image_endpoint'] ?? '/images/generations' ),
				'image_edit_endpoint' => (string) ( $key['image_edit_endpoint'] ?? '/images/edits' ),
				'image_api_format' => (string) ( $key['image_api_format'] ?? 'openai_images' ),
			],
			AIRuntimeConfig::get()
		);
		$result = self::make_provider( $config )->test_connection();
		if ( ! empty( $key['id'] ) ) {
			self::update_provider_status( $purpose, (string) $key['id'], $result );
		}
		return $result;
	}

	public static function test_all_keys(): array {
		$results = [];
		foreach ( ContentProviderRegistry::providers( false, true ) as $key ) {
			$results[] = [
				'id'     => $key['id'] ?? '',
				'label'  => $key['label'] ?? '',
				'result' => self::test_key( $key, 'content' ),
			];
		}
		return $results;
	}

	public static function first_content_candidate_config(): array {
		$candidates = self::content_candidates();
		return $candidates[0] ?? [];
	}

	public static function first_image_candidate_config(): array {
		$candidates = self::image_candidates();
		return $candidates[0] ?? [];
	}

	public static function provider_instance( array $config ): ProviderInterface {
		return self::make_provider( $config );
	}

	public static function update_provider_status( string $purpose, string $provider_id, array $status ): void {
		if ( '' === $provider_id ) {
			return;
		}
		if ( 'image' === $purpose ) {
			ImageProviderRegistry::update_provider_status( $provider_id, $status );
			$legacy_id = 0 === strpos( $provider_id, 'img_' ) ? substr( $provider_id, 4 ) : $provider_id;
			if ( '' !== $legacy_id ) {
				AIConfig::update_key_status( $legacy_id, $status );
			}
			return;
		}
		ContentProviderRegistry::update_provider_status( $provider_id, $status );
		AIConfig::update_key_status( $provider_id, $status );
	}

	private static function provider_for( string $purpose ): ProviderInterface {
		$candidates = 'image' === $purpose ? self::image_candidates() : self::content_candidates();
		if ( empty( $candidates ) ) {
			return new NullProvider();
		}
		$failover = ! empty( AIRuntimeConfig::get()['failover_enabled'] );
		return new FailoverProvider( $candidates, $purpose, $failover );
	}

	private static function make_provider( array $config ): ProviderInterface {
		if ( 'gemini_compatible' === ( $config['provider'] ?? '' ) ) {
			return new GeminiCompatibleProvider( $config );
		}
		return new OpenAICompatibleProvider( $config );
	}

	private static function content_candidates(): array {
		$runtime = AIRuntimeConfig::get();
		if ( 'ai_commerce_agent' === ( $runtime['mode'] ?? '' ) ) {
			$config = AICommerceAgentConfigAdapter::get_config( 'content' );
			return ! empty( $config['ready'] ) ? [ $config ] : [];
		}
		if ( 'own' !== ( $runtime['mode'] ?? 'disabled' ) ) {
			return [];
		}

		$providers = ProviderSelector::ordered_candidates( ContentProviderRegistry::providers( true, true ), 'content' );
		$configs   = [];
		foreach ( $providers as $provider ) {
			$model = (string) ( $provider['content_model'] ?? '' );
			if ( '' === $model ) {
				$model = (string) ( (array) ( $provider['content_models'] ?? [] ) )[0];
			}
			if ( '' === trim( $model ) || empty( $provider['api_key_plain'] ) ) {
				continue;
			}
			$configs[] = wp_parse_args(
				[
					'provider'         => (string) ( $provider['provider_type'] ?? 'openai_compatible' ),
					'base_url'         => (string) ( $provider['base_url'] ?? '' ),
					'api_key_plain'    => (string) ( $provider['api_key_plain'] ?? '' ),
					'model_content'    => $model,
					'content_endpoint' => (string) ( $provider['content_endpoint'] ?? '/chat/completions' ),
					'provider_id'      => (string) ( $provider['id'] ?? '' ),
					'provider_label'   => (string) ( $provider['label'] ?? '' ),
					'cooldown_after_error' => (int) ( $provider['cooldown_after_error'] ?? 15 ),
				],
				$runtime
			);
		}
		if ( ! empty( $configs ) ) {
			return $configs;
		}
		return self::legacy_candidates( 'content', $runtime );
	}

	private static function image_candidates(): array {
		$runtime = AIRuntimeConfig::get();
		if ( 'ai_commerce_agent' === ( $runtime['mode'] ?? '' ) ) {
			$config = AICommerceAgentConfigAdapter::get_config( 'image' );
			return ! empty( $config['ready'] ) ? [ $config ] : [];
		}
		if ( 'own' !== ( $runtime['mode'] ?? 'disabled' ) ) {
			return [];
		}

		$providers = ProviderSelector::ordered_candidates( ImageProviderRegistry::providers( true, true ), 'image' );
		$configs   = [];
		foreach ( $providers as $provider ) {
			$mode = (string) ( $provider['provider_mode'] ?? 'use_content_provider_key' );
			if ( 'use_content_provider_key' === $mode ) {
				$content_provider = ContentProviderRegistry::get_provider( (string) ( $provider['content_provider_id'] ?? '' ), true );
				if ( ! $content_provider || empty( $content_provider['api_key_plain'] ) || empty( $provider['image_model'] ) ) {
					continue;
				}
				$configs[] = wp_parse_args(
					[
						'provider'          => (string) ( $provider['provider_type'] ?? $content_provider['provider_type'] ?? 'openai_compatible' ),
						'base_url'          => (string) ( $content_provider['base_url'] ?? '' ),
						'api_key_plain'     => (string) ( $content_provider['api_key_plain'] ?? '' ),
						'model_image'       => (string) ( $provider['image_model'] ?? '' ),
						'image_endpoint'    => (string) ( $provider['image_endpoint'] ?? '/images/generations' ),
						'image_edit_endpoint'=> (string) ( $provider['image_edit_endpoint'] ?? '/images/edits' ),
						'image_api_format'  => (string) ( $provider['image_api_format'] ?? 'openai_images' ),
						'image_response_format' => (string) ( $provider['response_type'] ?? 'auto' ),
						'image_quality'     => (string) ( $provider['quality'] ?? 'standard' ),
						'image_style_preset'=> (string) ( $provider['style_preset'] ?? '' ),
						'provider_id'       => (string) ( $provider['id'] ?? '' ),
						'provider_label'    => (string) ( $provider['label'] ?? '' ),
						'cooldown_after_error' => (int) ( $provider['cooldown_after_error'] ?? 15 ),
					],
					$runtime
				);
				continue;
			}

			if ( empty( $provider['api_key_plain'] ) || empty( $provider['image_model'] ) ) {
				continue;
			}
			$configs[] = wp_parse_args(
				[
					'provider'          => (string) ( $provider['provider_type'] ?? 'openai_compatible' ),
					'base_url'          => (string) ( $provider['base_url'] ?? '' ),
					'api_key_plain'     => (string) ( $provider['api_key_plain'] ?? '' ),
					'model_image'       => (string) ( $provider['image_model'] ?? '' ),
					'image_endpoint'    => (string) ( $provider['image_endpoint'] ?? '/images/generations' ),
					'image_edit_endpoint'=> (string) ( $provider['image_edit_endpoint'] ?? '/images/edits' ),
					'image_api_format'  => (string) ( $provider['image_api_format'] ?? 'openai_images' ),
					'image_response_format' => (string) ( $provider['response_type'] ?? 'auto' ),
					'image_quality'     => (string) ( $provider['quality'] ?? 'standard' ),
					'image_style_preset'=> (string) ( $provider['style_preset'] ?? '' ),
					'provider_id'       => (string) ( $provider['id'] ?? '' ),
					'provider_label'    => (string) ( $provider['label'] ?? '' ),
					'cooldown_after_error' => (int) ( $provider['cooldown_after_error'] ?? 15 ),
				],
				$runtime
			);
		}
		if ( ! empty( $configs ) ) {
			return $configs;
		}
		return self::legacy_candidates( 'image', $runtime );
	}

	private static function legacy_candidates( string $purpose, array $runtime ): array {
		$settings = AIConfig::get();
		$keys     = AIConfig::keys( true, true );
		$configs  = [];

		foreach ( $keys as $key ) {
			$api_key = (string) ( $key['api_key_plain'] ?? '' );
			if ( '' === trim( $api_key ) ) {
				continue;
			}
			$model = self::legacy_model_for_purpose( $key, $settings, $purpose );
			if ( '' === $model ) {
				continue;
			}

			$config = [
				'provider'            => (string) ( $key['provider'] ?? 'openai_compatible' ),
				'base_url'            => (string) ( $key['base_url'] ?? ( $settings['base_url'] ?? '' ) ),
				'api_key_plain'       => $api_key,
				'provider_id'         => (string) ( $key['id'] ?? '' ),
				'provider_label'      => (string) ( $key['label'] ?? '' ),
				'cooldown_after_error'=> 15,
			];

			if ( 'image' === $purpose ) {
				$config['model_image']          = $model;
				$config['image_endpoint']       = (string) ( $key['image_endpoint'] ?? ( $settings['image_endpoint'] ?? '/images/generations' ) );
				$config['image_edit_endpoint']  = (string) ( $key['image_edit_endpoint'] ?? ( $settings['image_edit_endpoint'] ?? '/images/edits' ) );
				$config['image_api_format']     = (string) ( $key['image_api_format'] ?? ( $settings['image_api_format'] ?? 'openai_images' ) );
				$config['image_response_format']= (string) ( $settings['image_response_format'] ?? 'auto' );
				$config['image_quality']        = (string) ( $settings['image_quality'] ?? 'standard' );
				$config['image_style_preset']   = (string) ( $settings['image_style_preset'] ?? '' );
			} else {
				$config['model_content']   = $model;
				$config['content_endpoint']= (string) ( $key['content_endpoint'] ?? '/chat/completions' );
			}

			$configs[] = wp_parse_args( $config, $runtime );
		}

		return $configs;
	}

	private static function legacy_model_for_purpose( array $key, array $settings, string $purpose ): string {
		if ( 'image' === $purpose ) {
			$single = trim( (string) ( $key['image_model'] ?? '' ) );
			if ( '' !== $single ) {
				return $single;
			}
			$models = array_values( array_filter( array_map( 'trim', (array) ( $key['image_models'] ?? [] ) ) ) );
			if ( ! empty( $models[0] ) ) {
				return (string) $models[0];
			}
			$selected = trim( (string) ( $settings['selected_image_model'] ?? '' ) );
			if ( '' !== $selected ) {
				return $selected;
			}
			$fallback = self::split_models( (string) ( $settings['model_image'] ?? '' ) );
			return (string) ( $fallback[0] ?? '' );
		}

		$single = trim( (string) ( $key['content_model'] ?? '' ) );
		if ( '' !== $single ) {
			return $single;
		}
		$models = array_values( array_filter( array_map( 'trim', (array) ( $key['content_models'] ?? [] ) ) ) );
		if ( ! empty( $models[0] ) ) {
			return (string) $models[0];
		}
		$selected = trim( (string) ( $settings['selected_content_model'] ?? '' ) );
		if ( '' !== $selected ) {
			return $selected;
		}
		$fallback = self::split_models( (string) ( $settings['model_content'] ?? '' ) );
		return (string) ( $fallback[0] ?? '' );
	}

	private static function split_models( string $value ): array {
		return array_values(
			array_filter(
				array_map( 'trim', preg_split( '/[\r\n,]+/', $value ) ?: [] ),
				static fn( string $item ): bool => '' !== $item
			)
		);
	}
}
