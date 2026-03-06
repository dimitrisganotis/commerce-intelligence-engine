<?php

/**
 * Rebuild miner orchestrator.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates full and incremental rebuild flows via injected services.
 */
class CIE_Miner {

	/**
	 * Lock key used during rebuild.
	 *
	 * @var string
	 */
	const LOCK_KEY = 'cie_rebuild_lock';

	/**
	 * Lock TTL in seconds.
	 *
	 * @var int
	 */
	const LOCK_TTL = 1800;

	/**
	 * Resume state max age in seconds.
	 *
	 * @var int
	 */
	const STATE_MAX_AGE = 1800;

	/**
	 * Rebuild state option key.
	 *
	 * @var string
	 */
	const OPTION_REBUILD_STATE = 'cie_rebuild_state';

	/**
	 * Pending queued-at option key.
	 *
	 * @var string
	 */
	const OPTION_REBUILD_QUEUED_AT = 'cie_rebuild_queued_at';

	/**
	 * Last processed order cursor option key.
	 *
	 * @var string
	 */
	const OPTION_LAST_PROCESSED_ORDER_ID = 'cie_last_processed_order_id';

	/**
	 * Incremental snapshot option key.
	 *
	 * @var string
	 */
	const OPTION_INCREMENTAL_SNAPSHOT = 'cie_incremental_snapshot';

	/**
	 * Last rebuild run timestamp option key.
	 *
	 * @var string
	 */
	const OPTION_LAST_RUN_AT = 'cie_last_run_at';

	/**
	 * Last rebuild run mode option key.
	 *
	 * @var string
	 */
	const OPTION_LAST_RUN_MODE = 'cie_last_run_mode';

	/**
	 * Health snapshot option key.
	 *
	 * @var string
	 */
	const OPTION_HEALTH_SNAPSHOT = 'cie_health_snapshot';

	/**
	 * Rebuild checkpoint schema version.
	 *
	 * @var int
	 */
	const REBUILD_STATE_SCHEMA_VERSION = 1;

	/**
	 * Extractor service.
	 *
	 * @var CIE_Data_Extractor
	 */
	private $extractor;

	/**
	 * Pair counter service.
	 *
	 * @var CIE_Pair_Counter
	 */
	private $pair_counter;

	/**
	 * Association calculator service.
	 *
	 * @var CIE_Association_Calculator
	 */
	private $calculator;

	/**
	 * Scorer service.
	 *
	 * @var CIE_Scorer
	 */
	private $scorer;

	/**
	 * Association repository service.
	 *
	 * @var CIE_Association_Repository
	 */
	private $assoc_repo;

	/**
	 * Rebuild log repository service.
	 *
	 * @var CIE_Rebuild_Log_Repository
	 */
	private $log_repo;

	/**
	 * Lock manager service.
	 *
	 * @var CIE_Lock_Manager
	 */
	private $lock;

	/**
	 * Current run ID.
	 *
	 * @var string
	 */
	private $current_run_id = '';

	/**
	 * Current lock token.
	 *
	 * @var string
	 */
	private $lock_token = '';

	/**
	 * Constructor.
	 *
	 * @param CIE_Data_Extractor           $extractor    Extractor service.
	 * @param CIE_Pair_Counter             $pair_counter Pair counter service.
	 * @param CIE_Association_Calculator   $calculator   Calculator service.
	 * @param CIE_Scorer                   $scorer       Scorer service.
	 * @param CIE_Association_Repository   $assoc_repo   Association repository.
	 * @param CIE_Rebuild_Log_Repository   $log_repo     Rebuild log repository.
	 * @param CIE_Lock_Manager             $lock         Lock manager.
	 */
	public function __construct(
		CIE_Data_Extractor $extractor,
		CIE_Pair_Counter $pair_counter,
		CIE_Association_Calculator $calculator,
		CIE_Scorer $scorer,
		CIE_Association_Repository $assoc_repo,
		CIE_Rebuild_Log_Repository $log_repo,
		CIE_Lock_Manager $lock
	) {
		$this->extractor    = $extractor;
		$this->pair_counter = $pair_counter;
		$this->calculator   = $calculator;
		$this->scorer       = $scorer;
		$this->assoc_repo   = $assoc_repo;
		$this->log_repo     = $log_repo;
		$this->lock         = $lock;
	}

	/**
	 * Runs a full rebuild.
	 *
	 * @return bool
	 */
	public function run_full_rebuild(): bool {
		return $this->run_rebuild( 'full' );
	}

