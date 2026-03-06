<?php
/**
 * Unit tests for CIE_Association_Calculator.
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests directional metric calculations and threshold filtering.
 */
final class CIE_Test_Association_Calculator extends TestCase {

	/**
	 * Calculator under test.
	 *
	 * @var CIE_Association_Calculator
	 */
	private $calculator;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calculator = new CIE_Association_Calculator();
	}

	/**
	 * Verifies known support/confidence/lift values from plan examples.
	 *
	 * @return void
	 */
	public function test_calculate_metrics_known_values_from_plan_examples(): void {
		$rows = $this->calculator->calculate_metrics(
			array( '1:2' => 5 ),
			array(
				1 => 10,
				2 => 8,
			),
			100,
			array( '1:2' => strtotime( '2025-01-01 12:00:00' ) )
		);

		$this->assertCount( 2, $rows );

		$indexed = array();
		foreach ( $rows as $row ) {
			$key            = $row['product_id'] . ':' . $row['associated_product_id'];
			$indexed[ $key ] = $row;
		}

		$this->assertArrayHasKey( '1:2', $indexed );
		$this->assertArrayHasKey( '2:1', $indexed );

		$this->assertEqualsWithDelta( 0.05, (float) $indexed['1:2']['support'], 0.000001 );
		$this->assertEqualsWithDelta( 0.50, (float) $indexed['1:2']['confidence'], 0.000001 );
		$this->assertEqualsWithDelta( 6.25, (float) $indexed['1:2']['lift'], 0.000001 );

		$this->assertEqualsWithDelta( 0.05, (float) $indexed['2:1']['support'], 0.000001 );
		$this->assertEqualsWithDelta( 0.625, (float) $indexed['2:1']['confidence'], 0.000001 );
		$this->assertEqualsWithDelta( 6.25, (float) $indexed['2:1']['lift'], 0.000001 );
		$this->assertSame( '2025-01-01 12:00:00', $indexed['1:2']['last_seen_at'] );
		$this->assertSame( '2025-01-01 12:00:00', $indexed['2:1']['last_seen_at'] );
	}

	/**
	 * Verifies zero-total-order guard.
	 *
	 * @return void
	 */
	public function test_calculate_metrics_returns_empty_when_total_orders_is_zero(): void {
		$rows = $this->calculator->calculate_metrics(
			array( '1:2' => 5 ),
			array(
				1 => 10,
				2 => 8,
			),
			0
		);

		$this->assertSame( array(), $rows );
	}

	/**
	 * Verifies missing directional counts are skipped safely.
	 *
	 * @return void
	 */
	public function test_calculate_metrics_skips_pairs_with_missing_product_counts(): void {
		$rows = $this->calculator->calculate_metrics(
			array( '1:2' => 5 ),
			array(
				1 => 10,
			),
			100
		);

		$this->assertSame( array(), $rows );
	}

	/**
	 * Verifies threshold filtering keeps only rows meeting all constraints.
	 *
	 * @return void
	 */
	public function test_filter_by_thresholds_filters_expected_rows(): void {
		$rows = array(
			array(
				'product_id'            => 1,
				'associated_product_id' => 2,
				'co_occurrence_count'   => 5,
				'support'               => 0.05,
				'confidence'            => 0.5,
				'lift'                  => 6.25,
			),
			array(
				'product_id'            => 2,
				'associated_product_id' => 1,
				'co_occurrence_count'   => 5,
				'support'               => 0.05,
				'confidence'            => 0.625,
				'lift'                  => 5.0,
			),
		);

		$filtered = $this->calculator->filter_by_thresholds(
			$rows,
			array(
				'min_co_occurrence' => 5,
				'min_support'       => 0.05,
				'min_confidence'    => 0.5,
				'min_lift'          => 6.0,
			)
		);

		$this->assertCount( 1, $filtered );
		$this->assertSame( 1, $filtered[0]['product_id'] );
		$this->assertSame( 2, $filtered[0]['associated_product_id'] );
	}
}
