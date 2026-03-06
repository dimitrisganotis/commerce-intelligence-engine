<?php

/**
 * Recommendation service.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds recommendation results with fallback chain and caching.
 */
class CIE_Recommender {

	/**
	 * Source priority order.
	 *
	 * @var string[]
	 */
	const SOURCE_PRIORITY = array(
		'mined',
		'cross_sell',
		'category_bestseller',
		'global_bestseller',
	);

	/**
	 * Category/global bestseller window in days.
	 *
	 * @var int
	 */
	const BESTSELLER_WINDOW_DAYS = 90;

	/**
	 * Association repository.
	 *
	 * @var CIE_Association_Repository
	 */
	private $repo;

	/**
	 * Cache wrapper.
	 *
	 * @var CIE_Cache
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param CIE_Association_Repository $repo  Repository.
	 * @param CIE_Cache                  $cache Cache service.
	 */
	public function __construct( CIE_Association_Repository $repo, CIE_Cache $cache ) {
		$this->repo  = $repo;
		$this->cache = $cache;
	}

	/**
	 * Returns recommendations in RecommendationResult shape.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $context    Context.
	 * @param int    $limit      Display limit.
	 * @return array
	 */
	public function get_recommendations( int $product_id, string $context, int $limit ): array {
		$settings               = CIE_Settings::get();
		$product_id             = absint( $product_id );
		$context                = $this->normalize_context( $context );
		$limit                  = $this->resolve_limit( $limit, $settings );
		$excluded_category_ids  = $this->get_excluded_category_ids( $settings );
		$exclusion_signature    = $this->build_list_signature( $excluded_category_ids );

		$cache_key = CIE_Cache::make_key( 'cie_recs', (string) $product_id, $context, (string) $limit, $exclusion_signature );
		$cached    = $this->cache->get( $cache_key );

		if ( is_array( $cached ) ) {
			$cached['cache_hit'] = true;
			return $this->ensure_result_shape( $cached, $product_id, $context );
		}

		$base_product_id = $this->resolve_base_product_id( $product_id, $settings );
		$query_limit     = $limit * $this->resolve_query_headroom_multiplier( $settings );

		$query_args = apply_filters(
			'cie_recommendations_query_args',
			array(
				'product_id' => $base_product_id,
				'context'    => $context,
				'limit'      => $query_limit,
			),
			$base_product_id,
			$context
		);

		if ( ! is_array( $query_args ) ) {
			$query_args = array(
				'product_id' => $base_product_id,
				'context'    => $context,
				'limit'      => $query_limit,
			);
		}

		$query_product_id = isset( $query_args['product_id'] ) ? absint( $query_args['product_id'] ) : $base_product_id;
		$query_limit      = isset( $query_args['limit'] ) ? max( 1, absint( $query_args['limit'] ) ) : $query_limit;

		$rows  = $this->repo->get_for_product( $query_product_id, $query_limit );
		$items = $this->rows_to_items( $rows );
		$items = $this->filter_candidate_items( $items, $excluded_category_ids );

		$overrides = $this->get_overrides( $base_product_id );
		$items     = $this->apply_overrides( $items, $overrides );
		$items     = $this->apply_item_filter( $items, $base_product_id, $context );
		$items     = array_slice( $items, 0, $limit );

		if ( count( $items ) < 2 ) {
			$fallback_items = $this->get_fallback_recommendations( $base_product_id, $context, $limit );
			$items          = $this->merge_items_without_duplicates( $items, $fallback_items, $overrides['exclude_ids'] );
			$items          = array_slice( $items, 0, $limit );
		}

		foreach ( $items as $index => $item ) {
			$items[ $index ]['reason']      = $this->generate_reason( $item );
			$items[ $index ]['is_fallback'] = ( 'mined' !== $item['source'] );
		}

		$result = array(
			'product_id'   => $base_product_id,
			'context'      => $context,
			'source'       => $this->result_primary_source( $items ),
			'items'        => array_values( $items ),
			'generated_at' => $this->current_datetime(),
			'cache_hit'    => false,
		);

		$this->cache->set( $cache_key, $result, CIE_Cache::DEFAULT_TTL );

		return $result;
	}

