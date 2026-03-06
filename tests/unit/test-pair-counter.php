<?php
/**
 * Unit tests for CIE_Pair_Counter.
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests in-memory pair counting behavior.
 */
final class CIE_Test_Pair_Counter extends TestCase {

	/**
	 * Pair counter under test.
	 *
	 * @var CIE_Pair_Counter
	 */
	private $counter;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->counter = new CIE_Pair_Counter();
	}

	/**
	 * Verifies canonical pair counts for known baskets.
	 *
	 * @return void
	 */
	public function test_count_pairs_known_baskets(): void {
		$baskets = array(
			array( 1, 2, 3 ),
			array( 2, 3 ),
			array( 3, 3, 2, 1 ),
		);

		$counts = $this->counter->count_pairs( $baskets );

		$this->assertSame(
			array(
				'1:2' => 2,
				'1:3' => 2,
				'2:3' => 3,
			),
			$counts
		);
	}

	/**
	 * Verifies empty baskets produce no pairs.
	 *
	 * @return void
	 */
	public function test_count_pairs_empty_baskets_returns_empty_array(): void {
		$counts = $this->counter->count_pairs( array() );

		$this->assertSame( array(), $counts );
	}

	/**
	 * Verifies single-item baskets are ignored.
	 *
	 * @return void
	 */
	public function test_count_pairs_single_item_basket_returns_empty_array(): void {
		$counts = $this->counter->count_pairs(
			array(
				array( 99 ),
			)
		);

		$this->assertSame( array(), $counts );
	}
}

