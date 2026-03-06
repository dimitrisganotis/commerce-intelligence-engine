<?php
/**
 * Scheduled rebuild dispatcher.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages cron and Action Scheduler rebuild dispatch.
 */
class Commerce_Intelligence_Engine_Scheduler {

	/**
	 * WP-Cron hook name.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'cie_scheduled_rebuild';

	/**
	 * Action Scheduler hook name.
	 *
	 * @var string
	 */
	const ACTION_HOOK = 'cie_run_rebuild';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	const ACTION_GROUP = 'commerce-intelligence-engine';

	/**
	 * Registers scheduler hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_intervals' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'handle_cron' ) );
		add_action( self::ACTION_HOOK, array( __CLASS__, 'handle_as_action' ) );
		add_action( 'updated_option', array( __CLASS__, 'maybe_reschedule_on_settings_update' ), 10, 3 );
		add_action( 'after_switch_theme', array( __CLASS__, 'maybe_reschedule_after_theme_switch' ) );
	}

	/**
	 * Registers custom cron intervals.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function register_intervals( array $schedules ): array {
		$schedules['cie_nightly'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'CIE Nightly', 'commerce-intelligence-engine' ),
		);
		$schedules['cie_weekly']  = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'CIE Weekly', 'commerce-intelligence-engine' ),
		);

		return $schedules;
	}

	/**
	 * Schedules recurring rebuild event based on settings.
	 *
	 * @return void
	 */
	public static function schedule_rebuild(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_intervals' ) );

		$schedule = self::get_schedule_setting();

		if ( 'manual' === $schedule ) {
			self::unschedule_rebuild();
			return;
		}

		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$recurrence = self::map_schedule_to_recurrence( $schedule );
		wp_schedule_event( time(), $recurrence, self::CRON_HOOK );
	}

	/**
	 * Clears scheduled rebuild event.
	 *
	 * @return void
	 */
	public static function unschedule_rebuild(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Handles scheduled cron event.
	 *
	 * @return void
	 */
	public static function handle_cron(): void {
		$mode = self::get_automated_rebuild_mode();

		if ( self::is_action_scheduler_available() ) {
			self::dispatch_async_rebuild( $mode );
			return;
		}

		self::handle_as_action( array( 'mode' => $mode ) );
	}

	/**
	 * Handles rebuild action execution.
	 *
	 * @param mixed $args Optional action arguments.
	 * @return void
	 */
	public static function handle_as_action( $args = array() ): void {
		$miner = self::build_miner();
		$mode  = 'full';

		if ( is_array( $args ) && isset( $args['mode'] ) && is_scalar( $args['mode'] ) ) {
			$mode = sanitize_key( (string) $args['mode'] );
		} elseif ( is_scalar( $args ) ) {
			$mode = sanitize_key( (string) $args );
		}

		if ( 'incremental' === $mode ) {
			$miner->run_incremental_rebuild();
			return;
		}

		$miner->run_full_rebuild();
	}

	/**
	 * Reschedules when settings schedule value changes.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $old_value   Old value.
	 * @param mixed  $new_value   New value.
	 * @return void
	 */
	public static function maybe_reschedule_on_settings_update( $option_name, $old_value, $new_value ): void {
		if ( 'cie_settings' !== $option_name ) {
			return;
		}

		$old_schedule = self::extract_schedule( $old_value );
		$new_schedule = self::extract_schedule( $new_value );

		if ( $old_schedule === $new_schedule ) {
			return;
		}

		self::unschedule_rebuild();
		self::schedule_rebuild();
	}

	/**
	 * Re-applies schedule after theme switch.
	 *
	 * @return void
	 */
	public static function maybe_reschedule_after_theme_switch(): void {
		self::unschedule_rebuild();
		self::schedule_rebuild();
	}

	/**
	 * Dispatches async rebuild action to Action Scheduler.
	 *
	 * @return void
	 */
	private static function dispatch_async_rebuild( string $mode ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) ) {
			$has_scheduled = as_has_scheduled_action( self::ACTION_HOOK, null, self::ACTION_GROUP );
			if ( false !== $has_scheduled ) {
				return;
			}
		}