	/**
	 * Returns flat fallback recommendation items.
	 *
	 * @param int    $product_id Base product ID.
	 * @param string $context    Context.
	 * @param int    $limit      Needed limit.
	 * @return array
	 */
	public function get_fallback_recommendations( int $product_id, string $context, int $limit ): array {
		$product_id = absint( $product_id );
		$context    = $this->normalize_context( $context );
		$limit      = max( 1, absint( $limit ) );

		$chain = apply_filters(
			'cie_fallback_chain',
			array( 'cross_sell', 'category_bestseller', 'global_bestseller' ),
			$product_id,
			$context
		);

		if ( ! is_array( $chain ) ) {
			$chain = array( 'cross_sell', 'category_bestseller', 'global_bestseller' );
		}

		$items                 = array();
		$seen_ids              = array();
		$remaining             = $limit;
		$excluded_category_ids = $this->get_excluded_category_ids();

		foreach ( $chain as $source ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$source_items = array();

			if ( 'cross_sell' === $source ) {
				$source_items = $this->get_cross_sell_items( $product_id, $remaining );
			} elseif ( 'category_bestseller' === $source ) {
				$source_items = $this->get_category_bestsellers( $product_id, $remaining );
			} elseif ( 'global_bestseller' === $source ) {
				$source_items = $this->get_global_bestsellers( $remaining );
			}

			foreach ( $source_items as $item ) {
				$item_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
				if ( $item_id <= 0 || isset( $seen_ids[ $item_id ] ) ) {
					continue;
				}

				$seen_ids[ $item_id ] = true;
				$items[]              = $item;
				$remaining--;

				if ( $remaining <= 0 ) {
					break;
				}
			}
		}

		return $this->filter_candidate_items( $items, $excluded_category_ids );
	}

