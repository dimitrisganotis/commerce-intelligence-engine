<?php
/**
 * Unit tests for CIE_Scorer.
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests scoring boundaries and normalization behavior.
 */
final class CIE_Test_Scorer extends TestCase {

	/**
	 * Scorer under test.
	 *
	 * @var CIE_Scorer
	 */
	private $scorer;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->scorer = new CIE_Scorer();
	}

	/**
	 * Verifies weight normalization against expected ratio.
	 *
	 * @return void
	 */
	public function test_normalize_weights_scales_values_to_one(): void {
		$weights = CIE_Scorer::normalize_weights(
			array(
				'confidence' => 4,
				'lift'       => 3,
				'margin'     => 2,
				'stock'      => 1,
				'recency'    => 0,
			)
		);

		$this->assertEqualsWithDelta( 0.4, $weights['confidence'], 0.000001 );
		$this->assertEqualsWithDelta( 0.3, $weights['lift'], 0.000001 );
		$this->assertEqualsWithDelta( 0.2, $weights['margin'], 0.000001 );
		$this->assertEqualsWithDelta( 0.1, $weights['stock'], 0.000001 );
		$this->assertEqualsWithDelta( 0.0, $weights['recency'], 0.000001 );
	}

	/**
	 * Verifies equal fallback weights when sum is zero.
	 *
	 * @return void
	 */
	public function test_normalize_weights_uses_equal_fallback_when_sum_is_zero(): void {
		$weights = CIE_Scorer::normalize_weights( array() );

		$this->assertEqualsWithDelta( 0.2, $weights['confidence'], 0.000001 );
		$this->assertEqualsWithDelta( 0.2, $weights['lift'], 0.000001 );
		$this->assertEqualsWithDelta( 0.2, $weights['margin'], 0.000001 );
		$this->assertEqualsWithDelta( 0.2, $weights['stock'], 0.000001 );
		$this->assertEqualsWithDelta( 0.2, $weights['recency'], 0.000001 );
	}

	/**
	 * Verifies margin boost boundaries and neutral fallback.
	 *
	 * @return void
	 */
	public function test_score_batch_margin_boost_boundaries(): void {
		$rows = array(
			$this->build_row( 101 ),
			$this->build_row( 102 ),
			$this->build_row( 103 ),
			$this->build_row( 104 ),
		);

		$meta = array(
			101 => array(
				'_price' => 100,
				// Missing cost => neutral fallback.
			),
			102 => array(
				'_price'       => 100,
				'_wc_cog_cost' => 20,
			),
			103 => array(
				'_price'       => 100,
				'_wc_cog_cost' => 150,
			),
			104 => array(
				'_price'       => 100,
				'_wc_cog_cost' => 0,
			),
		);

		$scored = $this->scorer->score_batch( $rows, $meta, array( 'margin' => 1 ), 0.01 );

		$this->assertEqualsWithDelta( 0.5, $scored[0]['score'], 0.000001 );
		$this->assertEqualsWithDelta( 0.8, $scored[1]['score'], 0.000001 );
		$this->assertEqualsWithDelta( 0.0, $scored[2]['score'], 0.000001 );
		$this->assertEqualsWithDelta( 1.0, $scored[3]['score'], 0.000001 );
	}

	/**
	 * Verifies stock boost boundaries and unmanaged fallback.
	 *
	 * @return void
	 */
	public function test_score_batch_stock_boost_boundaries(): void {
		$rows = array(
			$this->build_row( 201 ),
			$this->build_row( 202 ),
			$this->build_row( 203 ),
			$this->build_row( 204 ),
			$this->build_row( 205 ),
		);

		$meta = array(
			201 => array( '_manage_stock' => 'yes', '_stock' => 51 ),
			202 => array( '_manage_stock' => 'yes', '_stock' => 11 ),
			203 => array( '_manage_stock' => 'yes', '_stock' => 4 ),
			204 => array( '_manage_stock' => 'yes', '_stock' => 3 ),
			205 => array( '_manage_stock' => 'no', '_stock' => 999 ),
		);

		$scored = $this->scorer->score_batch( $rows, $meta, array( 'stock' => 1 ), 0.01 );

		$this->assertEqualsWithDelta( 1.0, $scored[0]['score'], 0.000001 );
		$this->assertEqualsWithDelta( 0.7, $scored[1]['score'], 0.000001 );
		$this->assertEqualsWithDelta( 0.4, $scored[2]['score'], 0.000001 );
		$this->assertEqualsWithDelta( 0.1, $scored[3]['score'], 0.000001 );
		$this->assertEqualsWithDelta( 0.5, $scored[4]['score'], 0.000001 );
	}

	/**
	 * Verifies recency exponential decay and missing-date fallback.
	 *
	 * @return void
	 */
	public function test_score_batch_recency_decay_and_fallback(): void {
		$rows = array(
			$this->build_row( 301, '' ),
			$this->build_row( 302, date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
		);

		$scored = $this->scorer->score_batch( $rows, array(), array( 'recency' => 1 ), 0.01 );

		$this->assertEqualsWithDelta( 0.5, $scored[0]['score'], 0.000001 );
		$this->assertEqualsWithDelta( exp( -0.01 ), $scored[1]['score'], 0.02 );
	}

	/**
	 * Verifies neutral fallbacks are used when metadata is missing.
	 *
	 * @return void
	 */
	public function test_score_batch_uses_neutral_fallbacks_when_meta_missing(): void {
		$rows = array(
			$this->build_row( 401 ),
		);

		$scored = $this->scorer->score_batch(
			$rows,
			array(),
			array(
				'margin'  => 1,
				'stock'   => 1,
				'recency' => 1,
			),
			0.01
		);

		$this->assertCount( 1, $scored );
		$this->assertEqualsWithDelta( 0.5, $scored[0]['score'], 0.000001 );
	}

	/**
	 * Builds a minimal association row.
	 *
	 * @param int         $associated_id Associated product ID.
	 * @param string|null $last_seen_at  Last seen datetime.
	 * @return array
	 */
	private function build_row( int $associated_id, ?string $last_seen_at = null ): array {
		return array(
			'product_id'            => 1,
			'associated_product_id' => $associated_id,
			'confidence'            => 0.2,
			'lift'                  => 2.0,
			'last_seen_at'          => $last_seen_at,
		);
	}
}

