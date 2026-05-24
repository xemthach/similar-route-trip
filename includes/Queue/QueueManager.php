<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Queue;

use SimilarRouteTrip\AI\AIRuntimeConfig;

defined( 'ABSPATH' ) || exit;

final class QueueManager {
	public static function enqueue_content( int $route_id, array $args = [] ): int {
		$runtime = AIRuntimeConfig::get();
		$args['status'] = in_array( (string) ( $args['status'] ?? '' ), [ 'draft', 'pending' ], true )
			? (string) $args['status']
			: (string) ( $runtime['default_post_status'] ?? 'draft' );
		return JobRepository::enqueue(
			'generate_content',
			$route_id,
			$args,
			[
				'priority'     => (int) ( $args['priority'] ?? 10 ),
				'max_attempts' => (int) ( $runtime['max_retries'] ?? 2 ),
			]
		);
	}

	public static function enqueue_bulk_content( array $route_ids, array $args = [] ): int {
		$jobs = [];
		foreach ( $route_ids as $route_id ) {
			$route_id = (int) $route_id;
			if ( $route_id <= 0 ) {
				continue;
			}
			$jobs[] = [
				'job_type' => 'generate_content',
				'route_id' => $route_id,
				'payload'  => $args,
				'args'     => [
					'priority'     => (int) ( $args['priority'] ?? 10 ),
					'max_attempts' => (int) ( AIRuntimeConfig::get()['max_retries'] ?? 2 ),
				],
			];
		}
		return JobRepository::enqueue_bulk( $jobs );
	}
}
