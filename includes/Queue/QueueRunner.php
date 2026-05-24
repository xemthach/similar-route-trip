<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Queue;

use SimilarRouteTrip\Content\ContentGenerator;
use SimilarRouteTrip\Database\RouteRepository;
use SimilarRouteTrip\Image\ImageGenerator;
use SimilarRouteTrip\Logging\LogRepository;

defined( 'ABSPATH' ) || exit;

final class QueueRunner {
	public static function run_next_batch( int $limit = 3 ): array {
		$items   = QueueRepository::next( $limit );
		$results = [
			'processed' => 0,
			'completed' => 0,
			'failed'    => 0,
		];

		foreach ( $items as $item ) {
			$results['processed']++;
			QueueRepository::increment_attempts( (int) $item['id'] );
			$result = self::run_item( $item );
			if ( empty( $result['success'] ) ) {
				$results['failed']++;
				QueueRepository::update_status( (int) $item['id'], 'failed', (string) ( $result['error'] ?? 'Unknown queue error.' ) );
				continue;
			}
			$results['completed']++;
			QueueRepository::update_status( (int) $item['id'], 'completed' );
		}

		return $results;
	}

	private static function run_item( array $item ): array {
		$payload = json_decode( (string) ( $item['payload_json'] ?? '{}' ), true );
		$payload = is_array( $payload ) ? $payload : [];
		$route_id = (int) ( $item['route_id'] ?? 0 );

		switch ( (string) ( $item['task_type'] ?? '' ) ) {
			case 'generate_content':
			case 'create_post':
			case 'update_post':
				return ContentGenerator::create_post( $route_id, $payload );

			case 'generate_image':
				$route = RouteRepository::get_by_id( $route_id );
				if ( ! $route ) {
					return [ 'success' => false, 'error' => 'Route not found.' ];
				}
				$payload['queue_id'] = (int) ( $item['id'] ?? 0 );
				return ImageGenerator::generate_for_route(
					$route,
					(int) ( $payload['post_id'] ?? $route['post_id'] ?? 0 ),
					$payload
				);
		}

		LogRepository::add( 'error', 'queue_task_unknown', 'Unknown queue task.', [ 'task_type' => $item['task_type'] ?? '' ] );
		return [ 'success' => false, 'error' => 'Unknown queue task.' ];
	}
}
