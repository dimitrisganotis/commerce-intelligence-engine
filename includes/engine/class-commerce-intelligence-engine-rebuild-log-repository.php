<?php

/**
 * Rebuild log repository.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persists rebuild run lifecycle and progress.
 */
class CIE_Rebuild_Log_Repository {

	/**
	 * Starts a rebuild run.
	 *
	 * @param string $run_id Run UUID.
	 * @param string $mode   Rebuild mode.
	 * @return int
	 */
	public function start_run( string $run_id, string $mode ): int {
		global $wpdb;

		$table = CIE_DB_Migrator::get_table_name( 'rebuild_log' );
		$query = $wpdb->prepare(
			"INSERT INTO {$table} (run_id, mode, status, started_at) VALUES (%s, %s, %s, NOW())",
			$run_id,
			$mode,
			'running'
		);

		$result = $wpdb->query( $query );
		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates run progress counters.
	 *
	 * @param string $run_id  Run UUID.
	 * @param array  $metrics Progress metrics.
	 * @return bool
	 */
	public function update_progress( string $run_id, array $metrics ): bool {
		return $this->update_with_metrics( $run_id, array(), $metrics );
	}

	/**
	 * Completes a run.
	 *
	 * @param string $run_id  Run UUID.
	 * @param array  $metrics Completion metrics.
	 * @return bool
	 */
	public function complete_run( string $run_id, array $metrics ): bool {
		$set_parts = array(
			"status = 'completed'",
			'completed_at = NOW()',
		);

		$duration = $this->sanitize_non_negative_int_metric( $metrics, 'duration_seconds' );
		if ( null !== $duration ) {
			$set_parts[] = 'duration_seconds = %d';
		}

		$memory_peak = $this->sanitize_non_negative_int_metric( $metrics, 'memory_peak_bytes' );
		if ( null !== $memory_peak ) {
			$set_parts[] = 'memory_peak_bytes = %d';
		}

		$params = array();
		if ( null !== $duration ) {
			$params[] = $duration;
		}
		if ( null !== $memory_peak ) {
			$params[] = $memory_peak;
		}

		return $this->update_with_metrics( $run_id, $set_parts, $metrics, $params );
	}

	/**
	 * Marks a run as failed.
	 *
	 * @param string $run_id        Run UUID.
	 * @param string $error_message Error message.
	 * @param array  $metrics       Failure metrics.
	 * @return bool
	 */
	public function fail_run( string $run_id, string $error_message, array $metrics = array() ): bool {
		$set_parts = array(
			"status = 'failed'",
			'completed_at = NOW()',
			'error_message = %s',
		);
		$params    = array( $error_message );

		$duration = $this->sanitize_non_negative_int_metric( $metrics, 'duration_seconds' );
		if ( null !== $duration ) {
			$set_parts[] = 'duration_seconds = %d';
			$params[]    = $duration;
		}

		$memory_peak = $this->sanitize_non_negative_int_metric( $metrics, 'memory_peak_bytes' );
		if ( null !== $memory_peak ) {
			$set_parts[] = 'memory_peak_bytes = %d';
			$params[]    = $memory_peak;
		}

		return $this->update_with_metrics( $run_id, $set_parts, $metrics, $params );
	}

	/**
	 * Returns recent run rows.
	 *
	 * @param int $limit Max number of rows.
	 * @return array
	 */
	public function get_recent( int $limit = 10 ): array {
		global $wpdb;

		$table = CIE_DB_Migrator::get_table_name( 'rebuild_log' );
		$limit = max( 1, absint( $limit ) );
		$query = $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY started_at DESC LIMIT %d",
			$limit
		);

		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return $rows;
	}

	/**
	 * Prunes old rebuild log rows while keeping the most recent runs.
	 *
	 * @param int $keep_runs Number of recent rows to keep.
	 * @return int
	 */
	public function prune_old_runs( int $keep_runs = 90 ): int {
		global $wpdb;

		$table     = CIE_DB_Migrator::get_table_name( 'rebuild_log' );
		$keep_runs = max( 1, absint( $keep_runs ) );
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $total <= $keep_runs ) {
			return 0;
		}

		$query = $wpdb->prepare(
			"DELETE FROM {$table}
			WHERE id NOT IN (
				SELECT id FROM (
					SELECT id
					FROM {$table}
					ORDER BY started_at DESC, id DESC
					LIMIT %d
				) AS keep_rows
			)",
			$keep_runs
		);

		$result = $wpdb->query( $query );
		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Performs a metrics update with optional fixed assignments.
	 *
	 * @param string $run_id          Run UUID.
	 * @param array  $fixed_set_parts SQL set parts without run metrics.
	 * @param array  $metrics         Metrics array.
	 * @param array  $params          Prepared statement parameters.
	 * @return bool
	 */
	private function update_with_metrics( string $run_id, array $fixed_set_parts, array $metrics, array $params = array() ): bool {
		global $wpdb;

		$table     = CIE_DB_Migrator::get_table_name( 'rebuild_log' );
		$set_parts = $fixed_set_parts;

		$counter_keys = array(
			'orders_processed',
			'baskets_processed',
			'pairs_counted',
			'associations_written',
		);

		foreach ( $counter_keys as $metric_key ) {
			$value = $this->sanitize_non_negative_int_metric( $metrics, $metric_key );
			if ( null === $value ) {
				continue;
			}

			$set_parts[] = "{$metric_key} = %d";
			$params[]    = $value;
		}

		if ( empty( $set_parts ) ) {
			return false;
		}

		$params[] = $run_id;

		$query = $wpdb->prepare(
			"UPDATE {$table} SET " . implode( ', ', $set_parts ) . ' WHERE run_id = %s',
			$params
		);

		$result = $wpdb->query( $query );
		if ( false === $result ) {
			return false;
		}

		return true;
	}

	/**
	 * Sanitizes a non-negative integer metric.
	 *
	 * @param array  $metrics Metrics array.
	 * @param string $key     Metric key.
	 * @return int|null
	 */
	private function sanitize_non_negative_int_metric( array $metrics, string $key ): ?int {
		if ( ! array_key_exists( $key, $metrics ) ) {
			return null;
		}

		if ( is_array( $metrics[ $key ] ) || is_object( $metrics[ $key ] ) ) {
			return null;
		}

		return absint( $metrics[ $key ] );
	}
}
