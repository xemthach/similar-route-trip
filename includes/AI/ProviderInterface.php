<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

interface ProviderInterface {
	public function generate_text( string $prompt, array $args = [] ): array;
	public function generate_image( string $prompt, array $args = [] ): array;
	public function test_connection(): array;
	public function get_models(): array;
}