	/**
	 * Runs an incremental rebuild and falls back to full rebuild when baseline is invalid.
	 *
	 * @return bool
	 */
	public function run_incremental_rebuild(): bool {
		$baseline = $this->get_incremental_baseline();
		if ( null === $baseline ) {
			error_log( 'CIE incremental rebuild fallback: invalid incremental baseline, running full rebuild.' );
			return $this->run_rebuild( 'full' );
		}

		return $this->run_rebuild( 'incremental', $baseline );
	}

	/**
	 * Runs rebuild flow for the selected mode.
	 *
	 * @param string     $mode                 Rebuild mode.
	 * @param array|null $incremental_baseline Baseline payload for incremental mode.
	 * @return bool
	 */
	private function run_rebuild( string $mode, ?array $incremental_baseline = null ): bool {
		$mode = sanitize_key( $mode );
		if ( ! in_array( $mode, array( 'full', 'incremental' ), true ) ) {
			$mode = 'full';
		}

		$this->current_run_id = $this->resolve_run_id( $mode );
		$this->lock_token     = $this->lock->acquire( self::LOCK_KEY, self::LOCK_TTL );

		if ( false === $this->lock_token || '' === $this->lock_token ) {
			error_log( 'CIE rebuild skipped: lock is already held.' );
			return false;
		}

		$started_at     = time();
		$settings       = array();
		$retention_runs = 90;
		$metrics        = array(
			'orders_processed'     => 0,
			'baskets_processed'    => 0,
			'pairs_counted'        => 0,
			'associations_written' => 0,
		);

		do_action( 'cie_rebuild_started', $this->current_run_id, $mode );
		$this->log_repo->start_run( $this->current_run_id, $mode );
		delete_option( self::OPTION_REBUILD_QUEUED_AT );

		try {
			$settings       = CIE_Settings::get();
			$retention_runs = $this->resolve_rebuild_log_retention_runs( $settings );
			$resume_state   = $this->get_rebuild_state();
			$lookback_days  = isset( $settings['lookback_days'] ) ? max( 1, absint( $settings['lookback_days'] ) ) : 180;
			$chunk_size     = isset( $settings['rebuild_chunk_size'] ) ? max( 1, absint( $settings['rebuild_chunk_size'] ) ) : 1000;

			if ( is_array( $resume_state ) ) {
				$resume_mode = isset( $resume_state['mode'] ) ? sanitize_key( (string) $resume_state['mode'] ) : '';
				if ( '' !== $resume_mode && $resume_mode !== $mode ) {
					$resume_state = null;
				}
			}

			$last_order_id        = 0;
			$product_order_counts = array();
			$pair_last_seen_map   = array();
			$total_orders         = 0;
			$chunk_index          = 0;

			if ( null === $resume_state ) {
				if ( ! $this->assoc_repo->truncate_temp() ) {
					$error_message = $this->assoc_repo->get_last_error();
					if ( '' !== $error_message ) {
						throw new RuntimeException( 'Failed to truncate temp associations table. ' . $error_message );
					}

					throw new RuntimeException( 'Failed to truncate temp associations table.' );
				}

				if ( 'incremental' === $mode ) {
					$baseline             = is_array( $incremental_baseline ) ? $incremental_baseline : array();
					$last_order_id        = isset( $baseline['last_order_id'] ) ? absint( $baseline['last_order_id'] ) : 0;
					$total_orders         = isset( $baseline['total_orders'] ) ? absint( $baseline['total_orders'] ) : 0;
					$product_order_counts = isset( $baseline['product_order_counts'] ) && is_array( $baseline['product_order_counts'] )
						? $this->sanitize_product_order_counts_map( $baseline['product_order_counts'] )
						: array();
					$pair_last_seen_map   = $this->load_existing_pair_last_seen_map();
				} else {
					if ( ! $this->pair_counter->truncate_counts() ) {
						throw new RuntimeException( 'Failed to truncate pair counts table.' );
					}
				}
			} else {
				$last_order_id        = isset( $resume_state['last_order_id'] ) ? absint( $resume_state['last_order_id'] ) : 0;
				$total_orders         = isset( $resume_state['total_orders'] ) ? absint( $resume_state['total_orders'] ) : 0;
				$product_order_counts = isset( $resume_state['product_order_counts'] ) && is_array( $resume_state['product_order_counts'] )
					? $this->sanitize_product_order_counts_map( $resume_state['product_order_counts'] )
					: array();
				$pair_last_seen_map   = isset( $resume_state['pair_last_seen_map'] ) && is_array( $resume_state['pair_last_seen_map'] )
					? $this->sanitize_pair_last_seen_map( $resume_state['pair_last_seen_map'] )
					: array();

				$metrics['orders_processed']  = isset( $resume_state['orders_processed'] ) ? absint( $resume_state['orders_processed'] ) : 0;
				$metrics['baskets_processed'] = isset( $resume_state['baskets_processed'] ) ? absint( $resume_state['baskets_processed'] ) : 0;
				$metrics['pairs_counted']     = isset( $resume_state['pairs_counted'] ) ? absint( $resume_state['pairs_counted'] ) : 0;
			}

			while ( true ) {
				$order_lines = $this->extractor->fetch_order_lines_since( $lookback_days, $last_order_id, $chunk_size );
				if ( empty( $order_lines ) ) {
					break;
				}

				$basket_last_seen = array();
				$baskets          = $this->extractor->build_baskets( $order_lines, $basket_last_seen );
				$pair_counts      = $this->pair_counter->count_pairs( $baskets );
				$this->accumulate_pair_last_seen( $baskets, $basket_last_seen, $pair_last_seen_map );

				if ( ! $this->pair_counter->flush_counts( $pair_counts ) ) {
					throw new RuntimeException( 'Failed to flush pair counts.' );
				}

				$metrics['orders_processed']  += count( $order_lines );
				$metrics['baskets_processed'] += count( $baskets );
				$metrics['pairs_counted']     += array_sum( $pair_counts );

				$total_orders += count( $baskets );
				$this->accumulate_product_order_counts( $baskets, $product_order_counts );
				$last_order_id = $this->resolve_max_order_id( $order_lines, $last_order_id );

				$chunk_index++;
				if ( 0 === $chunk_index % 5 ) {
					$this->lock->heartbeat( self::LOCK_KEY, $this->lock_token );
				}

				$this->save_rebuild_state(
					array(
						'run_id'               => $this->current_run_id,
						'mode'                 => $mode,
						'status'               => 'running',
						'stage'                => 'extracting',
						'started_at'           => gmdate( 'Y-m-d H:i:s', $started_at ),
						'last_order_id'        => $last_order_id,
						'orders_processed'     => $metrics['orders_processed'],
						'baskets_processed'    => $metrics['baskets_processed'],
						'pairs_counted'        => $metrics['pairs_counted'],
						'product_order_counts' => $product_order_counts,
						'pair_last_seen_map'   => $pair_last_seen_map,
						'total_orders'         => $total_orders,
						'heartbeat_at'         => time(),
						'updated_at'           => time(),
					)
				);

				$this->log_repo->update_progress( $this->current_run_id, $metrics );

				if ( count( $order_lines ) < $chunk_size ) {
					break;
				}
			}

			$pair_counts_map = $this->pair_counter->get_all_counts();
			if ( 'incremental' === $mode && $total_orders > 0 && empty( $pair_counts_map ) ) {
				throw new RuntimeException( 'Incremental baseline is inconsistent: pair counts table is empty.' );
			}

			$rows = $this->calculator->calculate_metrics( $pair_counts_map, $product_order_counts, $total_orders, $pair_last_seen_map );
			$rows = $this->calculator->filter_by_thresholds(
				$rows,
				array(
					'min_co_occurrence' => isset( $settings['min_co_occurrence'] ) ? absint( $settings['min_co_occurrence'] ) : 0,
					'min_support'       => isset( $settings['min_support'] ) ? (float) $settings['min_support'] : 0.0,
					'min_confidence'    => isset( $settings['min_confidence'] ) ? (float) $settings['min_confidence'] : 0.0,
					'min_lift'          => isset( $settings['min_lift'] ) ? (float) $settings['min_lift'] : 0.0,
				)
			);

			$candidate_product_ids = $this->extract_candidate_product_ids( $rows );
			$meta_map              = $this->extractor->fetch_meta_map( $candidate_product_ids );
			$weights               = isset( $settings['weights'] ) && is_array( $settings['weights'] ) ? $settings['weights'] : array();
			$decay_rate            = isset( $settings['decay_rate'] ) ? (float) $settings['decay_rate'] : 0.01;
			$scored_rows           = $this->scorer->score_batch( $rows, $meta_map, $weights, $decay_rate );
			$persistable_rows      = $this->hydrate_persistable_rows( $scored_rows );

			if ( ! $this->assoc_repo->upsert_batch( $persistable_rows ) ) {
				throw new RuntimeException( 'Failed to upsert scored associations.' );
			}

			if ( ! $this->assoc_repo->replace_from_temp() ) {
				$error_message = $this->assoc_repo->get_last_error();
				if ( '' !== $error_message ) {
					throw new RuntimeException( 'Failed to replace live associations from temp. ' . $error_message );
				}

				throw new RuntimeException( 'Failed to replace live associations from temp.' );
			}

			$this->invalidate_caches();

			$metrics['associations_written'] = count( $persistable_rows );
			$metrics['duration_seconds']     = max( 0, time() - $started_at );
			$metrics['memory_peak_bytes']    = memory_get_peak_usage( true );

			$this->persist_incremental_baseline( $last_order_id, $product_order_counts, $total_orders, $settings );

			$this->log_repo->complete_run( $this->current_run_id, $metrics );
			$this->persist_run_snapshot( $mode, 'completed', $metrics );
			$this->prune_rebuild_log_runs( $retention_runs );
			do_action( 'cie_rebuild_completed', $this->current_run_id, $metrics );

			$this->clear_rebuild_state();
			$this->release_lock();

			return true;
		} catch ( Throwable $exception ) {
			$metrics['duration_seconds']  = max( 0, time() - $started_at );
			$metrics['memory_peak_bytes'] = memory_get_peak_usage( true );

			$this->log_repo->fail_run( $this->current_run_id, $exception->getMessage(), $metrics );
			$this->persist_run_snapshot( $mode, 'failed', $metrics, $exception->getMessage() );
			$this->prune_rebuild_log_runs( $retention_runs );
			do_action( 'cie_rebuild_failed', $this->current_run_id, $exception->getMessage(), $metrics );

			$this->release_lock();
			return false;
		}
	}

