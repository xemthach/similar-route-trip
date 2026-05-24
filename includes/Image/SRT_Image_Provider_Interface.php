<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Image;

interface SRT_Image_Provider_Interface {
	public function generate_images( string $prompt, array $args = [] ): array;

	public function search_images( string $query, array $args = [] ): array;

	public function download_image( string $image_url, array $args = [] ): array;

	public function test_connection(): array;

	public function supports_generation(): bool;

	public function supports_search(): bool;
}
