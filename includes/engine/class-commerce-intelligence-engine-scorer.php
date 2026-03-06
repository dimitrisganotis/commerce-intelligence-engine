<?php

/**
 * Association scorer.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calculates final recommendation scores.
 */
class CIE_Scorer {

	/**
	 * Canonical weight keys.
	 *
	 * @var string[]
	 */
	const WEIGHT_KEYS = array(
		'confidence',
		'lift',
		'margin',
		'stock',
		'recency',
	);

	/**
	 * Scores association rows.
	 *
	 * @param array $association_rows Association rows.
	 * @param array $meta_map         Meta map keyed by product ID.
	 * @param array $weights          Weight map.
	 * @param float $decay_rate       Recency decay rate.
	 * @return array
	 */
	public function score_batch( array $association_rows, array $meta_map, array $weights, float $decay_rate ): array {
		$normalized_weights = self::normalize_weights( $weights );
		$decay_rate         = ( $decay_rate < 0 ) ? 0.0 : (float) $decay_rate;
		$output             = array();

		foreach ( $association_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$product_id            = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
			$associated_product_id = isset( $row['associated_product_id'] ) ? absint( $row['associated_product_id'] ) : 0;
			$confidence            = isset( $row['confidence'] ) ? (float) $row['confidence'] : 0.0;
			$lift                  = isset( $row['lift'] ) ? (float) $row['lift'] : 0.0;
			$lift_normalized       = min( $lift / 10, 1.0 );

			$meta = array();
			if ( $associated_product_id > 0 && isset( $meta_map[ $associated_product_id ] ) && is_array( $meta_map[ $associated_product_id ] ) ) {
				$meta = $meta_map[ $associated_product_id ];
			}

			$margin_boost  = $this->calculate_margin_boost( $meta );
			$stock_boost   = $this->calculate_stock_boost( $meta );
			$recency_weight = $this->calculate_recency_weight( $row, $decay_rate );

			$components = array(
				'confidence'      => $confidence,
				'lift_normalized' => $lift_normalized,
				'margin_boost'    => $margin_boost,
				'stock_boost'     => $stock_boost,
				'recency_weight'  => $recency_weight,
			);

			$filtered_components = apply_filters( 'cie_score_components', $components, $product_id, $associated_product_id );
			if ( is_array( $filtered_components ) ) {
				$components = $this->sanitize_components( $filtered_components, $components );
			}

			$score = 0.0;
			$score += $normalized_weights['confidence'] * (float) $components['confidence'];
			$score += $normalized_weights['lift'] * (float) $components['lift_normalized'];
			$score += $normalized_weights['margin'] * (float) $components['margin_boost'];
			$score += $normalized_weights['stock'] * (float) $components['stock_boost'];
			$score += $normalized_weights['recency'] * (float) $components['recency_weight'];

			$row['score'] = (float) $score;
			$output[]     = $row;
		}

		return $output;
	}

	/**
	 * Normalizes weights to sum to 1.0.
	 *
	 * @param array $weights Weight map.
	 * @return array
	 */
	public static function normalize_weights( array $weights ): array {
		$normalized = array_fill_keys( self::WEIGHT_KEYS, 0.0 );

		foreach ( self::WEIGHT_KEYS as $key ) {
			if ( ! array_key_exists( $key, $weights ) ) {
				continue;
			}

			$value = $weights[ $key ];
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$normalized[ $key ] = (float) $value;
		}

		$sum = array_sum( $normalized );
		if ( $sum > 0.0 ) {
			foreach ( self::WEIGHT_KEYS as $key ) {
				$normalized[ $key ] = (float) ( $normalized[ $key ] / $sum );
			}

			return $normalized;
		}

		$equal_weight = 1 / count( self::WEIGHT_KEYS );
		foreach ( self::WEIGHT_KEYS as $key ) {
			$normalized[ $key ] = (float) $equal_weight;
		}

		return $normalized;
	}

	/**
	 * Calculates margin boost from metadata.
	 *
	 * @param array $meta Product meta values.
	 * @return float
	 */
	private function calculate_margin_boost( array $meta ): float {
		if ( ! array_key_exists( '_wc_cog_cost', $meta ) || '' === (string) $meta['_wc_cog_cost'] ) {
			return 0.5;
		}

		$cost  = (float) ( $meta['_wc_cog_cost'] ?? 0 );
		$price = (float) ( $meta['_price'] ?? 0 );

		if ( $price > 0 && $cost >= 0 ) {
			$margin_pct = ( $price - $cost ) / $price;
			return (float) max( 0.0, min( $margin_pct, 1.0 ) );
		}

		return 0.5;
	}

	/**
	 * Calculates stock boost from metadata.
	 *
	 * @param array $meta Product meta values.
	 * @return float
	 */
	private function calculate_stock_boost( array $meta ): float {
		$manage_stock = ( $meta['_manage_stock'] ?? 'no' ) === 'yes';

		if ( ! $manage_stock ) {
			return 0.5;
		}

		$stock_qty = (int) ( $meta['_stock'] ?? 0 );

		if ( $stock_qty > 50 ) {
			return 1.0;
		}

		if ( $stock_qty > 10 ) {
			return 0.7;
		}

		if ( $stock_qty > 3 ) {
			return 0.4;
		}

		return 0.1;
	}

	/**
	 * Calculates recency weight from association row.
	 *
	 * @param array $row        Association row.
	 * @param float $decay_rate Decay rate.
	 * @return float
	 */
	private function calculate_recency_weight( array $row, float $decay_rate ): float {
		$last_seen_at = $row['last_seen_at'] ?? null;
		if ( null === $last_seen_at || '' === $last_seen_at ) {
			return 0.5;
		}

		$last_seen_ts = strtotime( (string) $last_seen_at );
		if ( false === $last_seen_ts ) {
			return 0.5;
		}

		$day_in_seconds = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$days_ago       = ( time() - $last_seen_ts ) / $day_in_seconds;

		return (float) exp( -$decay_rate * $days_ago );
	}

	/**
	 * Normalizes filtered components to expected keys.
	 *
	 * @param array $components Filtered components.
	 * @param array $defaults   Original components.
	 * @return array
	 */
	private function sanitize_components( array $components, array $defaults ): array {
		$keys = array(
			'confidence',
			'lift_normalized',
			'margin_boost',
			'stock_boost',
			'recency_weight',
		);

		$is_numeric_list = array_keys( $components ) === range( 0, count( $components ) - 1 );
		$output          = $defaults;

		foreach ( $keys as $index => $key ) {
			if ( $is_numeric_list && array_key_exists( $index, $components ) ) {
				$value = $components[ $index ];
				if ( ! is_array( $value ) && ! is_object( $value ) ) {
					$output[ $key ] = (float) $value;
				}
				continue;
			}

			if ( ! array_key_exists( $key, $components ) ) {
				continue;
			}

			$value = $components[ $key ];
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$output[ $key ] = (float) $value;
		}

		return $output;
	}
}
