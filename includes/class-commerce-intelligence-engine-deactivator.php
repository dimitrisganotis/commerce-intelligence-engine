<?php

/**
 * Fired during plugin deactivation.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deactivator.
 */
class Commerce_Intelligence_Engine_Deactivator {

	/**
	 * Runs deactivation tasks.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'cie_scheduled_rebuild' );
		wp_clear_scheduled_hook( 'cie_run_rebuild' );
		wp_clear_scheduled_hook( 'cie_run_rebuild', array( 'mode' => 'incremental' ) );
		wp_clear_scheduled_hook( 'cie_run_rebuild', array( 'mode' => 'full' ) );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'cie_run_rebuild', array(), 'commerce-intelligence-engine' );
			as_unschedule_all_actions( 'cie_run_rebuild', array( 'mode' => 'incremental' ), 'commerce-intelligence-engine' );
			as_unschedule_all_actions( 'cie_run_rebuild', array( 'mode' => 'full' ), 'commerce-intelligence-engine' );
			as_unschedule_all_actions( '', array(), 'commerce-intelligence-engine' );
		}
	}
}
