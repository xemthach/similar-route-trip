<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

defined( 'ABSPATH' ) || exit;

final class FailoverProvider implements ProviderInterface {
	private array $configs;
	private string $purpose;
	private bool $failover_enabled;

	public function __construct( array $configs, string $purpose, bool $failover_enabled = true ) {
		$this->configs = $configs;
		$this->purpose = $purpose;
		$this->failover_enabled = $failover_enabled;
	}

	public function generate_text( string $prompt, array $args = [] ): array {
		return $this->run(
			'content',
			static fn( ProviderInterface $provider, array $config ): array => $provider->generate_text( $prompt, $args )
		);
	}

	public function generate_image( string $prompt, array $args = [] ): array {
		return $this->run(
			'image',
			static fn( ProviderInterface $provider, array $config ): array => $provider->generate_image( $prompt, $args )
		);
	}

	public function test_connection(): array {
		return $this->run(
			$this->purpose,
			static fn( ProviderInterface $provider, array $config ): array => $provider->test_connection()
		);
	}

	public function get_models(): array {
		foreach ( $this->configs as $config ) {
			$provider = AIService::provider_instance( $config );
			$models   = $provider->get_models();
			if ( ! empty( $models ) ) {
				return $models;
			}
		}
		return [];
	}

	private function run( string $task_type, callable $callback ): array {
		$errors = [];
		foreach ( $this->configs as $config ) {
			$provider_id = (string) ( $config['provider_id'] ?? '' );
			$provider    = AIService::provider_instance( $config );
			$result      = $callback( $provider, $config );

			if ( ! empty( $result['success'] ) ) {
				UsageTracker::increment_usage( $provider_id, $task_type );
				ProviderHealthManager::mark_success( $provider_id );
				AIService::update_provider_status( $this->purpose, $provider_id, $result );
				$result['provider_id'] = $provider_id;
				$result['provider_label'] = (string) ( $config['provider_label'] ?? '' );
				return $result;
			}

			$error = (string) ( $result['error'] ?? $result['message'] ?? 'Provider request failed.' );
			if ( '' !== $provider_id ) {
				ProviderHealthManager::mark_failure( $provider_id, $error, (int) ( $config['cooldown_after_error'] ?? 15 ) );
				AIService::update_provider_status( $this->purpose, $provider_id, $result );
			}
			$errors[] = ( '' !== $provider_id ? $provider_id . ': ' : '' ) . $error;
			if ( ! $this->failover_enabled ) {
				break;
			}
		}

		return [
			'success' => false,
			'content' => '',
			'images'  => [],
			'error'   => implode( ' | ', array_filter( $errors ) ),
		];
	}
}
