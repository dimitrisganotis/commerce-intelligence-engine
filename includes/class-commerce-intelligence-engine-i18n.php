<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://www.dganotis.dev
 * @since      1.0.0
 *
 * @package    Commerce_Intelligence_Engine
 * @subpackage Commerce_Intelligence_Engine/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Commerce_Intelligence_Engine
 * @subpackage Commerce_Intelligence_Engine/includes
 * @author     Dimitris Ganotis <hello@dganotis.dev>
 */
class Commerce_Intelligence_Engine_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'commerce-intelligence-engine',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