	/**
	 * Saves rebuild checkpoint state.
	 *
	 * @param array $state Checkpoint state.
	 * @return void
	 */
	public function save_rebuild_state( array $state ): void {
		$state['schema_version'] = self::REBUILD_STATE_SCHEMA_VERSION;
		$state['db_version']     = $this->get_installed_db_version();
		$state['updated_at']     = isset( $state['updated_at'] ) ? absint( $state['updated_at'] ) : time();

		if ( false === get_option( self::OPTION_REBUILD_STATE, false ) ) {
			add_option( self::OPTION_REBUILD_STATE, $state, '', 'no' );
			return;
		}

		update_option( self::OPTION_REBUILD_STATE, $state );
	}

	/**
	 * Returns resume state for current run if valid.
	 *
	 * @return array|null
	 */
	public function get_rebuild_state(): ?array {
		if ( '' === $this->current_run_id ) {
			return null;
		}

		$state = get_option( self::OPTION_REBUILD_STATE, null );
		if ( ! is_array( $state ) ) {
			return null;
		}

		$run_id = isset( $state['run_id'] ) ? (string) $state['run_id'] : '';
		if ( '' === $run_id || $run_id !== $this->current_run_id ) {
			return null;
		}

		if ( ! $this->is_state_fresh( $state ) ) {
			$this->clear_rebuild_state();
			return null;
		}

		if ( ! $this->is_state_compatible( $state ) ) {
			$this->clear_rebuild_state();
			return null;
		}

		return $state;
	}

