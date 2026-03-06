<?php
/**
 * Unit tests for CIE_Lock_Manager.
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests token lock lifecycle and stale detection.
 */
final class CIE_Test_Lock_Manager extends TestCase {

	/**
	 * Lock manager under test.
	 *
	 * @var CIE_Lock_Manager
	 */
	private $manager;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		cie_test_reset_state();
		$this->manager = new CIE_Lock_Manager();
	}

	/**
	 * Verifies acquire/heartbeat/release flow with token ownership.
	 *
	 * @return void
	 */
	public function test_acquire_heartbeat_and_release_with_token_ownership(): void {
		$token = $this->manager->acquire( 'rebuild', 1800 );

		$this->assertIsString( $token );
		$this->assertNotSame( '', $token );
		$this->assertFalse( $this->manager->acquire( 'rebuild', 1800 ) );
		$this->assertTrue( $this->manager->heartbeat( 'rebuild', $token ) );
		$this->assertFalse( $this->manager->release( 'rebuild', 'wrong-token' ) );
		$this->assertTrue( $this->manager->release( 'rebuild', $token ) );
	}

	/**
	 * Verifies stale lock detection for old heartbeat payload.
	 *
	 * @return void
	 */
	public function test_is_stale_detects_old_heartbeat_payload(): void {
		update_option(
			'cie_lock_test',
			wp_json_encode(
				array(
					'token'        => 'token-1',
					'heartbeat_at' => time() - ( CIE_Lock_Manager::STALE_THRESHOLD + 1 ),
				)
			)
		);

		$this->assertTrue( $this->manager->is_stale( 'test' ) );

		update_option(
			'cie_lock_test',
			wp_json_encode(
				array(
					'token'        => 'token-2',
					'heartbeat_at' => time(),
				)
			)
		);

		$this->assertFalse( $this->manager->is_stale( 'test' ) );
	}
}

