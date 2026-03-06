<?php

/**
 * Data extractor for rebuild pipeline.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extracts order lines and product metadata for mining.
 */
class CIE_Data_Extractor {

	/**
	 * Allowed WooCommerce order statuses for mining.
	 *
	 * @var string[]
	 */
	const STATUS_ALLOWLIST = array(
		'wc-completed',
		'wc-processing',
	);

	/**
	 * Meta keys required by scorer.
	 *
	 * @var string[]
	 */
	const META_KEYS = array(
		'_price',
		'_stock',
		'_manage_stock',
		'_wc_cog_cost',
	);

	/**
	 * Fetches order lines using keyset pagination.
	 *
	 * @param int $lookback_days  Lookback window in days.
	 * @param int $after_order_id Cursor order ID.
	 * @param int $chunk_size     Max rows to fetch.
	 * @return array
	 */
	public function fetch_order_lines_since( int $lookback_days, int $after_order_id, int $chunk_size ): array {
		global $wpdb;

		$lookback_days  = max( 1, absint( $lookback_days ) );
		$after_order_id = max( 0, absint( $after_order_id ) );
		$chunk_size     = max( 1, absint( $chunk_size ) );
		$cutoff_datetime = $this->calculate_lookback_cutoff_datetime( $lookback_days );

		$statuses            = $this->get_included_statuses();
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$order_lookup_table  = $wpdb->prefix . 'wc_order_product_lookup';
		$order_stats_table   = $wpdb->prefix . 'wc_order_stats';

		$query = "
			SELECT ol.order_id, ol.product_id, os.date_created AS order_created_at
			FROM {$order_lookup_table} AS ol
			INNER JOIN {$order_stats_table} AS os ON ol.order_id = os.order_id
			WHERE os.status IN ({$status_placeholders})
				AND os.date_created >= %s
				AND ol.order_id > %d
			ORDER BY ol.order_id ASC
			LIMIT %d
		";

		$params = array_merge(
			$statuses,
			array(
				$cutoff_datetime,
				$after_order_id,
				$chunk_size,
			)
		);

		$prepared = $this->prepare_query( $query, $params );
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$output = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$order_id   = isset( $row['order_id'] ) ? absint( $row['order_id'] ) : 0;
			$product_id = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;

			if ( $order_id <= 0 || $product_id <= 0 ) {
				continue;
			}

			$output[] = array(
				'order_id'         => $order_id,
				'product_id'       => $product_id,
				'order_created_at' => isset( $row['order_created_at'] ) ? $this->normalize_datetime_string( (string) $row['order_created_at'] ) : '',
			);
		}