	/**
	 * Clears rebuild checkpoint state.
	 *
	 * @return void
	 */
	public function clear_rebuild_state(): void {
		delete_option( self::OPTION_REBUILD_STATE );
	}

	/**
	 * Invalidates recommendation caches.
	 *
	 * @return void
	 */
	public function invalidate_caches(): void {
		if ( class_exists( 'CIE_Cache' ) && is_callable( array( 'CIE_Cache', 'flush_namespace' ) ) ) {
			call_user_func( array( 'CIE_Cache', 'flush_namespace' ), 'cie_recs' );
		} elseif ( class_exists( 'CIE_Cache' ) && method_exists( 'CIE_Cache', 'flush_namespace' ) ) {
			$cache = new CIE_Cache();
			$cache->flush_namespace( 'cie_recs' );
		}

		// Dashboard widget caches summary stats in a transient.
		delete_transient( 'cie_dashboard_widget_data' );
	}

	/**
	 * Resolves run ID for current rebuild execution.
	 *
	 * @return string
	 */
	private function resolve_run_id( string $mode ): string {
		$state = get_option( self::OPTION_REBUILD_STATE, null );
		if ( is_array( $state ) && $this->is_state_fresh( $state ) && $this->is_state_compatible( $state ) && ! empty( $state['run_id'] ) ) {
			$state_mode = isset( $state['mode'] ) ? sanitize_key( (string) $state['mode'] ) : '';
			if ( '' !== $state_mode && $state_mode !== sanitize_key( $mode ) ) {
				return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'cie-run-', true );
			}

			return (string) $state['run_id'];
		}

