<?php
/**
 * Admin AJAX handlers for operations controls.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin-only AJAX actions.
 */
class CIE_Ajax_Handlers {

	/**
	 * Pending queued-at option key.
	 *
	 * @var string
	 */
	const OPTION_REBUILD_QUEUED_AT = 'cie_rebuild_queued_at';

	/**
	 * Action Scheduler group for rebuild jobs.
	 *
	 * @var string
	 */
	const ACTION_GROUP = 'commerce-intelligence-engine';

	/**
	 * Registers admin AJAX actions.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_cie_trigger_rebuild', array( $this, 'handle_trigger_rebuild' ) );
		add_action( 'wp_ajax_cie_get_rebuild_status', array( $this, 'handle_get_rebuild_status' ) );
		add_action( 'wp_ajax_cie_clear_cache', array( $this, 'handle_clear_cache' ) );
	}

	/**
	 * Queues a rebuild run.
	 *
	 * @return void
	 */
	public function handle_trigger_rebuild(): void {
		if ( ! $this->verify_nonce_or_error( 'cie_rebuild_nonce' ) ) {
			return;
		}

		if ( ! $this->verify_capability_or_error() ) {
			return;
		}

		$queued = false;
		$mode   = $this->resolve_requested_rebuild_mode();
		$args   = array(
			'mode' => $mode,
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$has_scheduled = false;

			if ( function_exists( 'as_has_scheduled_action' ) ) {
				$has_scheduled = false !== as_has_scheduled_action( 'cie_run_rebuild', null, self::ACTION_GROUP );
			}

			if ( ! $has_scheduled ) {
				$action_id = as_enqueue_async_action( 'cie_run_rebuild', $args, self::ACTION_GROUP );
				$queued    = false !== $action_id;
			} else {
				$queued = true;
			}
		} else {
			$has_event = $this->is_rebuild_queued_in_wp_cron();

			if ( ! $has_event ) {
				$queued = (bool) wp_schedule_single_event( time() + 5, 'cie_run_rebuild', $args );
				if ( $queued && function_exists( 'spawn_cron' ) ) {
					spawn_cron( time() );
				}
			} else {
				$queued = true;
			}
		}

		if ( ! $queued ) {
			wp_send_json_error(
				array(
					'message' => __( 'Could not queue rebuild.', 'commerce-intelligence-engine' ),
				),
				500
			);
		}

		update_option( self::OPTION_REBUILD_QUEUED_AT, current_time( 'mysql' ), false );

		wp_send_json_success(
			array(
				'message' => __( 'Rebuild queued', 'commerce-intelligence-engine' ),
				'mode'    => $mode,
			)
		);
	}

	/**
	 * Returns rebuild status payload for admin polling.
	 *
	 * @return void
	 */
	public function handle_get_rebuild_status(): void {
		if ( ! $this->verify_nonce_or_error( 'cie_status_nonce' ) ) {
			return;
		}

		if ( ! $this->verify_capability_or_error() ) {
			return;
		}

		if ( ! class_exists( 'CIE_Rebuild_Log_Repository' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-rebuild-log-repository.php';
		}

		$repository = new CIE_Rebuild_Log_Repository();
		$recent     = $repository->get_recent( 1 );
		$last_run   = ( ! empty( $recent ) && is_array( $recent[0] ) ) ? $recent[0] : array();
		$state      = get_option( 'cie_rebuild_state', array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$status               = isset( $last_run['status'] ) ? sanitize_key( (string) $last_run['status'] ) : 'idle';
		$orders_processed     = isset( $last_run['orders_processed'] ) ? absint( $last_run['orders_processed'] ) : 0;
		$associations_written = isset( $last_run['associations_written'] ) ? absint( $last_run['associations_written'] ) : 0;
		$duration_seconds     = isset( $last_run['duration_seconds'] ) ? absint( $last_run['duration_seconds'] ) : 0;
		$started_at           = isset( $last_run['started_at'] ) ? sanitize_text_field( (string) $last_run['started_at'] ) : '';
		$error_message        = isset( $last_run['error_message'] ) ? sanitize_text_field( (string) $last_run['error_message'] ) : '';
		$queued_at            = get_option( self::OPTION_REBUILD_QUEUED_AT, '' );

		if ( ! is_string( $queued_at ) ) {
			$queued_at = '';
		}

		if ( isset( $state['status'] ) && is_scalar( $state['status'] ) ) {
			$status = sanitize_key( (string) $state['status'] );
		}

		if ( isset( $state['orders_processed'] ) && is_scalar( $state['orders_processed'] ) ) {
			$orders_processed = absint( $state['orders_processed'] );
		}

		if ( isset( $state['associations_written'] ) && is_scalar( $state['associations_written'] ) ) {
			$associations_written = absint( $state['associations_written'] );
		}

		if ( isset( $state['duration_seconds'] ) && is_scalar( $state['duration_seconds'] ) ) {
			$duration_seconds = absint( $state['duration_seconds'] );
		}

		if ( isset( $state['started_at'] ) && is_scalar( $state['started_at'] ) ) {
			$started_at = sanitize_text_field( (string) $state['started_at'] );
		}

		if ( ! in_array( $status, array( 'running', 'queued' ), true ) && $this->is_rebuild_queued() ) {
			$status        = 'queued';
			$started_at    = '' !== $queued_at ? $queued_at : current_time( 'mysql' );
			$orders_processed = 0;
			$associations_written = 0;
			$duration_seconds = 0;
			$error_message = '';
		}

		if ( ! $this->is_rebuild_queued() && in_array( $status, array( 'completed', 'failed', 'idle' ), true ) ) {
			delete_option( self::OPTION_REBUILD_QUEUED_AT );
		}

		wp_send_json_success(
			array(
				'status'               => $status,
				'orders_processed'     => $orders_processed,
				'associations_written' => $associations_written,
				'duration_seconds'     => $duration_seconds,
				'started_at'           => $started_at,
				'error_message'        => $error_message,
			)
		);
	}

	/**
	 * Clears recommendations cache namespace.
	 *
	 * @return void
	 */
	public function handle_clear_cache(): void {
		if ( ! $this->verify_nonce_or_error( 'cie_cache_nonce' ) ) {
			return;
		}

		if ( ! $this->verify_capability_or_error() ) {
			return;
		}

		if ( ! class_exists( 'CIE_Cache' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-cache.php';
		}

		$cache = new CIE_Cache();
		$count = $cache->flush_namespace( 'cie_recs' );

		wp_send_json_success(
			array(
				'count' => (int) $count,
			)
		);
	}

	/**
	 * Verifies AJAX nonce and emits 403 JSON on failure.
	 *
	 * @param string $action Nonce action.
	 * @return bool
	 */
	private function verify_nonce_or_error( string $action ): bool {
		$nonce = $this->get_request_nonce();

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid nonce', 'commerce-intelligence-engine' ),
				),
				403
			);

			return false;
		}

		return true;
	}