	/**
	 * Returns category bestseller fallback items.
	 *
	 * @param int $product_id Product ID.
	 * @param int $limit      Limit.
	 * @return array
	 */
	public function get_category_bestsellers( int $product_id, int $limit ): array {
		global $wpdb;

		$product_id = absint( $product_id );
		$limit      = max( 1, absint( $limit ) );
		if ( $product_id <= 0 ) {
			return array();
		}

		$category_ids = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( ! is_array( $category_ids ) || empty( $category_ids ) ) {
			return array();
		}

		$excluded_category_ids = $this->get_excluded_category_ids();
		$exclusion_signature   = $this->build_list_signature( $excluded_category_ids );
		$category_ids          = $this->sanitize_integer_list( $category_ids );
		if ( ! empty( $excluded_category_ids ) ) {
			$category_ids = array_values( array_diff( $category_ids, $excluded_category_ids ) );
		}

		if ( empty( $category_ids ) ) {
			return array();
		}

		$statuses = $this->get_included_statuses();
		$items    = array();
		$seen     = array();

		foreach ( $category_ids as $category_id ) {
			$category_id = absint( $category_id );
			if ( $category_id <= 0 ) {
				continue;
			}

			$cache_key = CIE_Cache::make_key( 'cie_cat_best', (string) $category_id, (string) self::BESTSELLER_WINDOW_DAYS, $exclusion_signature );
			$cached    = $this->cache->get( $cache_key );
			$rows      = is_array( $cached ) ? $cached : null;

			if ( null === $rows ) {
				$args = apply_filters(
					'cie_category_bestseller_query_args',
					array(
						'category_id' => $category_id,
						'product_id'  => $product_id,
						'statuses'    => $statuses,
						'days'        => self::BESTSELLER_WINDOW_DAYS,
						'limit'       => $limit,
					),
					$product_id
				);

				if ( ! is_array( $args ) ) {
					$args = array(
						'category_id' => $category_id,
						'product_id'  => $product_id,
						'statuses'    => $statuses,
						'days'        => self::BESTSELLER_WINDOW_DAYS,
						'limit'       => $limit,
					);
				}

				$query_category_id = isset( $args['category_id'] ) ? absint( $args['category_id'] ) : $category_id;
				$query_product_id  = isset( $args['product_id'] ) ? absint( $args['product_id'] ) : $product_id;
				$query_days        = isset( $args['days'] ) ? max( 1, absint( $args['days'] ) ) : self::BESTSELLER_WINDOW_DAYS;
				$query_limit       = isset( $args['limit'] ) ? max( 1, absint( $args['limit'] ) ) : $limit;
				$query_statuses    = isset( $args['statuses'] ) && is_array( $args['statuses'] )
					? $this->sanitize_statuses( $args['statuses'] )
					: $statuses;
				$cutoff_datetime   = $this->calculate_cutoff_datetime( $query_days );

				$status_placeholders = implode( ', ', array_fill( 0, count( $query_statuses ), '%s' ) );
				$excluded_sql        = '';
				$excluded_params     = array();

				if ( ! empty( $excluded_category_ids ) ) {
					$excluded_placeholders = implode( ', ', array_fill( 0, count( $excluded_category_ids ), '%d' ) );
					$excluded_sql          = "
						AND NOT EXISTS (
							SELECT 1
							FROM {$wpdb->term_relationships} AS tr_ex
							INNER JOIN {$wpdb->term_taxonomy} AS tt_ex ON tt_ex.term_taxonomy_id = tr_ex.term_taxonomy_id
							WHERE tr_ex.object_id = ol.product_id
								AND tt_ex.taxonomy = 'product_cat'
								AND tt_ex.term_id IN ({$excluded_placeholders})
						)
					";
					$excluded_params       = $excluded_category_ids;
				}

				$query = "
					SELECT ol.product_id, COUNT(*) AS sales_count
					FROM {$wpdb->prefix}wc_order_product_lookup AS ol
					INNER JOIN {$wpdb->prefix}wc_order_stats AS os ON ol.order_id = os.order_id
					INNER JOIN {$wpdb->term_relationships} AS tr ON tr.object_id = ol.product_id
					INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tt.taxonomy = 'product_cat'
						AND tt.term_id IN (%d)
						AND ol.product_id <> %d
						AND os.status IN ({$status_placeholders})
						AND os.date_created >= %s
						{$excluded_sql}
						GROUP BY ol.product_id
						ORDER BY sales_count DESC
						LIMIT %d
				";

				$params = array_merge(
					array( $query_category_id, $query_product_id ),
					$query_statuses,
					array( $cutoff_datetime ),
					$excluded_params,
					array( $query_limit )
				);

				$prepared = $this->prepare_query( $query, $params );
				$rows     = $wpdb->get_results( $prepared, ARRAY_A );
				$rows     = is_array( $rows ) ? $rows : array();
				$this->cache->set( $cache_key, $rows, 6 * HOUR_IN_SECONDS );
			}

			foreach ( $rows as $row ) {
				$item_id = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
				if ( $item_id <= 0 || isset( $seen[ $item_id ] ) ) {
					continue;
				}

				$seen[ $item_id ] = true;
				$items[]          = $this->build_fallback_item( $item_id, 'category_bestseller', isset( $row['sales_count'] ) ? (float) $row['sales_count'] : 0.0 );
			}
		}

		return array_slice( $items, 0, $limit );
	}