		if ( is_array( $state ) ) {
			$this->clear_rebuild_state();
		}

		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'cie-run-', true );
	}

	/**
	 * Checks whether checkpoint state is fresh enough to resume.
	 *
	 * @param array $state Raw state.
	 * @return bool
	 */
	private function is_state_fresh( array $state ): bool {
		$timestamp = 0;
		if ( isset( $state['updated_at'] ) ) {
			$timestamp = absint( $state['updated_at'] );
		} elseif ( isset( $state['heartbeat_at'] ) ) {
			$timestamp = absint( $state['heartbeat_at'] );
		}

		if ( $timestamp <= 0 ) {
			return false;
		}

		return ( time() - $timestamp ) < self::STATE_MAX_AGE;
	}

	/**
	 * Checks whether checkpoint state can be resumed on this schema version.
	 *
	 * @param array $state Raw state.
	 * @return bool
	 */
	private function is_state_compatible( array $state ): bool {
		$schema_version = isset( $state['schema_version'] ) ? absint( $state['schema_version'] ) : 0;
		if ( self::REBUILD_STATE_SCHEMA_VERSION !== $schema_version ) {
			return false;
		}

		$state_db_version = '';
		if ( isset( $state['db_version'] ) && is_scalar( $state['db_version'] ) ) {
			$state_db_version = sanitize_text_field( (string) $state['db_version'] );
		}

		if ( '' === $state_db_version ) {
			return false;
		}

		return hash_equals( $this->get_installed_db_version(), $state_db_version );
	}

	/**
	 * Returns sanitized incremental baseline payload when valid.
	 *
	 * @return array|null
	 */
	private function get_incremental_baseline(): ?array {
		$last_order_id = absint( get_option( self::OPTION_LAST_PROCESSED_ORDER_ID, 0 ) );
		if ( $last_order_id <= 0 ) {
			return null;
		}

		$snapshot = get_option( self::OPTION_INCREMENTAL_SNAPSHOT, null );
		if ( ! is_array( $snapshot ) ) {
			return null;
		}

		$total_orders = isset( $snapshot['total_orders'] ) ? absint( $snapshot['total_orders'] ) : 0;
		if ( $total_orders <= 0 ) {
			return null;
		}

		$product_order_counts = isset( $snapshot['product_order_counts'] ) && is_array( $snapshot['product_order_counts'] )
			? $this->sanitize_product_order_counts_map( $snapshot['product_order_counts'] )
			: array();
		if ( empty( $product_order_counts ) ) {
			return null;
		}

		$snapshot_signature = isset( $snapshot['settings_signature'] ) ? (string) $snapshot['settings_signature'] : '';
		$current_signature  = $this->build_incremental_settings_signature( CIE_Settings::get() );
		if ( '' === $snapshot_signature || $snapshot_signature !== $current_signature ) {
			return null;
		}

		if ( ! $this->has_pair_counts_rows() ) {
			return null;
		}

		return array(
			'last_order_id'        => $last_order_id,
			'total_orders'         => $total_orders,
			'product_order_counts' => $product_order_counts,
		);
	}

	/**
	 * Persists baseline data required by future incremental rebuilds.
	 *
	 * @param int   $last_order_id        Last processed order ID.
	 * @param array $product_order_counts Product order counts.
	 * @param int   $total_orders         Total eligible orders.
	 * @param array $settings             Active settings payload.
	 * @return void
	 */
	private function persist_incremental_baseline( int $last_order_id, array $product_order_counts, int $total_orders, array $settings ): void {
		$payload = array(
			'product_order_counts' => $this->sanitize_product_order_counts_map( $product_order_counts ),
			'total_orders'         => max( 0, absint( $total_orders ) ),
			'settings_signature'   => $this->build_incremental_settings_signature( $settings ),
			'updated_at'           => time(),
		);

		$this->upsert_option( self::OPTION_INCREMENTAL_SNAPSHOT, $payload, 'no' );
		$this->upsert_option( self::OPTION_LAST_PROCESSED_ORDER_ID, max( 0, absint( $last_order_id ) ), 'no' );
	}

	/**
	 * Builds a signature for settings that affect incremental baseline validity.
	 *
	 * @param array $settings Settings payload.
	 * @return string
	 */
	private function build_incremental_settings_signature( array $settings ): string {
		$statuses = array();
		if ( isset( $settings['included_statuses'] ) && is_array( $settings['included_statuses'] ) ) {
			foreach ( $settings['included_statuses'] as $status ) {
				if ( ! is_scalar( $status ) ) {
					continue;
				}

				$statuses[] = sanitize_key( (string) $status );
			}
		}

		$statuses = array_values( array_unique( $statuses ) );
		sort( $statuses );

		$excluded_category_ids = array();
		if ( isset( $settings['excluded_category_ids'] ) && is_array( $settings['excluded_category_ids'] ) ) {
			foreach ( $settings['excluded_category_ids'] as $category_id ) {
				$id = absint( $category_id );
				if ( $id > 0 ) {
					$excluded_category_ids[] = $id;
				}
			}
		}

		$excluded_category_ids = array_values( array_unique( $excluded_category_ids ) );
		sort( $excluded_category_ids );

		$payload = array(
			'lookback_days'         => isset( $settings['lookback_days'] ) ? absint( $settings['lookback_days'] ) : 180,
			'included_statuses'     => $statuses,
			'variation_mode'        => isset( $settings['variation_mode'] ) ? sanitize_key( (string) $settings['variation_mode'] ) : 'parent',
			'excluded_category_ids' => $excluded_category_ids,
		);

		return md5( wp_json_encode( $payload ) );
	}

	/**
	 * Sanitizes product count map.
	 *
	 * @param array $raw_counts Raw product counts.
	 * @return array
	 */
	private function sanitize_product_order_counts_map( array $raw_counts ): array {
		$sanitized = array();

		foreach ( $raw_counts as $product_id => $count ) {
			$pid = absint( $product_id );
			if ( $pid <= 0 ) {
				continue;
			}

			$sanitized[ $pid ] = max( 0, absint( $count ) );
		}

		return $sanitized;
	}

	/**
	 * Sanitizes pair last-seen map to canonical keys and integer timestamps.
	 *
	 * @param array $raw_map Raw pair map.
	 * @return array
	 */
	private function sanitize_pair_last_seen_map( array $raw_map ): array {
		$sanitized = array();

		foreach ( $raw_map as $pair_key => $value ) {
			$parsed = explode( ':', (string) $pair_key );
			if ( 2 !== count( $parsed ) ) {
				continue;
			}

			$product_a = absint( $parsed[0] );
			$product_b = absint( $parsed[1] );
			if ( $product_a <= 0 || $product_b <= 0 || $product_a === $product_b ) {
				continue;
			}

			$canonical_key = min( $product_a, $product_b ) . ':' . max( $product_a, $product_b );
			$timestamp     = 0;

			if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
				$timestamp = (int) $value;
			} elseif ( is_string( $value ) && '' !== $value ) {
				$parsed_time = strtotime( $value );
				if ( false !== $parsed_time ) {
					$timestamp = $parsed_time;
				}
			}

			if ( $timestamp <= 0 ) {
				continue;
			}

			if ( ! isset( $sanitized[ $canonical_key ] ) || $timestamp > (int) $sanitized[ $canonical_key ] ) {
				$sanitized[ $canonical_key ] = $timestamp;
			}
		}

		return $sanitized;
	}

	/**
	 * Loads last-seen map from current live directional association rows.
	 *
	 * @return array
	 */
	private function load_existing_pair_last_seen_map(): array {
		global $wpdb;

		$table = CIE_DB_Migrator::get_table_name( 'associations' );
		$rows  = $wpdb->get_results(
			"SELECT
				LEAST(product_id, associated_product_id) AS product_a,
				GREATEST(product_id, associated_product_id) AS product_b,
				MAX(last_seen_at) AS last_seen_at
			FROM {$table}
			WHERE last_seen_at IS NOT NULL
			GROUP BY product_a, product_b",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$map = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$product_a   = isset( $row['product_a'] ) ? absint( $row['product_a'] ) : 0;
			$product_b   = isset( $row['product_b'] ) ? absint( $row['product_b'] ) : 0;
			$last_seen_at = isset( $row['last_seen_at'] ) ? (string) $row['last_seen_at'] : '';

			if ( $product_a <= 0 || $product_b <= 0 || $product_a === $product_b || '' === $last_seen_at ) {
				continue;
			}

			$timestamp = strtotime( $last_seen_at );
			if ( false === $timestamp ) {
				continue;
			}

			$map[ $product_a . ':' . $product_b ] = (int) $timestamp;
		}

		return $map;
	}

	/**
	 * Returns whether pair-counts staging table contains rows.
	 *
	 * @return bool
	 */
	private function has_pair_counts_rows(): bool {
		global $wpdb;

		$table = CIE_DB_Migrator::get_table_name( 'pair_counts' );
		$found = $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" );

		return null !== $found;
	}

	/**
	 * Creates or updates an option while enforcing autoload behavior.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $value       Option value.
	 * @param string $autoload    yes|no.
	 * @return void
	 */
	private function upsert_option( string $option_name, $value, string $autoload = 'no' ): void {
		$autoload = ( 'yes' === $autoload ) ? 'yes' : 'no';

		if ( false === get_option( $option_name, false ) ) {
			add_option( $option_name, $value, '', $autoload );
			return;
		}

		update_option( $option_name, $value );
	}

	/**
	 * Returns installed DB schema version used for checkpoint compatibility.
	 *
	 * @return string
	 */
	private function get_installed_db_version(): string {
		$db_version = get_option( 'cie_db_version', '' );
		if ( is_scalar( $db_version ) ) {
			$db_version = sanitize_text_field( (string) $db_version );
			if ( '' !== $db_version ) {
				return $db_version;
			}
		}

		return defined( 'CIE_DB_VERSION' ) ? sanitize_text_field( (string) CIE_DB_VERSION ) : 'unknown';
	}

	/**
	 * Persists lightweight run metadata options for diagnostics.
	 *
	 * @param string $mode          Executed mode.
	 * @param string $status        Final status.
	 * @param array  $metrics       Run metrics.
	 * @param string $error_message Failure message.
	 * @return void
	 */
	private function persist_run_snapshot( string $mode, string $status, array $metrics, string $error_message = '' ): void {
		$mode          = in_array( $mode, array( 'full', 'incremental' ), true ) ? $mode : 'full';
		$status        = in_array( $status, array( 'completed', 'failed' ), true ) ? $status : 'failed';
		$finished_at   = gmdate( 'Y-m-d H:i:s' );
		$sanitized_err = sanitize_text_field( $error_message );

		$this->upsert_option( self::OPTION_LAST_RUN_AT, $finished_at, 'no' );
		$this->upsert_option( self::OPTION_LAST_RUN_MODE, $mode, 'no' );

		$snapshot = array(
			'status'               => $status,
			'mode'                 => $mode,
			'run_id'               => (string) $this->current_run_id,
			'reason'               => ( '' === $sanitized_err ) ? 'ok' : 'exception',
			'updated_at'           => time(),
			'duration_seconds'     => isset( $metrics['duration_seconds'] ) ? absint( $metrics['duration_seconds'] ) : 0,
			'orders_processed'     => isset( $metrics['orders_processed'] ) ? absint( $metrics['orders_processed'] ) : 0,
			'baskets_processed'    => isset( $metrics['baskets_processed'] ) ? absint( $metrics['baskets_processed'] ) : 0,
			'pairs_counted'        => isset( $metrics['pairs_counted'] ) ? absint( $metrics['pairs_counted'] ) : 0,
			'associations_written' => isset( $metrics['associations_written'] ) ? absint( $metrics['associations_written'] ) : 0,
			'memory_peak_bytes'    => isset( $metrics['memory_peak_bytes'] ) ? absint( $metrics['memory_peak_bytes'] ) : 0,
		);

		if ( '' !== $sanitized_err ) {
			$snapshot['error_message'] = $sanitized_err;
		}

		$this->upsert_option( self::OPTION_HEALTH_SNAPSHOT, $snapshot, 'no' );
	}

	/**
	 * Resolves rebuild log retention policy from settings.
	 *
	 * @param array $settings Settings payload.
	 * @return int
	 */
	private function resolve_rebuild_log_retention_runs( array $settings ): int {
		if ( ! isset( $settings['rebuild_log_retention_runs'] ) ) {
			return 90;
		}

		return max( 30, min( 365, absint( $settings['rebuild_log_retention_runs'] ) ) );
	}

	/**
	 * Applies rebuild log retention policy.
	 *
	 * @param int $retention_runs Number of recent runs to keep.
	 * @return void
	 */
	private function prune_rebuild_log_runs( int $retention_runs ): void {
		$retention_runs = max( 30, min( 365, absint( $retention_runs ) ) );
		$this->log_repo->prune_old_runs( $retention_runs );
	}

	/**
	 * Accumulates product-level order counts from baskets.
	 *
	 * @param array $baskets              Basket rows.
	 * @param array $product_order_counts Product count map by reference.
	 * @return void
	 */
	private function accumulate_product_order_counts( array $baskets, array &$product_order_counts ): void {
		foreach ( $baskets as $basket ) {
			if ( ! is_array( $basket ) ) {
				continue;
			}

			foreach ( $basket as $product_id ) {
				$pid = absint( $product_id );
				if ( $pid <= 0 ) {
					continue;
				}

				if ( ! isset( $product_order_counts[ $pid ] ) ) {
					$product_order_counts[ $pid ] = 0;
				}

				$product_order_counts[ $pid ]++;
			}
		}
	}

	/**
	 * Accumulates pair-level last-seen timestamps from basket rows.
	 *
	 * @param array $baskets             Basket rows.
	 * @param array $basket_last_seen    Basket timestamps aligned to basket index.
	 * @param array $pair_last_seen_map  Canonical pair map by reference.
	 * @return void
	 */
	private function accumulate_pair_last_seen( array $baskets, array $basket_last_seen, array &$pair_last_seen_map ): void {
		foreach ( $baskets as $index => $basket ) {
			if ( ! is_array( $basket ) ) {
				continue;
			}

			$last_seen_raw = isset( $basket_last_seen[ $index ] ) ? (string) $basket_last_seen[ $index ] : '';
			$last_seen_ts  = strtotime( $last_seen_raw );
			if ( false === $last_seen_ts ) {
				continue;
			}

			$product_ids = array();
			foreach ( $basket as $product_id ) {
				$pid = absint( $product_id );
				if ( $pid > 0 ) {
					$product_ids[] = $pid;
				}
			}

			$product_ids = array_values( array_unique( $product_ids ) );
			sort( $product_ids );

			$count = count( $product_ids );
			if ( $count < 2 ) {
				continue;
			}

			for ( $i = 0; $i < $count - 1; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$key = $product_ids[ $i ] . ':' . $product_ids[ $j ];

					if ( ! isset( $pair_last_seen_map[ $key ] ) || $last_seen_ts > (int) $pair_last_seen_map[ $key ] ) {
						$pair_last_seen_map[ $key ] = (int) $last_seen_ts;
					}
				}
			}
		}
	}

	/**
	 * Resolves max order ID from extracted lines.
	 *
	 * @param array $order_lines    Raw order lines.
	 * @param int   $fallback_value Fallback cursor value.
	 * @return int
	 */
	private function resolve_max_order_id( array $order_lines, int $fallback_value ): int {
		$max_order_id = absint( $fallback_value );

		foreach ( $order_lines as $line ) {
			if ( ! is_array( $line ) || ! isset( $line['order_id'] ) ) {
				continue;
			}

			$order_id = absint( $line['order_id'] );
			if ( $order_id > $max_order_id ) {
				$max_order_id = $order_id;
			}
		}

		return $max_order_id;
	}

	/**
	 * Extracts unique associated product IDs from association rows.
	 *
	 * @param array $rows Association rows.
	 * @return array
	 */
	private function extract_candidate_product_ids( array $rows ): array {
		$product_ids = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['associated_product_id'] ) ) {
				continue;
			}

			$product_id = absint( $row['associated_product_id'] );
			if ( $product_id <= 0 ) {
				continue;
			}

			$product_ids[] = $product_id;
		}

		return array_values( array_unique( $product_ids ) );
	}

	/**
	 * Shapes scored rows for repository persistence.
	 *
	 * @param array $rows Scored rows.
	 * @return array
	 */
	private function hydrate_persistable_rows( array $rows ): array {
		$persistable = array();
		$updated_at  = gmdate( 'Y-m-d H:i:s' );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row['source'] = isset( $row['source'] ) ? (string) $row['source'] : 'mined';
			if ( '' === $row['source'] ) {
				$row['source'] = 'mined';
			}

			$row['updated_at'] = $updated_at;

			if ( ! array_key_exists( 'last_seen_at', $row ) ) {
				$row['last_seen_at'] = null;
			}

			$persistable[] = $row;
		}

		return $persistable;
	}

	/**
	 * Releases rebuild lock token if held.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		if ( '' === $this->lock_token ) {
			return;
		}

		$this->lock->release( self::LOCK_KEY, $this->lock_token );
		$this->lock_token = '';
	}
}
