<?php
/**
 * PHPUnit bootstrap.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
$polyfills_dir = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );

if ( $polyfills_dir && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_dir );
}

if ( $_tests_dir && file_exists( $_tests_dir . '/includes/bootstrap.php' ) ) {
	require_once $_tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function() {
			require dirname( __DIR__ ) . '/commerce-intelligence-engine.php';
		}
	);

	require_once $_tests_dir . '/includes/bootstrap.php';
	return;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'CIE_PLUGIN_DIR' ) ) {
	define( 'CIE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$GLOBALS['cie_test_options'] = array();
$GLOBALS['cie_test_filters'] = array();

if ( ! function_exists( 'cie_test_reset_state' ) ) {
	/**
	 * Resets in-memory options and filters.
	 *
	 * @return void
	 */
	function cie_test_reset_state(): void {
		$GLOBALS['cie_test_options'] = array();
		$GLOBALS['cie_test_filters'] = array();
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Casts to absolute integer.
	 *
	 * @param mixed $maybeint Value.
	 * @return int
	 */
	function absint( $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Sanitizes keys.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ): string {
		$key = strtolower( (string) $key );
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Basic text field sanitization.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation no-op.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ): string {
		return (string) $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Translation no-op.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html__( $text ): string {
		return (string) $text;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode wrapper.
	 *
	 * @param mixed $value Value.
	 * @return string|false
	 */
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * UUID v4 approximation.
	 *
	 * @return string
	 */
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 65535 ),
			mt_rand( 0, 65535 ),
			mt_rand( 0, 65535 ),
			mt_rand( 16384, 20479 ),
			mt_rand( 32768, 49151 ),
			mt_rand( 0, 65535 ),
			mt_rand( 0, 65535 ),
			mt_rand( 0, 65535 )
		);
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Number format helper.
	 *
	 * @param float|int $number   Number.
	 * @param int       $decimals Decimals.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ): string {
		return number_format( (float) $number, (int) $decimals, '.', '' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Gets an option.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( string $option, $default = false ) {
		if ( array_key_exists( $option, $GLOBALS['cie_test_options'] ) ) {
			return $GLOBALS['cie_test_options'][ $option ];
		}

		return $default;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Adds an option.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Value.
	 * @param string $deprecated Deprecated.
	 * @param string $autoload Autoload.
	 * @return bool
	 */
	function add_option( string $option, $value, string $deprecated = '', string $autoload = 'yes' ): bool {
		unset( $deprecated, $autoload );

		if ( array_key_exists( $option, $GLOBALS['cie_test_options'] ) ) {
			return false;
		}

		$GLOBALS['cie_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Updates an option.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return bool
	 */
	function update_option( string $option, $value ): bool {
		$GLOBALS['cie_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Deletes an option.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( string $option ): bool {
		if ( ! array_key_exists( $option, $GLOBALS['cie_test_options'] ) ) {
			return false;
		}

		unset( $GLOBALS['cie_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Registers a filter callback.
	 *
	 * @param string   $hook_name      Hook name.
	 * @param callable $callback       Callback.
	 * @param int      $priority       Priority.
	 * @param int      $accepted_args  Accepted args.
	 * @return bool
	 */
	function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		if ( ! isset( $GLOBALS['cie_test_filters'][ $hook_name ] ) ) {
			$GLOBALS['cie_test_filters'][ $hook_name ] = array();
		}

		if ( ! isset( $GLOBALS['cie_test_filters'][ $hook_name ][ $priority ] ) ) {
			$GLOBALS['cie_test_filters'][ $hook_name ][ $priority ] = array();
		}

		$GLOBALS['cie_test_filters'][ $hook_name ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	/**
	 * Removes all callbacks for a hook.
	 *
	 * @param string $hook_name Hook name.
	 * @return void
	 */
	function remove_all_filters( string $hook_name ): void {
		unset( $GLOBALS['cie_test_filters'][ $hook_name ] );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Applies test filter callbacks.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Initial value.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value ) {
		$args = func_get_args();
		array_shift( $args );

		if ( empty( $GLOBALS['cie_test_filters'][ $hook_name ] ) ) {
			return $value;
		}

		$callbacks = $GLOBALS['cie_test_filters'][ $hook_name ];
		ksort( $callbacks );

		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback_data ) {
				$accepted = isset( $callback_data['accepted_args'] ) ? (int) $callback_data['accepted_args'] : 1;
				$to_pass  = array_slice( $args, 0, max( 1, $accepted ) );
				$value    = call_user_func_array( $callback_data['callback'], $to_pass );
				$args[0]  = $value;
			}
		}

		return $value;
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	/**
	 * Minimal wpdb test double.
	 */
	class CIE_Test_WPDB {

		/**
		 * Options table.
		 *
		 * @var string
		 */
		public $options = 'wp_options';

		/**
		 * Table prefix.
		 *
		 * @var string
		 */
		public $prefix = 'wp_';

		/**
		 * No-op update.
		 *
		 * @param string $table Table.
		 * @param array  $data  Data.
		 * @param array  $where Where.
		 * @param array  $format Format.
		 * @param array  $where_format Where format.
		 * @return int
		 */
		public function update( $table, $data, $where, $format = array(), $where_format = array() ): int {
			unset( $table, $data, $where, $format, $where_format );
			return 1;
		}
	}

	$GLOBALS['wpdb'] = new CIE_Test_WPDB();
}

require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-settings.php';
require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-lock-manager.php';
require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-association-calculator.php';
require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-scorer.php';
require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-pair-counter.php';
require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-association-repository.php';
require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-cache.php';
require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-recommender.php';
