<?php

/**
 * Pair counter for canonical co-occurrence counts.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Counts and persists canonical product pairs.
 */
class CIE_Pair_Counter {

	/**
	 * Counts canonical unordered pairs from baskets.
	 *
	 * @param array $baskets Product baskets.
	 * @return array
	 */
	public function count_pairs( array $baskets ): array {
		$pair_counts = array();

		foreach ( $baskets as $basket ) {
			if ( ! is_array( $basket ) ) {
				continue;
			}

			$product_ids = $this->sanitize_product_ids( $basket );
			$count       = count( $product_ids );

			if ( $count < 2 ) {
				continue;
			}

			for ( $i = 0; $i < $count - 1; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$product_a = (int) $product_ids[ $i ];
					$product_b = (int) $product_ids[ $j ];

					if ( $product_a === $product_b ) {
						continue;
					}

					$min_id = min( $product_a, $product_b );
					$max_id = max( $product_a, $product_b );
					$key    = $min_id . ':' . $max_id;

					if ( ! isset( $pair_counts[ $key ] ) ) {
						$pair_counts[ $key ] = 0;
					}

					$pair_counts[ $key ]++;
				}
			}
		}

		return $pair_counts;
	}

	/**
	 * Flushes pair counts to staging table.
	 *
	 * @param array $pair_counts Canonical pair counts.
	 * @return bool
	 */
	public function flush_counts( array $pair_counts ): bool {
		global $wpdb;

		$table = CIE_DB_Migrator::get_table_name( 'pair_counts' );
		$rows  = array();

		foreach ( $pair_counts as $pair_key => $pair_count ) {
			$parsed = $this->parse_pair_key( (string) $pair_key );
			if ( null === $parsed ) {
				continue;
			}

			$count = absint( $pair_count );
			if ( $count <= 0 ) {
				continue;
			}

			$rows[] = array(
				'product_a'  => $parsed['product_a'],
				'product_b'  => $parsed['product_b'],
				'pair_count' => $count,
			);
		}

		if ( empty( $rows ) ) {
			return true;
		}

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values_sql = array();
			$params     = array();

			foreach ( $chunk as $row ) {
				$values_sql[] = '(%d, %d, %d, NOW())';
				$params[]     = $row['product_a'];
				$params[]     = $row['product_b'];
				$params[]     = $row['pair_count'];
			}

			$query = "
				INSERT INTO {$table} (product_a, product_b, pair_count, updated_at)
				VALUES " . implode( ', ', $values_sql ) . '
				ON DUPLICATE KEY UPDATE
					pair_count = pair_count + VALUES(pair_count),
					updated_at = NOW()
			';

			$prepared = $this->prepare_query( $query, $params );
			$result   = $wpdb->query( $prepared );

			if ( false === $result ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Truncates pair counts staging table.
	 *
	 * @return bool
	 */
	public function truncate_counts(): bool {
		global $wpdb;

		$table  = CIE_DB_Migrator::get_table_name( 'pair_counts' );
		$result = $wpdb->query( "TRUNCATE TABLE {$table}" );

		return false !== $result;
	}

	/**
	 * Returns all canonical counts from DB.
	 *
	 * @return array
	 */
	public function get_all_counts(): array {
		global $wpdb;

		$table = CIE_DB_Migrator::get_table_name( 'pair_counts' );
		$rows  = $wpdb->get_results(
			"SELECT product_a, product_b, pair_count FROM {$table}",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$output = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$product_a = isset( $row['product_a'] ) ? absint( $row['product_a'] ) : 0;
			$product_b = isset( $row['product_b'] ) ? absint( $row['product_b'] ) : 0;
			$count     = isset( $row['pair_count'] ) ? absint( $row['pair_count'] ) : 0;

			if ( $product_a <= 0 || $product_b <= 0 || $product_a === $product_b ) {
				continue;
			}

			$key           = min( $product_a, $product_b ) . ':' . max( $product_a, $product_b );
			$output[ $key ] = $count;
		}

		return $output;
	}

	/**
	 * Sanitizes a basket product list to unique positive integers.
	 *
	 * @param array $product_ids Product IDs.
	 * @return array
	 */
	private function sanitize_product_ids( array $product_ids ): array {
		$sanitized = array();

		foreach ( $product_ids as $product_id ) {
			$id = absint( $product_id );
			if ( $id <= 0 ) {
				continue;
			}

			$sanitized[] = $id;
		}

		$sanitized = array_values( array_unique( $sanitized ) );
		sort( $sanitized );

		return $sanitized;
	}

	/**
	 * Parses a canonical pair key.
	 *
	 * @param string $pair_key Canonical key.
	 * @return array|null
	 */
	private function parse_pair_key( string $pair_key ): ?array {
		$parts = explode( ':', $pair_key );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$product_a = absint( $parts[0] );
		$product_b = absint( $parts[1] );

		if ( $product_a <= 0 || $product_b <= 0 || $product_a === $product_b ) {
			return null;
		}

		return array(
			'product_a' => min( $product_a, $product_b ),
			'product_b' => max( $product_a, $product_b ),
		);
	}

	/**
	 * Prepares SQL with dynamic parameter lists.
	 *
	 * @param string $query  SQL query.
	 * @param array  $params Query parameters.
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
