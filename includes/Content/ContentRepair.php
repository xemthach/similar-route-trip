<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Content;

use SimilarRouteTrip\Logging\LogRepository;

defined( 'ABSPATH' ) || exit;

final class ContentRepair {
	public static function run( int $limit = 100 ): array {
		$posts = get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'meta_key'       => '_srt_route_slug',
				'posts_per_page' => max( 1, min( 200, $limit ) ),
				'fields'         => 'ids',
			]
		);

		$checked  = 0;
		$repaired = 0;
		$skipped  = 0;
		$errors   = [];

		foreach ( $posts as $post_id ) {
			$checked++;
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );
			if ( ! $post ) {
				$skipped++;
				continue;
			}

			$clean = self::sanitize_html( (string) $post->post_content );
			if ( '' === trim( $clean ) ) {
				$skipped++;
				continue;
			}
			if ( $clean === (string) $post->post_content ) {
				$skipped++;
				continue;
			}

			$update = wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $clean,
				],
				true
			);

			if ( is_wp_error( $update ) ) {
				$errors[] = $update->get_error_message();
				LogRepository::add( 'error', 'content_repair_failed', $update->get_error_message(), [ 'post_id' => $post_id ] );
				continue;
			}

			$repaired++;
			LogRepository::add( 'info', 'content_repaired', 'Existing generated post content cleaned.', [ 'post_id' => $post_id ] );
		}

		return compact( 'checked', 'repaired', 'skipped', 'errors' );
	}

	public static function sanitize_html( string $content ): string {
		$content = ContentGenerator::sanitize_generated_content( $content );
		return self::remove_broken_faq_markup( $content );
	}

	private static function remove_broken_faq_markup( string $content ): string {
		$content = preg_replace( '/<div\s+class="faq[^>]*>[\s\S]*$/i', '', $content ) ?? $content;
		$content = preg_replace( '/<h2[^>]*>.*FAQ.*<\/h2>/iu', '', $content ) ?? $content;
		return trim( $content );
	}
}

