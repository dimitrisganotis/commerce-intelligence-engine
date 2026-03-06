<?php
/**
 * Unit tests for miner checkpoint state compatibility.
 */

use PHPUnit\Framework\TestCase;

require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-data-extractor.php';
require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-rebuild-log-repository.php';
require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-miner.php';

/**
 * Tests checkpoint save/load behavior in CIE_Miner.
 */
final class CIE_Test_Miner_Checkpoint_State extends TestCase {

	/**
	 * Miner instance.
	 *
	 * @var CIE_Miner
	 */
	private $miner;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		cie_test_reset_state();

		$this->miner = new CIE_Miner(
			new CIE_Data_Extractor(),
			new CIE_Pair_Counter(),
			new CIE_Association_Calculator(),
			new CIE_Scorer(),
			new CIE_Association_Repository(),
			new CIE_Rebuild_Log_Repository(),
			new CIE_Lock_Manager()
		);

		update_option( 'cie_db_version', '1.0.0' );
		$this->set_current_run_id( 'run-1' );
	}

	/**
	 * Persists checkpoint metadata fields required for compatibility.
	 *
	 * @return void
	 */
	public function test_save_rebuild_state_adds_schema_and_db_version(): void {
		$this->miner->save_rebuild_state(
			array(
				'run_id'    => 'run-1',
				'mode'      => 'full',
				'updated_at' => time(),
			)
		);

		$state = get_option( CIE_Miner::OPTION_REBUILD_STATE, array() );
		$this->assertIsArray( $state );
		$this->assertSame( 1, (int) $state['schema_version'] );
		$this->assertSame( '1.0.0', (string) $state['db_version'] );
	}

	/**
	 * Rejects incompatible checkpoint state and clears it.
	 *
	 * @return void
	 */
	public function test_get_rebuild_state_rejects_incompatible_db_version(): void {
		update_option(
			CIE_Miner::OPTION_REBUILD_STATE,
			array(
				'run_id'          => 'run-1',
				'mode'            => 'full',
				'updated_at'      => time(),
				'schema_version'  => 1,
				'db_version'      => '0.9.0',
			)
		);

		$state = $this->miner->get_rebuild_state();
		$this->assertNull( $state );
		$this->assertFalse( get_option( CIE_Miner::OPTION_REBUILD_STATE, false ) );
	}

	/**
	 * Sets the miner run_id private property.
	 *
	 * @param string $run_id Run ID.
	 * @return void
	 */
	private function set_current_run_id( string $run_id ): void {
		$reflection = new ReflectionClass( $this->miner );
		$property   = $reflection->getProperty( 'current_run_id' );
		$property->setAccessible( true );
		$property->setValue( $this->miner, $run_id );
	}
}
