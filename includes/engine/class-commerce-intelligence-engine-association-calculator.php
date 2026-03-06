<?php

/**
 * Association metrics calculator.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calculates directional association metrics from canonical pair counts.
 */
class CIE_Association_Calculator {

	/**
	 * Calculates support, confidence, and lift for directional rows.
	 *
	 * @param array $pair_counts           Canonical pair counts map "min:max" => count.
	 * @param array $product_order_counts  Product order counts map product_id => count.
	 * @param int   $total_orders          Total eligible orders.
	 * @param array $pair_last_seen_map    Optional pair map "min:max" => timestamp/datetime.
	 * @return array
	 */
	public function calculate_metrics( array $pair_counts, array $product_order_counts, int $total_orders, array $pair_last_seen_map = array() ): array {
		$total_orders = absint( $total_orders );
		if ( $total_orders <= 0 ) {
			return array();
		}

		$rows = array();

		foreach ( $pair_counts as $pair_key => $pair_count ) {
			$parsed = $this->parse_pair_key( (string) $pair_key );
			if ( null === $parsed ) {
				continue;
			}

			$count_ab = absint( $pair_count );
			if ( $count_ab <= 0 ) {
				continue;
			}

			$product_a = $parsed['product_a'];
			$product_b = $parsed['product_b'];

			$count_a = isset( $product_order_counts[ $product_a ] ) ? absint( $product_order_counts[ $product_a ] ) : 0;
			$count_b = isset( $product_order_counts[ $product_b ] ) ? absint( $product_order_counts[ $product_b ] ) : 0;

			if ( $count_a <= 0 || $count_b <= 0 ) {
				continue;
			}

			$support = (float) ( $count_ab / $total_orders );

			$confidence_ab = (float) ( $count_ab / $count_a );
			$support_b     = (float) ( $count_b / $total_orders );
			$lift_ab       = ( $support_b > 0.0 ) ? (float) ( $confidence_ab / $support_b ) : 0.0;

			$confidence_ba = (float) ( $count_ab / $count_b );
			$support_a     = (float) ( $count_a / $total_orders );
			$lift_ba       = ( $support_a > 0.0 ) ? (float) ( $confidence_ba / $support_a ) : 0.0;
			$pair_key      = $product_a . ':' . $product_b;
			$last_seen_at  = $this->resolve_last_seen_at( $pair_key, $pair_last_seen_map );

			$rows[] = array(
				'product_id'            => $product_a,
				'associated_product_id' => $product_b,
				'co_occurrence_count'   => $count_ab,
				'support'               => $support,
				'confidence'            => $confidence_ab,
				'lift'                  => $lift_ab,
				'last_seen_at'          => $last_seen_at,
			);

			$rows[] = array(
				'product_id'            => $product_b,
				'associated_product_id' => $product_a,
				'co_occurrence_count'   => $count_ab,
				'support'               => $support,
				'confidence'            => $confidence_ba,
				'lift'                  => $lift_ba,
				'last_seen_at'          => $last_seen_at,
			);
		}

		return $rows;
	}

	/**
	 * Filters rows by configured thresholds.
	 *
	 * @param array $rows       Association rows.
	 * @param array $thresholds Threshold map.
	 * @return array
	 */
	public function filter_by_thresholds( array $rows, array $thresholds ): array {
		$min_co_occurrence = isset( $thresholds['min_co_occurrence'] ) ? absint( $thresholds['min_co_occurrence'] ) : 0;
		$min_support       = isset( $thresholds['min_support'] ) ? (float) $thresholds['min_support'] : 0.0;
		$min_confidence    = isset( $thresholds['min_confidence'] ) ? (float) $thresholds['min_confidence'] : 0.0;
		$min_lift          = isset( $thresholds['min_lift'] ) ? (float) $thresholds['min_lift'] : 0.0;

		$output = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$co_occurrence_count = isset( $row['co_occurrence_count'] ) ? absint( $row['co_occurrence_count'] ) : 0;
			$support             = isset( $row['support'] ) ? (float) $row['support'] : 0.0;
			$confidence          = isset( $row['confidence'] ) ? (float) $row['confidence'] : 0.0;
			$lift                = isset( $row['lift'] ) ? (float) $row['lift'] : 0.0;

			if ( $co_occurrence_count < $min_co_occurrence ) {
				continue;
			}

			if ( $support < $min_support ) {
				continue;
			}

			if ( $confidence < $min_confidence ) {
				continue;
			}

			if ( $lift < $min_lift ) {
				continue;
			}

			$output[] = $row;
		}

		return $output;
	}

	/**
	 * Parses canonical pair keys.
	 *
	 * @param string $pair_key Canonical pair key.
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
	 * Resolves normalized last-seen datetime for a canonical pair key.
	 *
	 * @param string $pair_key           Canonical pair key.
	 * @param array  $pair_last_seen_map Pair last-seen map.
	 * @return string|null
	 */
	private function resolve_last_seen_at( string $pair_key, array $pair_last_seen_map ): ?string {
		if ( ! array_key_exists( $pair_key, $pair_last_seen_map ) ) {
			return null;
		}

		$value     = $pair_last_seen_map[ $pair_key ];
		$timestamp = 0;

		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
			$timestamp = (int) $value;
		} elseif ( is_string( $value ) && '' !== $value ) {
			$parsed = strtotime( $value );
			if ( false !== $parsed ) {
				$timestamp = $parsed;
			}
		}

		if ( $timestamp <= 0 ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