	/**
	 * Returns global bestseller fallback items.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_global_bestsellers( int $limit ): array {
		global $wpdb;

		$limit                 = max( 1, absint( $limit ) );
		$statuses              = $this->get_included_statuses();
		$excluded_category_ids = $this->get_excluded_category_ids();
		$exclusion_signature   = $this->build_list_signature( $excluded_category_ids );
		$cache_key             = CIE_Cache::make_key( 'cie_global_best', (string) self::BESTSELLER_WINDOW_DAYS, $exclusion_signature );
		$cached    = $this->cache->get( $cache_key );
		$rows      = is_array( $cached ) ? $cached : null;

		if ( null === $rows ) {
			$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$cutoff_datetime    = $this->calculate_cutoff_datetime( self::BESTSELLER_WINDOW_DAYS );
			$excluded_sql        = '';
			$excluded_params     = array();

			if ( ! empty( $excluded_category_ids ) ) {
				$excluded_placeholders = implode( ', ', array_fill( 0, count( $excluded_category_ids ), '%d' ) );
				$excluded_sql          = "
					AND NOT EXISTS (
						SELECT 1
						FROM {$wpdb->term_relationships} AS tr_ex
						INNER JOIN {$wpdb->term_taxonomy} AS tt_ex ON tt_ex.term_taxonomy_id = tr_ex.term_taxonomy_id
						WHERE tr_ex.object_id = ol.product_id
							AND tt_ex.taxonomy = 'product_cat'
							AND tt_ex.term_id IN ({$excluded_placeholders})
					)
				";
				$excluded_params       = $excluded_category_ids;
			}

			$query = "
				SELECT ol.product_id, COUNT(*) AS sales_count
				FROM {$wpdb->prefix}wc_order_product_lookup AS ol
				INNER JOIN {$wpdb->prefix}wc_order_stats AS os ON ol.order_id = os.order_id
				WHERE os.status IN ({$status_placeholders})
					AND os.date_created >= %s
					{$excluded_sql}
				GROUP BY ol.product_id
				ORDER BY sales_count DESC
				LIMIT %d
			";
			$params              = array_merge( $statuses, array( $cutoff_datetime ), $excluded_params, array( $limit ) );
			$prepared            = $this->prepare_query( $query, $params );
			$rows                = $wpdb->get_results( $prepared, ARRAY_A );
			$rows                = is_array( $rows ) ? $rows : array();
			$this->cache->set( $cache_key, $rows, 6 * HOUR_IN_SECONDS );
		}

		$items = array();

		foreach ( $rows as $row ) {
			$item_id = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
			if ( $item_id <= 0 ) {
				continue;
			}

			$items[] = $this->build_fallback_item( $item_id, 'global_bestseller', isset( $row['sales_count'] ) ? (float) $row['sales_count'] : 0.0 );
		}

		return array_slice( $items, 0, $limit );
	}

	/**
	 * Generates human-readable reason text.
	 *
	 * @param array $item Recommendation item.
	 * @return string
	 */
	public function generate_reason( array $item ): string {
		$source = isset( $item['source'] ) ? (string) $item['source'] : 'mined';

		if ( 'cross_sell' === $source ) {
			return __( 'Store-defined cross-sell', 'commerce-intelligence-engine' );
		}

		if ( 'category_bestseller' === $source ) {
			return __( 'Popular in this category', 'commerce-intelligence-engine' );
		}

		if ( 'global_bestseller' === $source ) {
			return __( 'Popular store-wide', 'commerce-intelligence-engine' );
		}

		$confidence = isset( $item['confidence'] ) ? (float) $item['confidence'] : 0.0;
		$lift       = isset( $item['lift'] ) ? (float) $item['lift'] : 0.0;

		return sprintf(
			/* translators: 1: confidence percentage, 2: lift value */
			__( 'Bought together in %1$d%% of qualifying orders (Lift %2$sx).', 'commerce-intelligence-engine' ),
			(int) round( $confidence * 100 ),
			number_format_i18n( $lift, 2 )
		);
	}

