<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\AI;

final class NullProvider implements ProviderInterface {
	public function generate_text( string $prompt, array $args = [] ): array {
		return [ 'success' => false, 'content' => '', 'error' => 'AI disabled.' ];
	}
	public function generate_image( string $prompt, array $args = [] ): array {
		return [ 'success' => false, 'url' => '', 'images' => [], 'error' => 'AI image disabled.' ];
	}
	public function test_connection(): array {
		return [ 'success' => true, 'message' => 'AI disabled. Null provider active.' ];
	}
	public function get_models(): array {
		return [];
	}
}
