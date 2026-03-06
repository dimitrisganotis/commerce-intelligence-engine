<?php

/**
 * CIE settings manager.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages plugin settings storage and sanitization.
 */
class Commerce_Intelligence_Engine_Settings {

	/**
	 * Option name for plugin settings.
	 *
	 * @var string
	 */
	const OPTION_SETTINGS = 'cie_settings';

	/**
	 * Option name for fast enabled flag.
	 *
	 * @var string
	 */
	const OPTION_ENABLED = 'cie_enabled';

	/**
	 * Returns canonical default settings.
	 *
	 * @return array
	 */
	public static function get_defaults(): array {
		return array(
			'enabled'               => true,
			'lookback_days'         => 180,
			'included_statuses'     => array( 'wc-completed' ),
			'min_co_occurrence'     => 3,
			'min_support'           => 0.01,
			'min_confidence'        => 0.05,
			'min_lift'              => 1.0,
			'weights'               => array(
				'confidence' => 0.40,
				'lift'       => 0.30,
				'margin'     => 0.15,
				'stock'      => 0.10,
				'recency'    => 0.05,
			),
			'decay_rate'            => 0.01,
			'max_recommendations'   => 4,
			'query_headroom_mult'   => 3,
			'variation_mode'        => 'parent',
			'excluded_category_ids' => array(),
			'display'               => array(
				'title'            => 'Frequently bought together',
				'show_reason'      => true,
				'show_on_product'  => true,
				'show_on_cart'     => false,
				'show_on_checkout' => false,
			),
			'schedule'              => 'nightly',
			'rebuild_log_retention_runs' => 90,
			'rest_api'              => array(
				'enabled'       => false,
				'access_mode'   => 'public',
				'cache_max_age' => 300,
			),
			'uninstall_delete_data' => false,
		);
	}