	/**
	 * Resolves result primary source from items.
	 *
	 * @param array $items Recommendation items.
	 * @return string
	 */
	public function result_primary_source( array $items ): string {
		$available = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['source'] ) ) {
				continue;
			}

			$available[ (string) $item['source'] ] = true;
		}

		foreach ( self::SOURCE_PRIORITY as $source ) {
			if ( isset( $available[ $source ] ) ) {
				return $source;
			}
		}

		return 'none';
	}

	/**
	 * Maps repository rows to recommendation item shape.
	 *
	 * @param array $rows Raw association rows.
	 * @return array
	 */
	private function rows_to_items( array $rows ): array {
		$items = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$product_id = isset( $row['associated_product_id'] ) ? absint( $row['associated_product_id'] ) : 0;
			if ( $product_id <= 0 ) {
				continue;
			}

			$items[] = array(
				'product_id'  => $product_id,
				'score'       => isset( $row['score'] ) ? (float) $row['score'] : 0.0,
				'confidence'  => isset( $row['confidence'] ) ? (float) $row['confidence'] : 0.0,
				'lift'        => isset( $row['lift'] ) ? (float) $row['lift'] : 0.0,
				'reason'      => '',
				'source'      => isset( $row['source'] ) ? sanitize_key( (string) $row['source'] ) : 'mined',
				'is_fallback' => false,
			);
		}

		return $items;
	}

	/**
	 * Filters candidate items for publish/visibility/stock policies.
	 *
	 * @param array $items                 Candidate items.
	 * @param array $excluded_category_ids Excluded category IDs.
	 * @return array
	 */
	private function filter_candidate_items( array $items, array $excluded_category_ids = array() ): array {
		$filtered       = array();
		$hide_oos_items = ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' ) );

		foreach ( $items as $item ) {
			if ( ! $this->is_valid_item_shape( $item ) ) {
				continue;
			}

			$product_id = (int) $item['product_id'];
			if ( $this->is_product_in_excluded_categories( $product_id, $excluded_category_ids ) ) {
				continue;
			}

			if ( 'publish' !== get_post_status( $product_id ) ) {
				continue;
			}

			$visibility = (string) get_post_meta( $product_id, '_visibility', true );
			if ( 'hidden' === $visibility ) {
				continue;
			}

			if ( $hide_oos_items ) {
				$product = wc_get_product( $product_id );
				if ( $product && ! $product->is_in_stock() ) {
					continue;
				}
			}

			$filtered[] = $item;
		}

		return $filtered;
	}

	/**
	 * Applies pin/exclude overrides.
	 *
	 * @param array $items     Mined items.
	 * @param array $overrides Override map.
	 * @return array
	 */
	private function apply_overrides( array $items, array $overrides ): array {
		$exclude_ids = isset( $overrides['exclude_ids'] ) && is_array( $overrides['exclude_ids'] )
			? $overrides['exclude_ids']
			: array();
		$pin_ids     = isset( $overrides['pin_ids'] ) && is_array( $overrides['pin_ids'] )
			? $overrides['pin_ids']
			: array();

		$exclude_lookup = array();
		foreach ( $exclude_ids as $exclude_id ) {
			$exclude_lookup[ absint( $exclude_id ) ] = true;
		}

		$item_map = array();
		$order    = array();

		foreach ( $items as $item ) {
			$product_id = absint( $item['product_id'] );
			if ( $product_id <= 0 || isset( $exclude_lookup[ $product_id ] ) ) {
				continue;
			}

			$item_map[ $product_id ] = $item;
			$order[]                 = $product_id;
		}

		$ordered = array();

		foreach ( $pin_ids as $pin_id ) {
			$pin_id = absint( $pin_id );
			if ( $pin_id <= 0 || isset( $exclude_lookup[ $pin_id ] ) ) {
				continue;
			}

			if ( isset( $item_map[ $pin_id ] ) ) {
				$ordered[] = $item_map[ $pin_id ];
				unset( $item_map[ $pin_id ] );
				continue;
			}

			$pinned_item = $this->build_fallback_item( $pin_id, 'mined', 1.0 );
			if ( $this->is_valid_item_shape( $pinned_item ) ) {
				$ordered[] = $pinned_item;
			}
		}

		foreach ( $order as $product_id ) {
			if ( isset( $item_map[ $product_id ] ) ) {
				$ordered[] = $item_map[ $product_id ];
			}
		}

		return $ordered;
	}

	/**
	 * Loads override rows for a base product.
	 *
	 * @param int $base_product_id Base product ID.
	 * @return array
	 */
	private function get_overrides( int $base_product_id ): array {
		global $wpdb;

		$table = CIE_DB_Migrator::get_table_name( 'overrides' );
		$query = $wpdb->prepare(
			"SELECT associated_product_id, action FROM {$table} WHERE product_id = %d ORDER BY created_at ASC, id ASC",
			$base_product_id
		);
		$rows  = $wpdb->get_results( $query, ARRAY_A );

		$pin_ids     = array();
		$exclude_ids = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$item_id = isset( $row['associated_product_id'] ) ? absint( $row['associated_product_id'] ) : 0;
				$action  = isset( $row['action'] ) ? sanitize_key( (string) $row['action'] ) : '';

				if ( $item_id <= 0 ) {
					continue;
				}

				if ( 'pin' === $action ) {
					$pin_ids[] = $item_id;
				} elseif ( 'exclude' === $action ) {
					$exclude_ids[] = $item_id;
				}
			}
		}

		return array(
			'pin_ids'     => array_values( array_unique( $pin_ids ) ),
			'exclude_ids' => array_values( array_unique( $exclude_ids ) ),
		);
	}

	/**
	 * Applies item-level filter and drops malformed items.
	 *
	 * @param array  $items           Items.
	 * @param int    $base_product_id Base product ID.
	 * @param string $context         Context.
	 * @return array
	 */
	private function apply_item_filter( array $items, int $base_product_id, string $context ): array {
		$filtered = array();

		foreach ( $items as $item ) {
			$candidate = apply_filters( 'cie_recommendation_item', $item, $base_product_id, $context );
			if ( null === $candidate || ! is_array( $candidate ) ) {
				continue;
			}

			if ( ! $this->is_valid_item_shape( $candidate ) ) {
				continue;
			}

			$candidate['product_id']  = absint( $candidate['product_id'] );
			if ( $candidate['product_id'] <= 0 || $candidate['product_id'] === $base_product_id ) {
				continue;
			}

			$candidate['score']       = (float) $candidate['score'];
			$candidate['confidence']  = isset( $candidate['confidence'] ) ? (float) $candidate['confidence'] : 0.0;
			$candidate['lift']        = isset( $candidate['lift'] ) ? (float) $candidate['lift'] : 0.0;
			$candidate['source']      = sanitize_key( (string) $candidate['source'] );
			$candidate['reason']      = isset( $candidate['reason'] ) ? (string) $candidate['reason'] : '';
			$candidate['is_fallback'] = isset( $candidate['is_fallback'] ) ? (bool) $candidate['is_fallback'] : ( 'mined' !== $candidate['source'] );

			$filtered[] = $candidate;
		}

		return $filtered;
	}

	/**
	 * Merges two item arrays without duplicate product IDs.
	 *
	 * @param array $primary      Primary items.
	 * @param array $secondary    Secondary items.
	 * @param array $exclude_ids  Excluded IDs.
	 * @return array
	 */
	private function merge_items_without_duplicates( array $primary, array $secondary, array $exclude_ids ): array {
		$exclude_lookup = array();
		foreach ( $exclude_ids as $exclude_id ) {
			$exclude_lookup[ absint( $exclude_id ) ] = true;
		}

		$seen_ids = array();
		$merged   = array();

		foreach ( array_merge( $primary, $secondary ) as $item ) {
			if ( ! $this->is_valid_item_shape( $item ) ) {
				continue;
			}

			$product_id = absint( $item['product_id'] );
			if ( $product_id <= 0 || isset( $seen_ids[ $product_id ] ) || isset( $exclude_lookup[ $product_id ] ) ) {
				continue;
			}

			$seen_ids[ $product_id ] = true;
			$merged[]                = $item;
		}

		return $merged;
	}

	/**
	 * Builds a fallback item shape.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $source     Source name.
	 * @param float  $score      Score value.
	 * @return array
	 */
	private function build_fallback_item( int $product_id, string $source, float $score ): array {
		return array(
			'product_id'  => absint( $product_id ),
			'score'       => (float) $score,
			'confidence'  => 0.0,
			'lift'        => 0.0,
			'reason'      => '',
			'source'      => sanitize_key( $source ),
			'is_fallback' => true,
		);
	}

	/**
	 * Builds cross-sell fallback items.
	 *
	 * @param int $product_id Product ID.
	 * @param int $limit      Limit.
	 * @return array
	 */
	private function get_cross_sell_items( int $product_id, int $limit ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! method_exists( $product, 'get_cross_sell_ids' ) ) {
			return array();
		}

		$items          = array();
		$cross_sell_ids = $product->get_cross_sell_ids();
		if ( ! is_array( $cross_sell_ids ) ) {
			return array();
		}

		foreach ( array_slice( $cross_sell_ids, 0, $limit ) as $cross_sell_id ) {
			$cross_sell_id = absint( $cross_sell_id );
			if ( $cross_sell_id <= 0 ) {
				continue;
			}

			$items[] = $this->build_fallback_item( $cross_sell_id, 'cross_sell', 0.0 );
		}

		return $items;
	}

	/**
	 * Ensures final result shape has required keys.
	 *
	 * @param array  $result     Raw result.
	 * @param int    $product_id Product ID.
	 * @param string $context    Context.
	 * @return array
	 */
	private function ensure_result_shape( array $result, int $product_id, string $context ): array {
		return array(
			'product_id'   => isset( $result['product_id'] ) ? absint( $result['product_id'] ) : absint( $product_id ),
			'context'      => isset( $result['context'] ) ? (string) $result['context'] : $context,
			'source'       => isset( $result['source'] ) ? (string) $result['source'] : 'none',
			'items'        => isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array(),
			'generated_at' => isset( $result['generated_at'] ) ? (string) $result['generated_at'] : $this->current_datetime(),
			'cache_hit'    => isset( $result['cache_hit'] ) ? (bool) $result['cache_hit'] : false,
		);
	}

	/**
	 * Checks whether item has required keys.
	 *
	 * @param array $item Item candidate.
	 * @return bool
	 */
	private function is_valid_item_shape( array $item ): bool {
		return isset( $item['product_id'], $item['score'], $item['source'] );
	}

	/**
	 * Resolves display limit.
	 *
	 * @param int   $limit    Requested limit.
	 * @param array $settings Settings.
	 * @return int
	 */
	private function resolve_limit( int $limit, array $settings ): int {
		if ( $limit > 0 ) {
			return $limit;
		}

		if ( isset( $settings['max_recommendations'] ) ) {
			return max( 1, absint( $settings['max_recommendations'] ) );
		}

		return 4;
	}

	/**
	 * Resolves query headroom multiplier.
	 *
	 * @param array $settings Settings.
	 * @return int
	 */
	private function resolve_query_headroom_multiplier( array $settings ): int {
		if ( isset( $settings['query_headroom_mult'] ) ) {
			return max( 1, absint( $settings['query_headroom_mult'] ) );
		}

		return 3;
	}

	/**
	 * Resolves base product ID from variation mode.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $settings   Settings.
	 * @return int
	 */
	private function resolve_base_product_id( int $product_id, array $settings ): int {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return 0;
		}

		$variation_mode = isset( $settings['variation_mode'] ) ? (string) $settings['variation_mode'] : 'parent';
		if ( 'parent' !== $variation_mode ) {
			return $product_id;
		}

		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $parent_id > 0 ) {
			return absint( $parent_id );
		}

		return $product_id;
	}

	/**
	 * Normalizes supported contexts.
	 *
	 * @param string $context Raw context.
	 * @return string
	 */
	private function normalize_context( string $context ): string {
		$context = sanitize_key( $context );
		if ( in_array( $context, array( 'product', 'cart', 'checkout' ), true ) ) {
			return $context;
		}

		return 'product';
	}

	/**
	 * Returns current datetime string.
	 *
	 * @return string
	 */
	private function current_datetime(): string {
		if ( function_exists( 'current_time' ) ) {
			return (string) current_time( 'mysql' );
		}

		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Returns allowed status list from settings.
	 *
	 * @return array
	 */
	private function get_included_statuses(): array {
		$settings = CIE_Settings::get();
		$statuses = isset( $settings['included_statuses'] ) && is_array( $settings['included_statuses'] )
			? $settings['included_statuses']
			: array( 'wc-completed' );

		return $this->sanitize_statuses( $statuses );
	}

	/**
	 * Returns sanitized excluded category IDs from settings.
	 *
	 * @param array|null $settings Optional settings payload.
	 * @return array
	 */
	private function get_excluded_category_ids( ?array $settings = null ): array {
		$settings = is_array( $settings ) ? $settings : CIE_Settings::get();
		if ( ! isset( $settings['excluded_category_ids'] ) || ! is_array( $settings['excluded_category_ids'] ) ) {
			return array();
		}

		return $this->sanitize_integer_list( $settings['excluded_category_ids'] );
	}

	/**
	 * Builds compact signature for cache keys from integer lists.
	 *
	 * @param array $values Integer list.
	 * @return string
	 */
	private function build_list_signature( array $values ): string {
		$values = $this->sanitize_integer_list( $values );
		if ( empty( $values ) ) {
			return 'none';
		}

		return substr( md5( implode( ',', $values ) ), 0, 10 );
	}

	/**
	 * Calculates a lookback cutoff datetime in site timezone.
	 *
	 * @param int $days Lookback window in days.
	 * @return string
	 */
	private function calculate_cutoff_datetime( int $days ): string {
		$days = max( 1, absint( $days ) );

		try {
			$timezone = function_exists( 'wp_timezone' )
				? wp_timezone()
				: new DateTimeZone( 'UTC' );
			$now      = function_exists( 'current_time' )
				? (string) current_time( 'mysql' )
				: gmdate( 'Y-m-d H:i:s' );
			$date     = new DateTimeImmutable( $now, $timezone );

			return $date->sub( new DateInterval( 'P' . $days . 'D' ) )->format( 'Y-m-d H:i:s' );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		}
	}

	/**
	 * Returns whether product belongs to any excluded category.
	 *
	 * @param int   $product_id            Product ID.
	 * @param array $excluded_category_ids Excluded category IDs.
	 * @return bool
	 */
	private function is_product_in_excluded_categories( int $product_id, array $excluded_category_ids ): bool {
		$product_id            = absint( $product_id );
		$excluded_category_ids = $this->sanitize_integer_list( $excluded_category_ids );

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
	 * Sanitizes statuses against allowlist.
	 *
	 * @param array $statuses Status values.
	 * @return array
	 */
	private function sanitize_statuses( array $statuses ): array {
		$allowed = array( 'wc-completed', 'wc-processing' );
		$output  = array();

		foreach ( $statuses as $status ) {
			if ( ! is_scalar( $status ) ) {
				continue;
			}

			$sanitized = sanitize_key( (string) $status );
			if ( in_array( $sanitized, $allowed, true ) ) {
				$output[] = $sanitized;
			}
		}

		$output = array_values( array_unique( $output ) );
		if ( empty( $output ) ) {
			$output[] = 'wc-completed';
		}

		return $output;
	}

	/**
	 * Sanitizes integer values to unique sorted positive IDs.
	 *
	 * @param array $values Raw values.
	 * @return array
	 */
	private function sanitize_integer_list( array $values ): array {
		$output = array();

		foreach ( $values as $value ) {
			$int_value = absint( $value );
			if ( $int_value > 0 ) {
				$output[] = $int_value;
			}
		}

		$output = array_values( array_unique( $output ) );
		sort( $output );

		return $output;
	}

	/**
	 * Prepares SQL with dynamic placeholders.
	 *
	 * @param string $query  SQL query.
	 * @param array  $params Parameter list.
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
