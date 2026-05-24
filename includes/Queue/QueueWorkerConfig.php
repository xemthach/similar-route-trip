<?php
declare( strict_types=1 );

namespace SimilarRouteTrip\Queue;

defined( 'ABSPATH' ) || exit;

final class QueueWorkerConfig {
	public const OPTION = 'srt_queue_worker_settings';

	public static function defaults(): array {
		return [
			'paused'               => 0,
			'worker_count'         => 1,
			'batch_size_per_worker'=> 3,
			'schedule_interval'    => 'five_minutes',
			'max_jobs_per_run'     => 10,
		];
	}

	public static function get(): array {
		$settings = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $settings ) ? $settings : [], self::defaults() );
	}

	public static function save( array $input ): void {
		$data = [
			'paused'               => ! empty( $input['paused'] ) ? 1 : 0,
			'worker_count'         => max( 1, min( 5, absint( $input['worker_count'] ?? 1 ) ) ),
			'batch_size_per_worker'=> max( 1, min( 20, absint( $input['batch_size_per_worker'] ?? 3 ) ) ),
			'schedule_interval'    => in_array( (string) ( $input['schedule_interval'] ?? 'five_minutes' ), [ 'manual', 'one_minute', 'five_minutes', 'fifteen_minutes' ], true ) ? (string) $input['schedule_interval'] : 'five_minutes',
			'max_jobs_per_run'     => max( 1, min( 100, absint( $input['max_jobs_per_run'] ?? 10 ) ) ),
		];
		update_option( self::OPTION, $data, false );
	}
}
