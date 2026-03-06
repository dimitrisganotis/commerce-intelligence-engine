<?php

/**
 * Fired during plugin activation.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activator.
 */
class Commerce_Intelligence_Engine_Activator {

	/**
	 * Runs activation setup.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! self::is_woocommerce_active() ) {
			set_transient(
				'cie_wc_missing_notice',
				esc_html__( 'Commerce Intelligence Engine requires WooCommerce to be active.', 'commerce-intelligence-engine' ),
				60
			);

			if ( function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( plugin_basename( dirname( __DIR__ ) . '/commerce-intelligence-engine.php' ) );
			}

			return;
		}

		self::create_tables();
		self::initialize_options();
		self::schedule_rebuild();
	}

	/**
	 * Checks whether WooCommerce is active.
	 *
	 * @return bool
	 */
	private static function is_woocommerce_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'woocommerce/woocommerce.php' );
	}

	/**
	 * Creates plugin tables using dbDelta.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_prefix    = $wpdb->prefix . 'ci_';

		$table_associations      = $table_prefix . 'associations';
		$table_pair_counts       = $table_prefix . 'pair_counts';
		$table_rebuild_log       = $table_prefix . 'rebuild_log';
		$table_overrides         = $table_prefix . 'overrides';
		$table_associations_temp = $table_prefix . 'associations_temp';

		$sql_associations      = self::get_associations_table_sql( $table_associations, $charset_collate );
		$sql_associations_temp = self::get_associations_table_sql( $table_associations_temp, $charset_collate );

		$sql_pair_counts = "CREATE TABLE {$table_pair_counts} (
			product_a BIGINT UNSIGNED NOT NULL,
			product_b BIGINT UNSIGNED NOT NULL,
			pair_count INT UNSIGNED NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (product_a, product_b)
		) {$charset_collate};";

		$sql_rebuild_log = "CREATE TABLE {$table_rebuild_log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id CHAR(36) NOT NULL,
			mode VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL,
			started_at DATETIME NOT NULL,
			completed_at DATETIME NULL,
			duration_seconds INT UNSIGNED NULL,
			orders_processed INT UNSIGNED NOT NULL DEFAULT 0,
			baskets_processed INT UNSIGNED NOT NULL DEFAULT 0,
			pairs_counted INT UNSIGNED NOT NULL DEFAULT 0,
			associations_written INT UNSIGNED NOT NULL DEFAULT 0,
			memory_peak_bytes BIGINT UNSIGNED NULL,
			error_message TEXT NULL,
			PRIMARY KEY  (id),
			KEY idx_status_started (status, started_at),
			KEY idx_run_id (run_id)
		) {$charset_collate};";

		$sql_overrides = "CREATE TABLE {$table_overrides} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			associated_product_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(10) NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_override (product_id, associated_product_id)
		) {$charset_collate};";

		dbDelta( $sql_associations );
		dbDelta( $sql_pair_counts );
		dbDelta( $sql_rebuild_log );
		dbDelta( $sql_overrides );
		dbDelta( $sql_associations_temp );
	}

	/**
	 * Initializes plugin options.
	 *
	 * @return void
	 */
	private static function initialize_options(): void {
		if ( defined( 'CIE_DB_VERSION' ) ) {
			self::upsert_option( 'cie_db_version', CIE_DB_VERSION, 'no' );
		}

		if ( false === get_option( 'cie_last_run_at', false ) ) {
			add_option( 'cie_last_run_at', '', '', 'no' );
		}
		self::enforce_option_autoload( 'cie_last_run_at', 'no' );

		if ( false === get_option( 'cie_last_run_mode', false ) ) {
			add_option( 'cie_last_run_mode', '', '', 'no' );
		}
		self::enforce_option_autoload( 'cie_last_run_mode', 'no' );

		if ( false === get_option( 'cie_health_snapshot', false ) ) {
			add_option(
				'cie_health_snapshot',
				array(
					'status'      => 'unknown',
					'reason'      => 'no_runs',
					'updated_at'  => time(),
				),
				'',
				'no'
			);
		}
		self::enforce_option_autoload( 'cie_health_snapshot', 'no' );

		if ( false === get_option( 'cie_last_processed_order_id', false ) ) {
			add_option( 'cie_last_processed_order_id', 0, '', 'no' );
		}
		self::enforce_option_autoload( 'cie_last_processed_order_id', 'no' );

		if ( false === get_option( 'cie_incremental_snapshot', false ) ) {
			add_option(
				'cie_incremental_snapshot',
				array(
					'product_order_counts' => array(),
					'total_orders'         => 0,
					'settings_signature'   => '',
					'updated_at'           => 0,
				),
				'',
				'no'
			);
		}
		self::enforce_option_autoload( 'cie_incremental_snapshot', 'no' );

		if ( false === get_option( 'cie_settings', false ) ) {
			if ( ! class_exists( 'CIE_Settings' ) ) {
				$settings_file = dirname( __FILE__ ) . '/class-commerce-intelligence-engine-settings.php';
				if ( file_exists( $settings_file ) ) {
					require_once $settings_file;
				}
			}

			add_option( 'cie_settings', CIE_Settings::get_defaults(), '', 'no' );
		}
		self::enforce_option_autoload( 'cie_settings', 'no' );

		$enabled = true;
		$settings = get_option( 'cie_settings', array() );
		if ( is_array( $settings ) && array_key_exists( 'enabled', $settings ) ) {
			$enabled = (bool) $settings['enabled'];
		}

		if ( false === get_option( 'cie_enabled', false ) ) {
			add_option( 'cie_enabled', $enabled, '', 'yes' );
		} else {
			update_option( 'cie_enabled', $enabled );
		}
		self::enforce_option_autoload( 'cie_enabled', 'yes' );
	}

	/**
	 * Returns SQL for the associations table.
	 *
	 * @param string $table_name       Table name.
	 * @param string $charset_collate Charset/collation clause.
	 * @return string
	 */
	private static function get_associations_table_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			associated_product_id BIGINT UNSIGNED NOT NULL,
			co_occurrence_count INT UNSIGNED NOT NULL DEFAULT 0,
			support FLOAT NOT NULL,
			confidence FLOAT NOT NULL,
			lift FLOAT NOT NULL,
			score FLOAT NOT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'mined',
			last_seen_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_pair_direction (product_id, associated_product_id),
			KEY idx_product_score (product_id, score),
			KEY idx_assoc_product (associated_product_id)
		) {$charset_collate};";
	}

	/**
	 * Creates or updates an option while enforcing autoload policy.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $value       Option value.
	 * @param string $autoload    yes|no.
	 * @return void
	 */
	private static function upsert_option( string $option_name, $value, string $autoload ): void {
		if ( false === get_option( $option_name, false ) ) {
			add_option( $option_name, $value, '', $autoload );
		} else {
			update_option( $option_name, $value );
		}

		self::enforce_option_autoload( $option_name, $autoload );
	}

	/**
	 * Forces autoload policy for an option.
	 *
	 * @param string $option_name Option name.
	 * @param string $autoload    yes|no.
	 * @return void
	 */
	private static function enforce_option_autoload( string $option_name, string $autoload ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => ( 'yes' === $autoload ) ? 'yes' : 'no' ),
			array( 'option_name' => $option_name ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Schedules recurring rebuild based on current settings.
	 *
	 * @return void
	 */
	private static function schedule_rebuild(): void {
		$scheduler_file = dirname( __FILE__ ) . '/class-commerce-intelligence-engine-scheduler.php';
		if ( ! file_exists( $scheduler_file ) ) {
			return;
		}

		require_once $scheduler_file;
		CIE_Scheduler::schedule_rebuild();
	}
}
