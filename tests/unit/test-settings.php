<?php
/**
 * Unit tests for CIE_Settings.
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests defaults and sanitization/update behavior.
 */
final class CIE_Test_Settings extends TestCase {

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		cie_test_reset_state();
	}

	/**
	 * Verifies default schema keys are present.
	 *
	 * @return void
	 */
	public function test_get_defaults_contains_required_keys(): void {
		$defaults = CIE_Settings::get_defaults();

		$this->assertArrayHasKey( 'enabled', $defaults );
		$this->assertArrayHasKey( 'lookback_days', $defaults );
		$this->assertArrayHasKey( 'included_statuses', $defaults );
		$this->assertArrayHasKey( 'weights', $defaults );
		$this->assertArrayHasKey( 'display', $defaults );
		$this->assertArrayHasKey( 'rest_api', $defaults );
		$this->assertArrayHasKey( 'uninstall_delete_data', $defaults );

		$this->assertFalse( $defaults['uninstall_delete_data'] );
	}

	/**
	 * Verifies update sanitization, weight normalization, and unknown key rejection.
	 *
	 * @return void
	 */
	public function test_update_sanitizes_values_normalizes_weights_and_rejects_unknown_keys(): void {
		$updated = CIE_Settings::update(
			array(
				'enabled'           => 0,
				'lookback_days'     => '30',
				'included_statuses' => array( 'wc-completed', '', 'WC-PROCESSING' ),
				'weights'           => array(
					'confidence' => 2,
					'lift'       => 1,
					'margin'     => 1,
					'stock'      => 1,
					'recency'    => 1,
				),
				'variation_mode'    => 'invalid-mode',
				'display'           => array(
					'title'       => '<b>Title Test</b>',
					'show_reason' => 0,
				),
				'rest_api'          => array(
					'enabled'      => 1,
					'access_mode'  => 'private-mode',
					'cache_max_age'=> 120,
				),
				'uninstall_delete_data' => 1,
				'unknown_key'       => 'should-not-persist',
			)
		);

		$this->assertTrue( $updated );

		$settings = CIE_Settings::get();

		$this->assertFalse( $settings['enabled'] );
		$this->assertSame( 30, $settings['lookback_days'] );
		$this->assertSame( array( 'wc-completed', 'wc-processing' ), $settings['included_statuses'] );
		$this->assertSame( 'parent', $settings['variation_mode'] );
		$this->assertSame( 'Title Test', $settings['display']['title'] );
		$this->assertFalse( $settings['display']['show_reason'] );
		$this->assertTrue( $settings['rest_api']['enabled'] );
		$this->assertSame( 'public', $settings['rest_api']['access_mode'] );
		$this->assertSame( 120, $settings['rest_api']['cache_max_age'] );
		$this->assertTrue( $settings['uninstall_delete_data'] );
		$this->assertArrayNotHasKey( 'unknown_key', $settings );
		$this->assertEqualsWithDelta( 1.0, array_sum( $settings['weights'] ), 0.000001 );
		$this->assertFalse( CIE_Settings::is_enabled() );
	}

	/**
	 * Verifies unknown-only updates do not mutate schema.
	 *
	 * @return void
	 */
	public function test_update_unknown_root_keys_are_ignored(): void {
		CIE_Settings::update(
			array(
				'unknown_key' => 'value',
			)
		);

		$settings = CIE_Settings::get();
		$this->assertArrayNotHasKey( 'unknown_key', $settings );
	}
}