		return $output;
	}

	/**
	 * Normalizes a product ID according to variation mode.
	 *
	 * @param int    $product_id     Product ID.
	 * @param string $variation_mode Variation mode.
	 * @return int
	 */
	public function normalize_product_id( int $product_id, string $variation_mode ): int {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return 0;
		}

		if ( 'parent' !== $variation_mode ) {
			return $product_id;
		}

		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $parent_id > 0 ) {
			return (int) $parent_id;
		}

		return $product_id;
	}

	/**
	 * Builds unique product baskets by order.
	 *
	 * @param array $order_lines         Extracted order lines.
	 * @param array $basket_last_seen_at Optional output map aligned to basket index.
	 * @return array
	 */
	public function build_baskets( array $order_lines, array &$basket_last_seen_at = array() ): array {
		$settings       = CIE_Settings::get();
		$variation_mode = isset( $settings['variation_mode'] ) && 'individual' === $settings['variation_mode']
			? 'individual'
			: 'parent';

		$grouped = array();
		$order_last_seen_map = array();
		$basket_last_seen_at = array();

		foreach ( $order_lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$order_id   = isset( $line['order_id'] ) ? absint( $line['order_id'] ) : 0;
			$product_id = isset( $line['product_id'] ) ? absint( $line['product_id'] ) : 0;

			if ( $order_id <= 0 || $product_id <= 0 ) {
				continue;
			}

			$normalized_product_id = $this->normalize_product_id( $product_id, $variation_mode );
			if ( $normalized_product_id <= 0 ) {
				continue;
			}

			if ( ! isset( $grouped[ $order_id ] ) ) {
				$grouped[ $order_id ] = array();
			}

			$grouped[ $order_id ][ $normalized_product_id ] = true;

			$order_created_at = isset( $line['order_created_at'] ) ? $this->normalize_datetime_string( (string) $line['order_created_at'] ) : '';
			if ( '' !== $order_created_at ) {
				if ( ! isset( $order_last_seen_map[ $order_id ] ) || $order_created_at > $order_last_seen_map[ $order_id ] ) {
					$order_last_seen_map[ $order_id ] = $order_created_at;
				}
			}
		}

		$excluded_category_ids = $this->get_excluded_category_ids( $settings );
		$excluded_lookup       = array();
		if ( ! empty( $excluded_category_ids ) ) {
			$excluded_lookup = $this->build_excluded_product_lookup(
				$this->collect_grouped_product_ids( $grouped ),
				$excluded_category_ids
			);
		}

		$baskets = array();

		foreach ( $grouped as $order_id => $product_map ) {
			$product_ids = array_map( 'intval', array_keys( $product_map ) );
			sort( $product_ids );
			$product_ids = $this->filter_excluded_product_ids( $product_ids, $excluded_category_ids, $excluded_lookup );
			if ( empty( $product_ids ) ) {
				continue;
			}

			// Callbacks must not execute DB queries — data must be preloaded.
			$filtered = apply_filters( 'cie_basket_product_ids', $product_ids, (int) $order_id );
			if ( ! is_array( $filtered ) ) {
				$filtered = $product_ids;
			}

			$filtered = $this->sanitize_integer_list( $filtered );
			$filtered = $this->filter_excluded_product_ids( $filtered, $excluded_category_ids, $excluded_lookup );

			if ( empty( $filtered ) ) {
				continue;
			}

			$baskets[] = $filtered;
			$basket_last_seen_at[] = isset( $order_last_seen_map[ $order_id ] ) ? $order_last_seen_map[ $order_id ] : '';
		}

		return $baskets;
	}

	/**
	 * Fetches scorer meta map in bulk.
	 *
	 * @param array $product_ids Product IDs.
	 * @return array
	 */
	public function fetch_meta_map( array $product_ids ): array {
		global $wpdb;

		$product_ids = $this->sanitize_integer_list( $product_ids );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$meta_map = array();

		foreach ( $product_ids as $product_id ) {
			$meta_map[ $product_id ] = array(
				'_price'       => '',
				'_stock'       => '',
				'_manage_stock'=> '',
				'_wc_cog_cost' => '',
			);
		}

		$meta_placeholders = implode( ', ', array_fill( 0, count( self::META_KEYS ), '%s' ) );

		foreach ( array_chunk( $product_ids, 500 ) as $chunk ) {
			$id_placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$query           = "
				SELECT post_id, meta_key, meta_value
				FROM {$wpdb->postmeta}
				WHERE post_id IN ({$id_placeholders})
					AND meta_key IN ({$meta_placeholders})
			";
			$params          = array_merge( $chunk, self::META_KEYS );
			$prepared        = $this->prepare_query( $query, $params );
			$rows            = $wpdb->get_results( $prepared, ARRAY_A );

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$post_id  = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
				$meta_key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';

				if ( $post_id <= 0 || ! in_array( $meta_key, self::META_KEYS, true ) ) {
					continue;
				}

				$meta_value = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
				$meta_map[ $post_id ][ $meta_key ] = $meta_value;
			}
		}

		return $meta_map;
	}

	/**
	 * Resolves included statuses from settings against allowlist.
	 *
	 * @return array
	 */
	private function get_included_statuses(): array {
		$settings = CIE_Settings::get();
		$statuses = array();

		if ( isset( $settings['included_statuses'] ) && is_array( $settings['included_statuses'] ) ) {
			foreach ( $settings['included_statuses'] as $status ) {
				if ( ! is_scalar( $status ) ) {
					continue;
				}

				$value = sanitize_key( (string) $status );
				if ( in_array( $value, self::STATUS_ALLOWLIST, true ) ) {
					$statuses[] = $value;
				}
			}
		}

		$statuses = array_values( array_unique( $statuses ) );

		if ( empty( $statuses ) ) {
			$statuses[] = 'wc-completed';
		}

		return $statuses;
	}

	/**
	 * Returns sanitized excluded product category IDs from settings.
	 *
	 * @param array $settings Settings payload.
	 * @return array
	 */
	private function get_excluded_category_ids( array $settings ): array {
		if ( ! isset( $settings['excluded_category_ids'] ) || ! is_array( $settings['excluded_category_ids'] ) ) {
			return array();
		}

		return $this->sanitize_integer_list( $settings['excluded_category_ids'] );
	}

	/**
	 * Builds lookup map of product IDs that belong to excluded categories.
	 *
	 * @param array $product_ids            Product IDs to evaluate.
	 * @param array $excluded_category_ids  Excluded category IDs.
	 * @return array
	 */
	private function build_excluded_product_lookup( array $product_ids, array $excluded_category_ids ): array {
		global $wpdb;

		$product_ids           = $this->sanitize_integer_list( $product_ids );
		$excluded_category_ids = $this->sanitize_integer_list( $excluded_category_ids );

		if ( empty( $product_ids ) || empty( $excluded_category_ids ) ) {
			return array();
		}

		$lookup             = array();
		$category_sql       = implode( ', ', array_fill( 0, count( $excluded_category_ids ), '%d' ) );

		foreach ( array_chunk( $product_ids, 500 ) as $product_chunk ) {
			$product_sql = implode( ', ', array_fill( 0, count( $product_chunk ), '%d' ) );
			$query       = "
				SELECT DISTINCT tr.object_id AS product_id
				FROM {$wpdb->term_relationships} AS tr
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tr.object_id IN ({$product_sql})
					AND tt.taxonomy = 'product_cat'
					AND tt.term_id IN ({$category_sql})
			";
			$params      = array_merge( $product_chunk, $excluded_category_ids );
			$prepared    = $this->prepare_query( $query, $params );
			$rows        = $wpdb->get_results( $prepared, ARRAY_A );

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['product_id'] ) ) {
					continue;
				}

				$product_id = absint( $row['product_id'] );
				if ( $product_id > 0 ) {
					$lookup[ $product_id ] = true;
				}
			}
		}

		return $lookup;
	}

	/**
	 * Filters product IDs to exclude products in configured categories.
	 *
	 * @param array $product_ids            Candidate product IDs.
	 * @param array $excluded_category_ids  Excluded category IDs.
	 * @param array $excluded_lookup        Cached exclusion lookup by reference.
	 * @return array
	 */
	private function filter_excluded_product_ids( array $product_ids, array $excluded_category_ids, array &$excluded_lookup ): array {
		if ( empty( $excluded_category_ids ) ) {
			return $this->sanitize_integer_list( $product_ids );
		}

		$filtered = array();

		foreach ( $product_ids as $product_id ) {
			$pid = absint( $product_id );
			if ( $pid <= 0 ) {
				continue;
			}

			if ( array_key_exists( $pid, $excluded_lookup ) ) {
				if ( true === $excluded_lookup[ $pid ] ) {
					continue;
				}

				$filtered[] = $pid;
				continue;
			}

			$is_excluded            = $this->is_product_in_excluded_categories( $pid, $excluded_category_ids );
			$excluded_lookup[ $pid ] = $is_excluded;
			if ( $is_excluded ) {
				continue;
			}

			$filtered[] = $pid;
		}

		return $this->sanitize_integer_list( $filtered );
	}

	/**
	 * Determines whether a product belongs to any excluded category.
	 *
	 * @param int   $product_id            Product ID.
	 * @param array $excluded_category_ids Excluded category IDs.
	 * @return bool
	 */
	private function is_product_in_excluded_categories( int $product_id, array $excluded_category_ids ): bool {
		if ( $product_id <= 0 || empty( $excluded_category_ids ) || ! function_exists( 'wp_get_post_terms' ) ) {
			return false;
		}

		$category_ids = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( ( ! is_array( $category_ids ) || empty( $category_ids ) ) && function_exists( 'wp_get_post_parent_id' ) ) {
			$parent_id = absint( wp_get_post_parent_id( $product_id ) );
			if ( $parent_id > 0 ) {
				$category_ids = wp_get_post_terms( $parent_id, 'product_cat', array( 'fields' => 'ids' ) );
			}
		}

		if ( ! is_array( $category_ids ) || empty( $category_ids ) ) {
			return false;
		}

		$category_ids = $this->sanitize_integer_list( $category_ids );

		return ! empty( array_intersect( $category_ids, $excluded_category_ids ) );
	}

	/**
	 * Extracts unique product IDs from grouped baskets map.
	 *
	 * @param array $grouped Grouped baskets keyed by order.
	 * @return array
	 */
	private function collect_grouped_product_ids( array $grouped ): array {
		$product_ids = array();

		foreach ( $grouped as $product_map ) {
			if ( ! is_array( $product_map ) ) {
				continue;
			}

			$product_ids = array_merge( $product_ids, array_keys( $product_map ) );
		}

		return $this->sanitize_integer_list( $product_ids );
	}

	/**
	 * Sanitizes integer list.
	 *
	 * @param array $values Input values.
	 * @return array
	 */
	private function sanitize_integer_list( array $values ): array {
		$sanitized = array();

		foreach ( $values as $value ) {
			$int_value = absint( $value );
			if ( $int_value <= 0 ) {
				continue;
			}

			$sanitized[] = $int_value;
		}

		$sanitized = array_values( array_unique( $sanitized ) );
		sort( $sanitized );

		return $sanitized;
	}

	/**
	 * Normalizes datetime strings to UTC MySQL format.
	 *
	 * @param string $raw_datetime Raw datetime value.
	 * @return string
	 */
	private function normalize_datetime_string( string $raw_datetime ): string {
		$raw_datetime = trim( $raw_datetime );
		if ( '' === $raw_datetime ) {
			return '';
		}

		$timestamp = strtotime( $raw_datetime );
		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Calculates lookback cutoff in WordPress site timezone.
	 *
	 * Avoids relying on MySQL server timezone when filtering wc_order_stats rows.
	 *
	 * @param int $lookback_days Lookback window in days.
	 * @return string
	 */
	private function calculate_lookback_cutoff_datetime( int $lookback_days ): string {
		$lookback_days = max( 1, absint( $lookback_days ) );

		try {
			$timezone = function_exists( 'wp_timezone' )
				? wp_timezone()
				: new DateTimeZone( 'UTC' );

			$now_string = function_exists( 'current_time' )
				? (string) current_time( 'mysql' )
				: gmdate( 'Y-m-d H:i:s' );
			$now        = new DateTimeImmutable( $now_string, $timezone );
			$cutoff     = $now->sub( new DateInterval( 'P' . $lookback_days . 'D' ) );

			return $cutoff->format( 'Y-m-d H:i:s' );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return gmdate( 'Y-m-d H:i:s', time() - ( $lookback_days * DAY_IN_SECONDS ) );
		}
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
