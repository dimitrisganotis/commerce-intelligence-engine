<?php
/**
 * WP-CLI commands for CIE.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Command group: wp cie.
 */
class CIE_CLI {

	/**
	 * Runs a full rebuild immediately.
	 *
	 * ## OPTIONS
	 *
	 * [--incremental]
	 * : Run incremental rebuild mode (falls back to full rebuild when baseline is invalid).
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function rebuild( $args, $assoc_args ): void {
		unset( $args );

		if ( isset( $assoc_args['incremental'] ) ) {
			$miner = $this->build_miner();

			WP_CLI::line( 'Running incremental rebuild...' );
			$ok = $miner->run_incremental_rebuild();

			if ( ! $ok ) {
				WP_CLI::error( 'Incremental rebuild failed.' );
				return;
			}

			WP_CLI::success( 'Incremental rebuild completed.' );
			return;
		}

		$miner = $this->build_miner();

		WP_CLI::line( 'Running full rebuild...' );
		$ok = $miner->run_full_rebuild();

		if ( ! $ok ) {
			WP_CLI::error( 'Rebuild failed.' );
			return;
		}

		WP_CLI::success( 'Rebuild completed.' );
	}

	/**
	 * Prints latest rebuild status row.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function status( $args, $assoc_args ): void {
		unset( $args, $assoc_args );

		$this->require_file( 'includes/class-commerce-intelligence-engine-db-migrator.php' );
		$this->require_file( 'includes/engine/class-commerce-intelligence-engine-rebuild-log-repository.php' );

		$repository = new CIE_Rebuild_Log_Repository();
		$rows       = $repository->get_recent( 1 );

		if ( empty( $rows ) ) {
			WP_CLI::line( 'No rebuild runs found.' );
			return;
		}

		$fields = array(
			'run_id',
			'mode',
			'status',
			'started_at',
			'completed_at',
			'duration_seconds',
			'orders_processed',
			'baskets_processed',
			'pairs_counted',
			'associations_written',
			'memory_peak_bytes',
			'error_message',
		);

		$normalized = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$entry = array();
			foreach ( $fields as $field ) {
				$entry[ $field ] = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
			}

			$normalized[] = $entry;
		}

		\WP_CLI\Utils\format_items( 'table', $normalized, $fields );
	}

	/**
	 * Flushes recommendations cache namespace.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function flush_cache( $args, $assoc_args ): void {
		unset( $args, $assoc_args );

		$this->require_file( 'includes/engine/class-commerce-intelligence-engine-cache.php' );

		$cache = new CIE_Cache();
		$count = $cache->flush_namespace( 'cie_recs' );

		WP_CLI::success( sprintf( 'Cleared %d cache entries.', absint( $count ) ) );
	}

	/**
	 * Shows associations for a product.
	 *
	 * ## OPTIONS
	 *
	 * <product_id>
	 * : Base product ID.
	 *
	 * [--limit=<limit>]
	 * : Maximum number of rows to display. Default: 10.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function associations( $args, $assoc_args ): void {
		$product_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		if ( $product_id <= 0 ) {
			WP_CLI::error( 'A valid product ID is required.' );
			return;
		}

		$limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 10;
		$limit = max( 1, $limit );

		$this->require_file( 'includes/class-commerce-intelligence-engine-db-migrator.php' );
		$this->require_file( 'includes/engine/class-commerce-intelligence-engine-association-repository.php' );

		$repository = new CIE_Association_Repository();
		$rows       = $repository->get_for_product( $product_id, $limit );

		if ( empty( $rows ) ) {
			WP_CLI::line( 'No associations found for this product.' );
			return;
		}

		$items = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$associated_product_id = isset( $row['associated_product_id'] ) ? absint( $row['associated_product_id'] ) : 0;
			$product_name          = get_the_title( $associated_product_id );
			if ( '' === $product_name ) {
				$product_name = sprintf( 'Product #%d', $associated_product_id );
			}

			$items[] = array(
				'associated_product_id' => (string) $associated_product_id,
				'product_name'          => $product_name,
				'confidence'            => isset( $row['confidence'] ) ? (string) $row['confidence'] : '',
				'lift'                  => isset( $row['lift'] ) ? (string) $row['lift'] : '',
				'score'                 => isset( $row['score'] ) ? (string) $row['score'] : '',
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$items,
			array( 'associated_product_id', 'product_name', 'confidence', 'lift', 'score' )
		);
	}

	/**
	 * Builds miner with dependencies.
	 *
	 * @return CIE_Miner
	 */
	private function build_miner(): CIE_Miner {
		$this->require_file( 'includes/class-commerce-intelligence-engine-settings.php' );
		$this->require_file( 'includes/class-commerce-intelligence-engine-db-migrator.php' );
		$this->require_file( 'includes/engine/class-commerce-intelligence-engine-data-extractor.php' );
		$this->require_file( 'includes/engine/class-commerce-intelligence-engine-pair-counter.php' );
		$this->require_file( 'includes/engine/class-commerce-intelligence-engine-association-calculator.php' );
		$this->require_file( 'includes/engine/class-commerce-intelligence-engine-scorer.php' );
		$this->require_file( 'includes/engine/class-commerce-intelligence-engine-association-repository.php' );
		$this->require_file( 'includes/engine/class-commerce-intelligence-engine-rebuild-log-repository.php' );
		$this->require_file( 'includes/engine/class-commerce-intelligence-engine-lock-manager.php' );
		$this->require_file( 'includes/engine/class-commerce-intelligence-engine-miner.php' );

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
	 * Requires a plugin file once.
	 *
	 * @param string $relative_path Relative path from plugin root.
	 * @return void
	 */
	private function require_file( string $relative_path ): void {
		$file = CIE_PLUGIN_DIR . ltrim( $relative_path, '/' );
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'cie', 'CIE_CLI' );
}
