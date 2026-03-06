<?php
/**
 * Unit tests for recommendation item filter behavior.
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests cie_recommendation_item filter contract handling.
 */
final class CIE_Test_Recommendation_Item_Filter extends TestCase {

	/**
	 * Recommender under test.
	 *
	 * @var CIE_Recommender
	 */
	private $recommender;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		cie_test_reset_state();
		$this->recommender = new CIE_Recommender( new CIE_Association_Repository(), new CIE_Cache() );
	}

	/**
	 * Clears test filters.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		remove_all_filters( 'cie_recommendation_item' );
	}

	/**
	 * Verifies null filter return drops item.
	 *
	 * @return void
	 */
	public function test_null_filter_return_drops_item(): void {
		add_filter(
			'cie_recommendation_item',
			static function( $item ) {
				unset( $item );
				return null;
			},
			10,
			3
		);

		$result = $this->invoke_apply_item_filter(
			array(
				array(
					'product_id' => 9,
					'score'      => 0.7,
					'source'     => 'mined',
				),
			)
		);

		$this->assertSame( array(), $result );
	}

	/**
	 * Verifies malformed item from filter is dropped.
	 *
	 * @return void
	 */
	public function test_malformed_filter_return_drops_item(): void {
		add_filter(
			'cie_recommendation_item',
			static function() {
				return array(
					'product_id' => 9,
					'score'      => 0.7,
					// Missing required "source".
				);
			},
			10,
			3
		);

		$result = $this->invoke_apply_item_filter(
			array(
				array(
					'product_id' => 9,
					'score'      => 0.7,
					'source'     => 'mined',
				),
			)
		);

		$this->assertSame( array(), $result );
	}

	/**
	 * Verifies valid item passes and is normalized.
	 *
	 * @return void
	 */
	public function test_valid_filter_return_passes_and_normalizes_fields(): void {
		add_filter(
			'cie_recommendation_item',
			static function() {
				return array(
					'product_id' => '15',
					'score'      => '0.75',
					'confidence' => '0.20',
					'lift'       => '1.40',
					'source'     => 'mined',
				);
			},
			10,
			3
		);

		$result = $this->invoke_apply_item_filter(
			array(
				array(
					'product_id' => 9,
					'score'      => 0.7,
					'source'     => 'mined',
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 15, $result[0]['product_id'] );
		$this->assertEqualsWithDelta( 0.75, $result[0]['score'], 0.000001 );
		$this->assertEqualsWithDelta( 0.20, $result[0]['confidence'], 0.000001 );
		$this->assertEqualsWithDelta( 1.40, $result[0]['lift'], 0.000001 );
		$this->assertSame( 'mined', $result[0]['source'] );
		$this->assertSame( '', $result[0]['reason'] );
		$this->assertFalse( $result[0]['is_fallback'] );
	}

	/**
	 * Invokes private apply_item_filter() via reflection.
	 *
	 * @param array $items Input items.
	 * @return array
	 */
	private function invoke_apply_item_filter( array $items ): array {
		$method = new ReflectionMethod( CIE_Recommender::class, 'apply_item_filter' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->recommender, $items, 123, 'product' );
		return is_array( $result ) ? $result : array();
	}
}