	/**
	 * Returns merged settings from DB and defaults.
	 *
	 * @return array
	 */
	public static function get(): array {
		$defaults = self::get_defaults();
		$stored   = get_option( self::OPTION_SETTINGS, array() );

		if ( is_string( $stored ) ) {
			$decoded = json_decode( $stored, true );
			$stored  = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::merge_settings( $defaults, $stored );
	}

	/**
	 * Sanitizes and writes updated settings.
	 *
	 * @param array $input Raw settings input.
	 * @return bool
	 */
	public static function update( array $input ): bool {
		$defaults = self::get_defaults();
		$current  = self::get();
		$updated  = $current;

		if ( isset( $input['enabled'] ) ) {
			$updated['enabled'] = (bool) $input['enabled'];
		}

		if ( isset( $input['lookback_days'] ) ) {
			$sanitized = self::sanitize_non_negative_int( $input['lookback_days'] );
			if ( null !== $sanitized ) {
				$updated['lookback_days'] = $sanitized;
			}
		}

		if ( isset( $input['included_statuses'] ) && is_array( $input['included_statuses'] ) ) {
			$updated['included_statuses'] = self::sanitize_string_list( $input['included_statuses'] );
		}

		if ( isset( $input['min_co_occurrence'] ) ) {
			$sanitized = self::sanitize_non_negative_int( $input['min_co_occurrence'] );
			if ( null !== $sanitized ) {
				$updated['min_co_occurrence'] = $sanitized;
			}
		}

		if ( isset( $input['min_support'] ) ) {
			$sanitized = self::sanitize_positive_float( $input['min_support'] );
			if ( null !== $sanitized ) {
				$updated['min_support'] = $sanitized;
			}
		}

		if ( isset( $input['min_confidence'] ) ) {
			$sanitized = self::sanitize_positive_float( $input['min_confidence'] );
			if ( null !== $sanitized ) {
				$updated['min_confidence'] = $sanitized;
			}
		}

		if ( isset( $input['min_lift'] ) ) {
			$sanitized = self::sanitize_positive_float( $input['min_lift'] );
			if ( null !== $sanitized ) {
				$updated['min_lift'] = $sanitized;
			}
		}

		if ( isset( $input['weights'] ) && is_array( $input['weights'] ) ) {
			$updated['weights'] = self::sanitize_weights( $input['weights'], $current['weights'] );
		}

		if ( isset( $input['decay_rate'] ) ) {
			$sanitized = self::sanitize_positive_float( $input['decay_rate'] );
			if ( null !== $sanitized ) {
				$updated['decay_rate'] = $sanitized;
			}
		}

		if ( isset( $input['max_recommendations'] ) ) {
			$sanitized = self::sanitize_non_negative_int( $input['max_recommendations'] );
			if ( null !== $sanitized ) {
				$updated['max_recommendations'] = $sanitized;
			}
		}

		if ( isset( $input['query_headroom_mult'] ) ) {
			$sanitized = self::sanitize_non_negative_int( $input['query_headroom_mult'] );
			if ( null !== $sanitized ) {
				$updated['query_headroom_mult'] = $sanitized;
			}
		}

		if ( isset( $input['variation_mode'] ) ) {
			$sanitized = self::sanitize_enum( (string) $input['variation_mode'], array( 'parent', 'individual' ) );
			if ( null !== $sanitized ) {
				$updated['variation_mode'] = $sanitized;
			}
		}

		if ( isset( $input['excluded_category_ids'] ) && is_array( $input['excluded_category_ids'] ) ) {
			$updated['excluded_category_ids'] = self::sanitize_int_list( $input['excluded_category_ids'] );
		}

		if ( isset( $input['display'] ) && is_array( $input['display'] ) ) {
			$updated['display'] = self::sanitize_display( $input['display'], $current['display'] );
		}

		if ( isset( $input['schedule'] ) ) {
			$sanitized = self::sanitize_enum( (string) $input['schedule'], array( 'nightly', 'weekly', 'manual' ) );
			if ( null !== $sanitized ) {
				$updated['schedule'] = $sanitized;
			}
		}

		if ( isset( $input['rebuild_log_retention_runs'] ) ) {
			$sanitized = self::sanitize_non_negative_int( $input['rebuild_log_retention_runs'] );
			if ( null !== $sanitized ) {
				$updated['rebuild_log_retention_runs'] = max( 30, min( 365, $sanitized ) );
			}
		}

		if ( isset( $input['rest_api'] ) && is_array( $input['rest_api'] ) ) {
			$updated['rest_api'] = self::sanitize_rest_api( $input['rest_api'], $current['rest_api'] );
		}

		if ( isset( $input['uninstall_delete_data'] ) ) {
			$updated['uninstall_delete_data'] = (bool) $input['uninstall_delete_data'];
		}

		$final_settings = self::merge_settings( $defaults, $updated );
		$saved          = self::upsert_option( self::OPTION_SETTINGS, $final_settings, 'no' );

		self::sync_fast_gate();

		return $saved;
	}

	/**
	 * Fast path enabled check.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	/**
	 * Syncs fast gate enabled option from settings.
	 *
	 * @return void
	 */
	private static function sync_fast_gate(): void {
		$settings = self::get();
		$enabled  = isset( $settings['enabled'] ) ? (bool) $settings['enabled'] : true;

		self::upsert_option( self::OPTION_ENABLED, $enabled, 'yes' );
	}

	/**
	 * Sanitizes display settings.
	 *
	 * @param array $input   Input values.
	 * @param array $current Current values.
	 * @return array
	 */
	private static function sanitize_display( array $input, array $current ): array {
		$sanitized = $current;

		if ( isset( $input['title'] ) && is_scalar( $input['title'] ) ) {
			$sanitized['title'] = sanitize_text_field( (string) $input['title'] );
		}

		if ( isset( $input['show_reason'] ) ) {
			$sanitized['show_reason'] = (bool) $input['show_reason'];
		}

		if ( isset( $input['show_on_product'] ) ) {
			$sanitized['show_on_product'] = (bool) $input['show_on_product'];
		}

		if ( isset( $input['show_on_cart'] ) ) {
			$sanitized['show_on_cart'] = (bool) $input['show_on_cart'];
		}

		if ( isset( $input['show_on_checkout'] ) ) {
			$sanitized['show_on_checkout'] = (bool) $input['show_on_checkout'];
		}

		return $sanitized;
	}

	/**
	 * Sanitizes REST API settings.
	 *
	 * @param array $input   Input values.
	 * @param array $current Current values.
	 * @return array
	 */
	private static function sanitize_rest_api( array $input, array $current ): array {
		$sanitized = $current;

		if ( isset( $input['enabled'] ) ) {
			$sanitized['enabled'] = (bool) $input['enabled'];
		}

		if ( isset( $input['access_mode'] ) ) {
			$value = self::sanitize_enum( (string) $input['access_mode'], array( 'public', 'authenticated-only' ) );
			if ( null !== $value ) {
				$sanitized['access_mode'] = $value;
			}
		}

		if ( isset( $input['cache_max_age'] ) ) {
			$value = self::sanitize_non_negative_int( $input['cache_max_age'] );
			if ( null !== $value ) {
				$sanitized['cache_max_age'] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitizes list of strings.
	 *
	 * @param array $values Input values.
	 * @return array
	 */
	private static function sanitize_string_list( array $values ): array {
		$output = array();

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$sanitized = sanitize_key( (string) $value );
			if ( '' === $sanitized ) {
				continue;
			}

			$output[] = $sanitized;
		}

		return array_values( array_unique( $output ) );
	}

	/**
	 * Sanitizes list of positive integers.
	 *
	 * @param array $values Input values.
	 * @return array
	 */
	private static function sanitize_int_list( array $values ): array {
		$output = array();

		foreach ( $values as $value ) {
			$sanitized = absint( $value );
			if ( 0 === $sanitized ) {
				continue;
			}

			$output[] = $sanitized;
		}

		return array_values( array_unique( $output ) );
	}

	/**
	 * Sanitizes weight values and normalizes sum to 1.0.
	 *
	 * @param array $input   Input values.
	 * @param array $current Current values.
	 * @return array
	 */
	private static function sanitize_weights( array $input, array $current ): array {
		$keys      = array( 'confidence', 'lift', 'margin', 'stock', 'recency' );
		$sanitized = $current;

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = self::sanitize_positive_float( $input[ $key ] );
			if ( null === $value ) {
				continue;
			}

			$sanitized[ $key ] = $value;
		}

		$total = 0.0;
		foreach ( $keys as $key ) {
			$total += (float) $sanitized[ $key ];
		}

		if ( $total <= 0.0 ) {
			$equal = 1 / count( $keys );
			foreach ( $keys as $key ) {
				$sanitized[ $key ] = (float) $equal;
			}

			return $sanitized;
		}

		foreach ( $keys as $key ) {
			$sanitized[ $key ] = (float) ( $sanitized[ $key ] / $total );
		}

		return $sanitized;
	}

	/**
	 * Sanitizes positive float values (> 0).
	 *
	 * @param mixed $value Input value.
	 * @return float|null
	 */
	private static function sanitize_positive_float( $value ): ?float {
		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$value = str_replace( ',', '.', trim( $value ) );
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$float = (float) $value;

		if ( $float <= 0.0 ) {
			return null;
		}

		return $float;
	}

	/**
	 * Sanitizes non-negative integer values.
	 *
	 * @param mixed $value Input value.
	 * @return int|null
	 */
	private static function sanitize_non_negative_int( $value ): ?int {
		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		return absint( $value );
	}

	/**
	 * Sanitizes enum values.
	 *
	 * @param string $value   Input value.
	 * @param array  $allowed Allowed enum values.
	 * @return string|null
	 */
	private static function sanitize_enum( string $value, array $allowed ): ?string {
		return in_array( $value, $allowed, true ) ? $value : null;
	}

	/**
	 * Deep merges settings arrays while only keeping known keys.
	 *
	 * @param array $defaults Default values.
	 * @param array $input    Input values.
	 * @return array
	 */
	private static function merge_settings( array $defaults, array $input ): array {
		$merged = $defaults;

		foreach ( $defaults as $key => $default_value ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			if ( is_array( $default_value ) && is_array( $input[ $key ] ) ) {
				$default_is_assoc = self::is_associative_array( $default_value );
				$input_is_assoc   = self::is_associative_array( $input[ $key ] );

				if ( $default_is_assoc && $input_is_assoc ) {
					$merged[ $key ] = self::merge_settings( $default_value, $input[ $key ] );
					continue;
				}

				$merged[ $key ] = $input[ $key ];
				continue;
			}

			$merged[ $key ] = $input[ $key ];
		}

		return $merged;
	}

	/**
	 * Returns true when an array is associative.
	 *
	 * @param array $array Input array.
	 * @return bool
	 */
	private static function is_associative_array( array $array ): bool {
		if ( array() === $array ) {
			return false;
		}

		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}

	/**
	 * Creates or updates an option while enforcing autoload.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $value       Option value.
	 * @param string $autoload    yes|no.
	 * @return bool
	 */
	private static function upsert_option( string $option_name, $value, string $autoload ): bool {
		global $wpdb;

		$autoload = ( 'yes' === $autoload ) ? 'yes' : 'no';
		$exists   = get_option( $option_name, null );
		$result   = false;

		if ( null === $exists ) {
			$result = add_option( $option_name, $value, '', $autoload );
		} else {
			if ( maybe_serialize( $exists ) === maybe_serialize( $value ) ) {
				$result = true;
			} else {
				$result = update_option( $option_name, $value );
			}
		}

		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => $autoload ),
			array( 'option_name' => $option_name ),
			array( '%s' ),
			array( '%s' )
		);

		return (bool) $result;
	}
}

if ( ! class_exists( 'CIE_Settings', false ) ) {
	class_alias( 'Commerce_Intelligence_Engine_Settings', 'CIE_Settings' );
}