	/**
	 * Detects whether a rebuild action/event is pending.
	 *
	 * @return bool
	 */
	private function is_rebuild_queued(): bool {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return false !== as_has_scheduled_action( 'cie_run_rebuild', null, self::ACTION_GROUP );
		}

		return $this->is_rebuild_queued_in_wp_cron();
	}

	/**
	 * Resolves requested rebuild mode.
	 *
	 * Defaults to incremental when a baseline likely exists, otherwise full.
	 *
	 * @return string
	 */
	private function resolve_requested_rebuild_mode(): string {
		$raw_mode = '';

		if ( isset( $_REQUEST['mode'] ) && ! is_array( $_REQUEST['mode'] ) ) {
			$raw_mode = sanitize_key( (string) wp_unslash( $_REQUEST['mode'] ) );
		}

		if ( in_array( $raw_mode, array( 'full', 'incremental' ), true ) ) {
			return $raw_mode;
		}

		return $this->has_incremental_baseline_hint() ? 'incremental' : 'full';
	}

	/**
	 * Returns true when baseline options suggest incremental can run.
	 *
	 * Final validity checks still happen in CIE_Miner.
	 *
	 * @return bool
	 */
	private function has_incremental_baseline_hint(): bool {
		$last_order_id = absint( get_option( 'cie_last_processed_order_id', 0 ) );
		if ( $last_order_id <= 0 ) {
			return false;
		}

		$snapshot = get_option( 'cie_incremental_snapshot', null );
		if ( ! is_array( $snapshot ) ) {
			return false;
		}

		$total_orders = isset( $snapshot['total_orders'] ) ? absint( $snapshot['total_orders'] ) : 0;
		if ( $total_orders <= 0 ) {
			return false;
		}

		return isset( $snapshot['product_order_counts'] ) && is_array( $snapshot['product_order_counts'] ) && ! empty( $snapshot['product_order_counts'] );
	}

	/**
	 * Returns true when any supported wp-cron rebuild event is queued.
	 *
	 * @return bool
	 */
	private function is_rebuild_queued_in_wp_cron(): bool {
		if ( false !== wp_next_scheduled( 'cie_run_rebuild' ) ) {
			return true;
		}

		if ( false !== wp_next_scheduled( 'cie_run_rebuild', array( 'mode' => 'incremental' ) ) ) {
			return true;
		}

		return false !== wp_next_scheduled( 'cie_run_rebuild', array( 'mode' => 'full' ) );
	}

	/**
	 * Verifies capability and emits 403 JSON on failure.
	 *
	 * @return bool
	 */
	private function verify_capability_or_error(): bool {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied', 'commerce-intelligence-engine' ),
				),
				403
			);

			return false;
		}

		return true;
	}

	/**
	 * Returns request nonce from known keys.
	 *
	 * @return string
	 */
	private function get_request_nonce(): string {
		$candidate_keys = array( 'nonce', '_ajax_nonce', '_wpnonce' );

		foreach ( $candidate_keys as $key ) {
			if ( ! isset( $_REQUEST[ $key ] ) || is_array( $_REQUEST[ $key ] ) ) {
				continue;
			}

			$nonce = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
			if ( '' !== $nonce ) {
				return $nonce;
			}
		}

		return '';
	}
}