		as_enqueue_async_action(
			self::ACTION_HOOK,
			array( 'mode' => in_array( $mode, array( 'full', 'incremental' ), true ) ? $mode : 'full' ),
			self::ACTION_GROUP
		);
	}

	/**
	 * Determines the automated rebuild mode.
	 *
	 * Policy:
	 * - Weekly schedule always runs full rebuilds.
	 * - Nightly schedule runs full every Sunday (site timezone).
	 * - Non-Sunday nightly runs use incremental when baseline/full-history is healthy.
	 *
	 * @return string
	 */
	private static function get_automated_rebuild_mode(): string {
		$schedule = self::get_schedule_setting();
		if ( 'weekly' === $schedule ) {
			return 'full';
		}

		if ( self::is_weekly_full_sync_day() ) {
			return 'full';
		}

		if ( ! self::has_incremental_baseline_hint() ) {
			return 'full';
		}

		if ( ! self::was_last_full_rebuild_successful() ) {
			return 'full';
		}

		return 'incremental';
	}

	/**
	 * Returns true when today is the configured weekly full-sync day.
	 *
	 * Uses site timezone and Sunday (ISO-8601 day 7).
	 *
	 * @return bool
	 */
	private static function is_weekly_full_sync_day(): bool {
		$now_utc = function_exists( 'current_time' )
			? (int) current_time( 'timestamp', true )
			: time();

		return 7 === (int) wp_date( 'N', $now_utc, wp_timezone() );
	}

	/**
	 * Returns true when incremental baseline options exist and look usable.
	 *
	 * @return bool
	 */
	private static function has_incremental_baseline_hint(): bool {
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
	 * Returns true when the latest full rebuild log row is completed.
	 *
	 * @return bool
	 */
	private static function was_last_full_rebuild_successful(): bool {
		global $wpdb;

		self::require_file( 'includes/class-commerce-intelligence-engine-db-migrator.php' );
		if ( ! class_exists( 'CIE_DB_Migrator' ) ) {
			return false;
		}

		$table = CIE_DB_Migrator::get_table_name( 'rebuild_log' );
		$query = $wpdb->prepare(
			"SELECT status FROM {$table} WHERE mode = %s ORDER BY started_at DESC LIMIT 1",
			'full'
		);
		$status = $wpdb->get_var( $query );

		if ( null === $status || '' !== (string) $wpdb->last_error ) {
			return false;
		}

		return 'completed' === sanitize_key( (string) $status );
	}

	/**
	 * Builds miner with required dependencies.
	 *
	 * @return CIE_Miner
	 */
	private static function build_miner(): CIE_Miner {
		self::require_file( 'includes/class-commerce-intelligence-engine-settings.php' );
		self::require_file( 'includes/class-commerce-intelligence-engine-db-migrator.php' );
		self::require_file( 'includes/engine/class-commerce-intelligence-engine-data-extractor.php' );
		self::require_file( 'includes/engine/class-commerce-intelligence-engine-pair-counter.php' );
		self::require_file( 'includes/engine/class-commerce-intelligence-engine-association-calculator.php' );
		self::require_file( 'includes/engine/class-commerce-intelligence-engine-scorer.php' );
		self::require_file( 'includes/engine/class-commerce-intelligence-engine-association-repository.php' );
		self::require_file( 'includes/engine/class-commerce-intelligence-engine-rebuild-log-repository.php' );
		self::require_file( 'includes/engine/class-commerce-intelligence-engine-lock-manager.php' );
		self::require_file( 'includes/engine/class-commerce-intelligence-engine-miner.php' );

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
	 * Returns current schedule setting.
	 *
	 * @return string
	 */
	private static function get_schedule_setting(): string {
		if ( ! class_exists( 'CIE_Settings' ) ) {
			self::require_file( 'includes/class-commerce-intelligence-engine-settings.php' );
		}

		$settings = CIE_Settings::get();
		return self::extract_schedule( $settings );
	}

	/**
	 * Extracts normalized schedule from settings-like payload.
	 *
	 * @param mixed $value Settings payload.
	 * @return string
	 */
	private static function extract_schedule( $value ): string {
		$schedule = 'nightly';

		if ( is_array( $value ) && isset( $value['schedule'] ) && is_scalar( $value['schedule'] ) ) {
			$schedule = sanitize_key( (string) $value['schedule'] );
		}

		if ( in_array( $schedule, array( 'nightly', 'weekly', 'manual' ), true ) ) {
			return $schedule;
		}

		return 'nightly';
	}

	/**
	 * Maps schedule value to WP-Cron recurrence key.
	 *
	 * @param string $schedule Schedule value.
	 * @return string
	 */
	private static function map_schedule_to_recurrence( string $schedule ): string {
		return ( 'weekly' === $schedule ) ? 'cie_weekly' : 'cie_nightly';
	}

	/**
	 * Checks whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	private static function is_action_scheduler_available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Requires a plugin file once.
	 *
	 * @param string $relative_path Relative plugin path.
	 * @return void
	 */
	private static function require_file( string $relative_path ): void {
		$file = CIE_PLUGIN_DIR . ltrim( $relative_path, '/' );
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

if ( ! class_exists( 'CIE_Scheduler', false ) ) {
	class_alias( 'Commerce_Intelligence_Engine_Scheduler', 'CIE_Scheduler' );
}
