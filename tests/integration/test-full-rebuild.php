<?php
/**
 * Integration test for full rebuild pipeline.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Full rebuild integration test against real WP/WC database tables.
 */
final class CIE_Test_Full_Rebuild extends WP_UnitTestCase {

	/**
	 * First order ID used by the deterministic fixture.
	 *
	 * @var int
	 */
	private const FIXTURE_START_ORDER_ID = 900001;

	/**
	 * Number of orders in the deterministic fixture.
	 *
	 * @var int
	 */
	private const FIXTURE_ORDER_COUNT = 20;

	/**
	 * Fixture orders map: order_id => product IDs.
	 *
	 * @var array
	 */
	private $fixture_orders = array();

	/**
	 * Cached table schemas.
	 *
	 * @var array
	 */
	private $table_schema_cache = array();

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->load_dependencies();

		if ( ! $this->has_required_wc_tables() ) {
			$this->markTestSkipped( 'WooCommerce lookup tables are required for integration test.' );
		}

		$this->ensure_cie_tables_exist();
		$this->clear_tables_before_fixture();
		$this->configure_test_settings();
		$this->build_fixture_orders();
		$this->insert_fixture_orders();
	}

	/**
	 * Test teardown.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->cleanup_fixture_and_plugin_data();
		parent::tearDown();
	}

	/**
	 * Verifies full rebuild creates expected associations and log status.
	 *
	 * @return void
	 */
	public function test_full_rebuild_produces_correct_associations(): void {
		global $wpdb;

		$table_associations = $wpdb->prefix . 'ci_associations';
		$table_log          = $wpdb->prefix . 'ci_rebuild_log';

		$expected_lift_45 = $this->calculate_lift_from_fixture( 4, 5 );
		$this->assertLessThan( 1.0, $expected_lift_45, 'Fixture must produce lift < 1.0 for pair 4+5.' );

		$miner  = $this->build_miner();
		$result = $miner->run_full_rebuild();

		$latest_error_message = '';
		if ( ! $result && $this->table_exists( $table_log ) ) {
			$latest_error_message = (string) $wpdb->get_var(
				"SELECT error_message FROM {$table_log} WHERE status = 'failed' ORDER BY id DESC LIMIT 1"
			);
		}
		if ( ! $result && '' === $latest_error_message ) {
			$health_snapshot = get_option( CIE_Miner::OPTION_HEALTH_SNAPSHOT, array() );
			if ( is_array( $health_snapshot ) && ! empty( $health_snapshot['error_message'] ) ) {
				$latest_error_message = (string) $health_snapshot['error_message'];
			}
		}

		$this->assertTrue(
			$result,
			'run_full_rebuild() should complete successfully. ' . $latest_error_message
		);

		$has_12 = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_associations} WHERE product_id = %d AND associated_product_id = %d",
				1,
				2
			)
		);
		$this->assertGreaterThan( 0, $has_12, 'Expected association 1->2 was not found.' );

		$has_13 = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_associations} WHERE product_id = %d AND associated_product_id = %d",
				1,
				3
			)
		);
		$this->assertGreaterThan( 0, $has_13, 'Expected association 1->3 was not found.' );

		$has_45 = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_associations} WHERE product_id = %d AND associated_product_id = %d",
				4,
				5
			)
		);
		$this->assertSame( 0, $has_45, 'Association 4->5 should be filtered out by min_lift.' );

		$completed_row = $wpdb->get_row(
			"SELECT run_id, status FROM {$table_log} WHERE status = 'completed' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);
		$this->assertNotEmpty( $completed_row, 'Expected completed rebuild log row was not found.' );
		$this->assertArrayHasKey( 'run_id', $completed_row );

		$running_for_same_run = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_log} WHERE run_id = %s AND status = %s",
				(string) $completed_row['run_id'],
				'running'
			)
		);
		$this->assertSame( 0, $running_for_same_run, 'No running row should remain for completed run.' );
	}

	/**
	 * Loads class dependencies needed for integration execution.
	 *
	 * @return void
	 */
	private function load_dependencies(): void {
		require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-settings.php';
		require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-db-migrator.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-data-extractor.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-pair-counter.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-association-calculator.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-scorer.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-association-repository.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-rebuild-log-repository.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-lock-manager.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-miner.php';
	}

	/**
	 * Returns true when WC lookup tables exist.
	 *
	 * @return bool
	 */
	private function has_required_wc_tables(): bool {
		global $wpdb;

		$order_stats  = $wpdb->prefix . 'wc_order_stats';
		$order_lookup = $wpdb->prefix . 'wc_order_product_lookup';

		return $this->table_exists( $order_stats ) && $this->table_exists( $order_lookup );
	}

	/**
	 * Ensures plugin custom tables required by miner exist.
	 *
	 * @return void
	 */
	private function ensure_cie_tables_exist(): void {
		global $wpdb;

		$charset_collate        = $wpdb->get_charset_collate();
		$table_associations     = $wpdb->prefix . 'ci_associations';
		$table_associations_tmp = $wpdb->prefix . 'ci_associations_temp';
		$table_pair_counts      = $wpdb->prefix . 'ci_pair_counts';
		$table_rebuild_log      = $wpdb->prefix . 'ci_rebuild_log';
		$table_overrides        = $wpdb->prefix . 'ci_overrides';

		$associations_sql = "CREATE TABLE IF NOT EXISTS {$table_associations} (
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

		$associations_temp_sql = "CREATE TABLE IF NOT EXISTS {$table_associations_tmp} (
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

		$pair_counts_sql = "CREATE TABLE IF NOT EXISTS {$table_pair_counts} (
			product_a BIGINT UNSIGNED NOT NULL,
			product_b BIGINT UNSIGNED NOT NULL,
			pair_count INT UNSIGNED NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (product_a, product_b)
		) {$charset_collate};";

		$rebuild_log_sql = "CREATE TABLE IF NOT EXISTS {$table_rebuild_log} (
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

		$overrides_sql = "CREATE TABLE IF NOT EXISTS {$table_overrides} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			associated_product_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(10) NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_override (product_id, associated_product_id)
		) {$charset_collate};";

		$queries = array(
			$associations_sql,
			$associations_temp_sql,
			$pair_counts_sql,
			$rebuild_log_sql,
			$overrides_sql,
		);

		foreach ( $queries as $query ) {
			$result = $wpdb->query( $query );
			$this->assertNotFalse( $result, 'Failed creating required CIE table: ' . $wpdb->last_error );
		}
	}

	/**
	 * Clears relevant tables before fixture insertion.
	 *
	 * @return void
	 */
	private function clear_tables_before_fixture(): void {
		global $wpdb;

		$fixture_order_ids = range(
			self::FIXTURE_START_ORDER_ID,
			self::FIXTURE_START_ORDER_ID + self::FIXTURE_ORDER_COUNT - 1
		);
		$placeholders      = implode( ', ', array_fill( 0, count( $fixture_order_ids ), '%d' ) );
		$table_lookup      = $wpdb->prefix . 'wc_order_product_lookup';
		$table_stats       = $wpdb->prefix . 'wc_order_stats';

		if ( $this->table_exists( $table_lookup ) ) {
			$delete_lookup_query = $this->prepare_dynamic_query(
				"DELETE FROM {$table_lookup} WHERE order_id IN ({$placeholders})",
				$fixture_order_ids
			);
			$wpdb->query( $delete_lookup_query );
		}

		if ( $this->table_exists( $table_stats ) ) {
			$delete_stats_query = $this->prepare_dynamic_query(
				"DELETE FROM {$table_stats} WHERE order_id IN ({$placeholders})",
				$fixture_order_ids
			);
			$wpdb->query( $delete_stats_query );
		}

		$tables_to_truncate = array(
			$wpdb->prefix . 'ci_associations',
			$wpdb->prefix . 'ci_associations_temp',
			$wpdb->prefix . 'ci_pair_counts',
			$wpdb->prefix . 'ci_rebuild_log',
			$wpdb->prefix . 'ci_overrides',
		);

		foreach ( $tables_to_truncate as $table ) {
			if ( $this->table_exists( $table ) ) {
				$wpdb->query( "TRUNCATE TABLE {$table}" );
			}
		}

		delete_option( 'cie_rebuild_state' );
		delete_option( 'cie_lock_cie_rebuild_lock' );
	}

	/**
	 * Configures plugin settings used by this integration fixture.
	 *
	 * @return void
	 */
	private function configure_test_settings(): void {
		CIE_Settings::update(
			array(
				'enabled'           => 1,
				'lookback_days'     => 3650,
				'included_statuses' => array( 'wc-completed' ),
				'min_co_occurrence' => 2,
				'min_lift'          => 1.0,
				'variation_mode'    => 'individual',
			)
		);
	}

	/**
	 * Builds deterministic 20-order fixture.
	 *
	 * @return void
	 */
	private function build_fixture_orders(): void {
		$this->fixture_orders = array(
			self::FIXTURE_START_ORDER_ID + 0  => array( 1, 2, 4 ),
			self::FIXTURE_START_ORDER_ID + 1  => array( 1, 2, 4 ),
			self::FIXTURE_START_ORDER_ID + 2  => array( 1, 2, 4 ),
			self::FIXTURE_START_ORDER_ID + 3  => array( 1, 2, 4 ),
			self::FIXTURE_START_ORDER_ID + 4  => array( 1, 2, 5 ),
			self::FIXTURE_START_ORDER_ID + 5  => array( 1, 2, 5 ),
			self::FIXTURE_START_ORDER_ID + 6  => array( 1, 2, 5 ),
			self::FIXTURE_START_ORDER_ID + 7  => array( 1, 2, 5 ),
			self::FIXTURE_START_ORDER_ID + 8  => array( 1, 3, 4 ),
			self::FIXTURE_START_ORDER_ID + 9  => array( 1, 3, 4 ),
			self::FIXTURE_START_ORDER_ID + 10 => array( 1, 3, 5 ),
			self::FIXTURE_START_ORDER_ID + 11 => array( 4, 5 ),
			self::FIXTURE_START_ORDER_ID + 12 => array( 4, 5 ),
			self::FIXTURE_START_ORDER_ID + 13 => array( 4 ),
			self::FIXTURE_START_ORDER_ID + 14 => array( 4 ),
			self::FIXTURE_START_ORDER_ID + 15 => array( 4 ),
			self::FIXTURE_START_ORDER_ID + 16 => array( 4 ),
			self::FIXTURE_START_ORDER_ID + 17 => array( 5 ),
			self::FIXTURE_START_ORDER_ID + 18 => array( 5 ),
			self::FIXTURE_START_ORDER_ID + 19 => array( 5 ),
		);
	}

	/**
	 * Inserts fixture rows into wc_order_stats and wc_order_product_lookup.
	 *
	 * @return void
	 */
	private function insert_fixture_orders(): void {
		global $wpdb;

		$table_stats  = $wpdb->prefix . 'wc_order_stats';
		$table_lookup = $wpdb->prefix . 'wc_order_product_lookup';
		$order_item_id = 9900001;
		$now_local     = current_time( 'mysql' );
		$now_gmt       = gmdate( 'Y-m-d H:i:s' );

		foreach ( $this->fixture_orders as $order_id => $product_ids ) {
			$stats_row = array(
				'order_id'            => $order_id,
				'parent_id'           => 0,
				'status'              => 'wc-completed',
				'date_created'        => $now_local,
				'date_created_gmt'    => $now_gmt,
				'num_items_sold'      => count( $product_ids ),
				'total_sales'         => (float) ( 10 * count( $product_ids ) ),
				'tax_total'           => 0.0,
				'shipping_total'      => 0.0,
				'net_total'           => (float) ( 10 * count( $product_ids ) ),
				'returning_customer'  => 0,
				'customer_id'         => 1,
			);

			$inserted_stats = $wpdb->insert(
				$table_stats,
				$this->build_insert_row_for_table( $table_stats, $stats_row )
			);
			$this->assertNotFalse( $inserted_stats, 'Failed inserting fixture wc_order_stats row: ' . $wpdb->last_error );

			foreach ( $product_ids as $product_id ) {
				$lookup_row = array(
					'order_item_id'        => $order_item_id++,
					'order_id'             => $order_id,
					'product_id'           => $product_id,
					'variation_id'         => 0,
					'customer_id'          => 1,
					'date_created'         => $now_local,
					'product_qty'          => 1,
					'product_net_revenue'  => 10.0,
					'product_gross_revenue'=> 10.0,
					'coupon_amount'        => 0.0,
					'tax_amount'           => 0.0,
					'shipping_amount'      => 0.0,
					'shipping_tax_amount'  => 0.0,
				);

				$inserted_lookup = $wpdb->insert(
					$table_lookup,
					$this->build_insert_row_for_table( $table_lookup, $lookup_row )
				);
				$this->assertNotFalse( $inserted_lookup, 'Failed inserting fixture wc_order_product_lookup row: ' . $wpdb->last_error );
			}
		}
	}

	/**
	 * Builds miner with real dependencies.
	 *
	 * @return CIE_Miner
	 */
	private function build_miner(): CIE_Miner {
		return new CIE_Miner(
			new CIE_Data_Extractor(),
			new CIE_Pair_Counter(),
			new CIE_Association_Calculator(),
			new CIE_Scorer(),
			new CIE_Association_Repository(),
			new CIE_Rebuild_Log_Repository(),
			new CIE_Lock_Manager()
		);
	}

	/**
	 * Calculates directional lift from fixture counts.
	 *
	 * @param int $product_a Product A.
	 * @param int $product_b Product B.
	 * @return float
	 */
	private function calculate_lift_from_fixture( int $product_a, int $product_b ): float {
		$total_orders = count( $this->fixture_orders );
		if ( $total_orders <= 0 ) {
			return 0.0;
		}

		$count_ab = 0;
		$count_a  = 0;
		$count_b  = 0;

		foreach ( $this->fixture_orders as $products ) {
			if ( in_array( $product_a, $products, true ) ) {
				$count_a++;
			}
			if ( in_array( $product_b, $products, true ) ) {
				$count_b++;
			}
			if ( in_array( $product_a, $products, true ) && in_array( $product_b, $products, true ) ) {
				$count_ab++;
			}
		}

		if ( $count_a <= 0 || $count_b <= 0 ) {
			return 0.0;
		}

		$confidence_ab = (float) ( $count_ab / $count_a );
		$support_b     = (float) ( $count_b / $total_orders );

		if ( $support_b <= 0.0 ) {
			return 0.0;
		}

		return (float) ( $confidence_ab / $support_b );
	}

	/**
	 * Cleans fixture and custom table data.
	 *
	 * @return void
	 */
	private function cleanup_fixture_and_plugin_data(): void {
		global $wpdb;

		$order_ids = array_map( 'absint', array_keys( $this->fixture_orders ) );

		$table_lookup = $wpdb->prefix . 'wc_order_product_lookup';
		$table_stats  = $wpdb->prefix . 'wc_order_stats';

		if ( ! empty( $order_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );

			if ( $this->table_exists( $table_lookup ) ) {
				$delete_lookup_query = $this->prepare_dynamic_query(
					"DELETE FROM {$table_lookup} WHERE order_id IN ({$placeholders})",
					$order_ids
				);
				$wpdb->query( $delete_lookup_query );
			}

			if ( $this->table_exists( $table_stats ) ) {
				$delete_stats_query = $this->prepare_dynamic_query(
					"DELETE FROM {$table_stats} WHERE order_id IN ({$placeholders})",
					$order_ids
				);
				$wpdb->query( $delete_stats_query );
			}
		}

		$plugin_tables = array(
			$wpdb->prefix . 'ci_pair_counts',
			$wpdb->prefix . 'ci_associations_temp',
			$wpdb->prefix . 'ci_associations',
			$wpdb->prefix . 'ci_rebuild_log',
			$wpdb->prefix . 'ci_overrides',
		);

		foreach ( $plugin_tables as $table ) {
			if ( $this->table_exists( $table ) ) {
				$wpdb->query( "TRUNCATE TABLE {$table}" );
			}
		}

		delete_option( 'cie_rebuild_state' );
		delete_option( 'cie_lock_cie_rebuild_lock' );
	}

	/**
	 * Returns true if table exists in database.
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
	 * Builds insert row that respects table-specific required columns.
	 *
	 * @param string $table_name Table name.
	 * @param array  $base_data  Base row data.
	 * @return array
	 */
	private function build_insert_row_for_table( string $table_name, array $base_data ): array {
		$schema = $this->get_table_schema( $table_name );
		$row    = array();

		foreach ( $schema as $field => $column ) {
			if ( array_key_exists( $field, $base_data ) ) {
				$row[ $field ] = $base_data[ $field ];
				continue;
			}

			if ( 'YES' === strtoupper( (string) $column['Null'] ) ) {
				continue;
			}

			if ( null !== $column['Default'] ) {
				continue;
			}

			$extra = isset( $column['Extra'] ) ? strtolower( (string) $column['Extra'] ) : '';
			if ( false !== strpos( $extra, 'auto_increment' ) ) {
				continue;
			}

			$row[ $field ] = $this->default_value_for_type( (string) $column['Type'] );
		}

		return $row;
	}

	/**
	 * Returns table schema from cache.
	 *
	 * @param string $table_name Table name.
	 * @return array
	 */
	private function get_table_schema( string $table_name ): array {
		global $wpdb;

		if ( isset( $this->table_schema_cache[ $table_name ] ) ) {
			return $this->table_schema_cache[ $table_name ];
		}

		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$schema = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['Field'] ) ) {
				continue;
			}

			$schema[ (string) $row['Field'] ] = $row;
		}

		$this->table_schema_cache[ $table_name ] = $schema;
		return $schema;
	}

	/**
	 * Returns generic default value for a SQL column type.
	 *
	 * @param string $type SQL column type.
	 * @return mixed
	 */
	private function default_value_for_type( string $type ) {
		$type = strtolower( $type );

		if ( false !== strpos( $type, 'int' ) || false !== strpos( $type, 'decimal' ) || false !== strpos( $type, 'float' ) || false !== strpos( $type, 'double' ) ) {
			return 0;
		}

		if ( false !== strpos( $type, 'datetime' ) || false !== strpos( $type, 'timestamp' ) ) {
			return current_time( 'mysql' );
		}

		if ( false !== strpos( $type, 'date' ) ) {
			return gmdate( 'Y-m-d' );
		}

		return '';
	}

	/**
	 * Prepares dynamic SQL with parameter list.
	 *
	 * @param string $query  Query with placeholders.
	 * @param array  $params Parameters.
	 * @return string
	 */
	private function prepare_dynamic_query( string $query, array $params ): string {
		global $wpdb;

		return (string) call_user_func_array(
			array( $wpdb, 'prepare' ),
			array_merge( array( $query ), $params )
		);
	}
}
