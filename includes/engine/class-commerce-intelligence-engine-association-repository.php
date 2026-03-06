<?php

/**
 * Association repository.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persists and serves association rows.
 */
class CIE_Association_Repository {

	/**
	 * Last database error captured by this repository.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Batch size for bulk upserts.
	 *
	 * @var int
	 */
	const UPSERT_BATCH_SIZE = 200;

	/**
	 * Returns raw association rows for a product.
	 *
	 * @param int $product_id Product ID.
	 * @param int $limit      Row limit.
	 * @return array
	 */
	public function get_for_product( int $product_id, int $limit ): array {
		global $wpdb;

		$product_id = absint( $product_id );
		$limit      = max( 1, absint( $limit ) );
		if ( $product_id <= 0 ) {
			return array();
		}

		$table = CIE_DB_Migrator::get_table_name( 'associations' );
		$query = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE product_id = %d ORDER BY score DESC LIMIT %d",
			$product_id,
			$limit
		);

		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return $rows;
	}

	/**
	 * Upserts rows into staging associations table.
	 *
	 * @param array $rows Rows to upsert.
	 * @return bool
	 */
	public function upsert_batch( array $rows ): bool {
		global $wpdb;

		if ( empty( $rows ) ) {
			return true;
		}

		$table          = CIE_DB_Migrator::get_table_name( 'associations_temp' );
		$sanitized_rows = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return false;
			}

			$sanitized_row = $this->sanitize_upsert_row( $row );
			if ( null === $sanitized_row ) {
				return false;
			}

			$sanitized_rows[] = $sanitized_row;
		}

		foreach ( array_chunk( $sanitized_rows, self::UPSERT_BATCH_SIZE ) as $chunk ) {
			$values_sql = array();
			$params     = array();

			foreach ( $chunk as $row ) {
				$values_sql[] = '(%d, %d, %d, %f, %f, %f, %f, %s, NULLIF(%s, \'\'), %s)';
				$params[]     = $row['product_id'];
				$params[]     = $row['associated_product_id'];
				$params[]     = $row['co_occurrence_count'];
				$params[]     = $row['support'];
				$params[]     = $row['confidence'];
				$params[]     = $row['lift'];
				$params[]     = $row['score'];
				$params[]     = $row['source'];
				$params[]     = $row['last_seen_at'];
				$params[]     = $row['updated_at'];
			}

			$query = "
				INSERT INTO {$table}
					(product_id, associated_product_id, co_occurrence_count, support, confidence, lift, score, source, last_seen_at, updated_at)
				VALUES " . implode( ', ', $values_sql ) . "
				ON DUPLICATE KEY UPDATE
					co_occurrence_count = VALUES(co_occurrence_count),
					support = VALUES(support),
					confidence = VALUES(confidence),
					lift = VALUES(lift),
					score = VALUES(score),
					source = VALUES(source),
					last_seen_at = VALUES(last_seen_at),
					updated_at = VALUES(updated_at)
			";

			$prepared = $this->prepare_query( $query, $params );
			$result   = $wpdb->query( $prepared );

			if ( false === $result ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Swaps temp associations into live table.
	 *
	 * @return bool
	 */
	public function replace_from_temp(): bool {
		global $wpdb;

		$table_live = $wpdb->prefix . 'ci_associations';
		$table_temp = $wpdb->prefix . 'ci_associations_temp';

		if ( ! $this->table_exists( $table_temp ) ) {
			$this->last_error = 'Missing temp associations table.';
			return false;
		}

		// Cold-start path: no live table exists yet, so promote temp directly.
		if ( ! $this->table_exists( $table_live ) ) {
			$rename_cold_start_query = "RENAME TABLE {$table_temp} TO {$table_live}";
			if ( false === $wpdb->query( $rename_cold_start_query ) ) {
				$this->last_error = (string) $wpdb->last_error;
				return false;
			}

			$recreate_temp_query = "CREATE TABLE {$table_temp} LIKE {$table_live}";
			if ( false === $wpdb->query( $recreate_temp_query ) ) {
				$this->last_error = (string) $wpdb->last_error;
				return false;
			}

			$this->last_error = '';
			return true;
		}

		$temp_rows_before_swap = $this->count_rows( $table_temp );
		if ( $temp_rows_before_swap < 0 ) {
			$this->last_error = (string) $wpdb->last_error;
			return false;
		}

		$table_swap = $this->resolve_swap_table_name( $table_live );
		if ( '' === $table_swap ) {
			$this->last_error = 'Could not resolve swap table name.';
			return false;
		}

		$rename_query = "RENAME TABLE {$table_live} TO {$table_swap}, {$table_temp} TO {$table_live}, {$table_swap} TO {$table_temp}";
		$renamed      = $wpdb->query( $rename_query );

		if ( false === $renamed ) {
			$rename_error = (string) $wpdb->last_error;
			$fallback_ok  = $this->fallback_replace_from_temp( $table_live, $table_temp );

			if ( ! $fallback_ok ) {
				$this->last_error = (string) $wpdb->last_error;
				return false;
			}

			error_log( 'CIE degraded mode: atomic swap failed, fallback truncate+insert used. ' . $rename_error );
			$this->last_error = '';
			return true;
		}

		$live_rows_after_swap = $this->count_rows( $table_live );
		if ( $live_rows_after_swap < 0 ) {
			$this->last_error = (string) $wpdb->last_error;
			return false;
		}

		// Defensive check: if swap reports success but live is still empty while temp had rows,
		// force the fallback copy to guarantee live reads new associations.
		if ( $temp_rows_before_swap > 0 && 0 === $live_rows_after_swap ) {
			$fallback_ok = $this->fallback_replace_from_temp( $table_live, $table_temp );
			if ( ! $fallback_ok ) {
				$this->last_error = (string) $wpdb->last_error;
				return false;
			}
		}

		$this->last_error = '';
		return true;
	}

	/**
	 * Deletes stale association rows.
	 *
	 * @param array $valid_product_ids Valid product IDs.
	 * @return int
	 */
	public function delete_stale( array $valid_product_ids ): int {
		global $wpdb;

		$table             = CIE_DB_Migrator::get_table_name( 'associations' );
		$valid_product_ids = $this->sanitize_id_list( $valid_product_ids );

		if ( empty( $valid_product_ids ) ) {
			$result = $wpdb->query( "DELETE FROM {$table}" );
			if ( false === $result ) {
				return 0;
			}

			return (int) $wpdb->rows_affected;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $valid_product_ids ), '%d' ) );
		$query        = $this->prepare_query(
			"DELETE FROM {$table} WHERE product_id NOT IN ({$placeholders})",
			$valid_product_ids
		);
		$result       = $wpdb->query( $query );

		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Truncates temp association table.
	 *
	 * @return bool
	 */
	public function truncate_temp(): bool {
		global $wpdb;

		$table  = CIE_DB_Migrator::get_table_name( 'associations_temp' );
		$result = $wpdb->query( "TRUNCATE TABLE {$table}" );
		if ( false !== $result ) {
			$this->last_error = '';
			return true;
		}

		$table_live = CIE_DB_Migrator::get_table_name( 'associations' );
		$created    = $wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} LIKE {$table_live}" );
		if ( false === $created ) {
			$this->last_error = (string) $wpdb->last_error;
			return false;
		}

		$result = $wpdb->query( "TRUNCATE TABLE {$table}" );
		if ( false === $result ) {
			$this->last_error = (string) $wpdb->last_error;
			return false;
		}

		$this->last_error = '';
		return true;
	}

	/**
	 * Fallback path when RENAME swap fails.
	 *
	 * @param string $table_live Live table name.
	 * @param string $table_temp Temp table name.
	 * @return bool
	 */
	private function fallback_replace_from_temp( string $table_live, string $table_temp ): bool {
		global $wpdb;

		$truncate_query = "TRUNCATE TABLE {$table_live}";
		$truncated      = $wpdb->query( $truncate_query );

		if ( false === $truncated ) {
			return false;
		}

		$insert_query = "
			INSERT INTO {$table_live}
				(product_id, associated_product_id, co_occurrence_count, support, confidence, lift, score, source, last_seen_at, updated_at)
			SELECT
				product_id, associated_product_id, co_occurrence_count, support, confidence, lift, score, source, last_seen_at, updated_at
			FROM {$table_temp}
		";
		$inserted     = $wpdb->query( $insert_query );

		return false !== $inserted;
	}

	/**
	 * Resolves a unique swap table name for atomic rename operations.
	 *
	 * @param string $table_live Live table name.
	 * @return string
	 */
	private function resolve_swap_table_name( string $table_live ): string {
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$candidate = $table_live . '_swap_' . substr( md5( uniqid( '', true ) ), 0, 8 );
			if ( ! $this->table_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Returns row count for a table, or -1 on query failure.
	 *
	 * @param string $table_name Table name.
	 * @return int
	 */
	private function count_rows( string $table_name ): int {
		global $wpdb;

		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		if ( null === $count ) {
			return -1;
		}

		return (int) $count;
	}

	/**
	 * Returns true when a database table exists.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private function table_exists( string $table_name ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$table_name
			)
		);

		return $count > 0;
	}

	/**
	 * Returns the last repository DB error message.
	 *
	 * @return string
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Sanitizes row for upsert.
	 *
	 * @param array $row Raw row.
	 * @return array|null
	 */
	private function sanitize_upsert_row( array $row ): ?array {
		$required_keys = array(
			'product_id',
			'associated_product_id',
			'co_occurrence_count',
			'support',
			'confidence',
			'lift',
			'score',
			'source',
			'updated_at',
		);

		foreach ( $required_keys as $required_key ) {
			if ( ! array_key_exists( $required_key, $row ) ) {
				return null;
			}
		}

		$product_id            = absint( $row['product_id'] );
		$associated_product_id = absint( $row['associated_product_id'] );
		$co_occurrence_count   = absint( $row['co_occurrence_count'] );

		if ( $product_id <= 0 || $associated_product_id <= 0 ) {
			return null;
		}

		$updated_at = $this->normalize_required_datetime( $row['updated_at'] );
		if ( null === $updated_at ) {
			return null;
		}

		$source = sanitize_key( (string) $row['source'] );
		if ( '' === $source ) {
			$source = 'mined';
		}

		$last_seen_at = '';
		if ( array_key_exists( 'last_seen_at', $row ) ) {
			$normalized_last_seen = $this->normalize_nullable_datetime( $row['last_seen_at'] );
			if ( null !== $normalized_last_seen ) {
				$last_seen_at = $normalized_last_seen;
			}
		}

		return array(
			'product_id'            => $product_id,
			'associated_product_id' => $associated_product_id,
			'co_occurrence_count'   => $co_occurrence_count,
			'support'               => (float) $row['support'],
			'confidence'            => (float) $row['confidence'],
			'lift'                  => (float) $row['lift'],
			'score'                 => (float) $row['score'],
			'source'                => $source,
			'last_seen_at'          => $last_seen_at,
			'updated_at'            => $updated_at,
		);
	}

	/**
	 * Sanitizes list of positive IDs.
	 *
	 * @param array $ids Raw IDs.
	 * @return array
	 */
	private function sanitize_id_list( array $ids ): array {
		$sanitized = array();

		foreach ( $ids as $id ) {
			$int_id = absint( $id );
			if ( $int_id <= 0 ) {
				continue;
			}

			$sanitized[] = $int_id;
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Normalizes required datetime string.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function normalize_required_datetime( $value ): ?string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $value );
		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Normalizes nullable datetime string.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function normalize_nullable_datetime( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return '';
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $value );
		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Prepares SQL with dynamic placeholder counts.
	 *
	 * @param string $query  SQL query.
	 * @param array  $params Query params.
	 * @return string
	 */
	private function prepare_query( string $query, array $params ): string {
		global $wpdb;

		return (string) call_user_func_array(
			array( $wpdb, 'prepare' ),
			array_merge( array( $query ), $params )
		);
	}
}
