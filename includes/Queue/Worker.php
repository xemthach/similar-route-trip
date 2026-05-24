<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Queue;

use SimilarRouteTrip\Content\ContentGenerator;
use SimilarRouteTrip\Database\RouteRepository;
use SimilarRouteTrip\Image\ImageGenerator;
use SimilarRouteTrip\Logging\LogRepository;

defined( 'ABSPATH' ) || exit;

final class Worker {
	public static function run( string $worker_id, int $limit ): array {
		$jobs = JobRepository::next_jobs( $limit, $worker_id );
		$result = [
			'processed'  => 0,
			'completed'  => 0,
			'failed'     => 0,
			'retrying'   => 0,
		];

		foreach ( $jobs as $job ) {
			$result['processed']++;
			$run = self::process_job( $job );
			if ( ! empty( $run['success'] ) ) {
				JobRepository::complete_job( (int) ( $job['id'] ?? 0 ) );
				$result['completed']++;
				continue;
			}

			$retryable = empty( $run['stop_retry'] );
			JobRepository::fail_job( (int) ( $job['id'] ?? 0 ), (string) ( $run['error'] ?? 'Job failed.' ), $retryable );
			if ( $retryable ) {
				$result['retrying']++;
			} else {
				$result['failed']++;
			}
		}

		return $result;
	}

	private static function process_job( array $job ): array {
		$payload = json_decode( (string) ( $job['payload_json'] ?? '{}' ), true );
		$payload = is_array( $payload ) ? $payload : [];
		$route_id = (int) ( $job['route_id'] ?? 0 );

		switch ( (string) ( $job['job_type'] ?? '' ) ) {
			case 'generate_content':
				$payload['use_job_queue'] = true;
				return ContentGenerator::create_post( $route_id, $payload );

			case 'generate_image':
				$route = RouteRepository::get_by_id( $route_id );
				if ( ! $route ) {
					return [ 'success' => false, 'error' => 'Route not found.', 'stop_retry' => true ];
				}
				return ImageGenerator::generate_for_route(
					$route,
					(int) ( $payload['post_id'] ?? $route['post_id'] ?? 0 ),
					$payload
				);
		}

		LogRepository::add( 'error', 'job_type_unknown', 'Unknown worker job type.', [ 'job_type' => $job['job_type'] ?? '' ] );
		return [ 'success' => false, 'error' => 'Unknown worker job type.', 'stop_retry' => true ];
	}
}
