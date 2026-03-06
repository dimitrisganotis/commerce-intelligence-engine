<?php
/**
 * Unit tests for HPOS compatibility declaration.
 */

namespace Automattic\WooCommerce\Utilities {
	if ( ! class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil', false ) ) {
		/**
		 * FeaturesUtil test double used when WooCommerce is unavailable.
		 */
		class FeaturesUtil {
			/**
			 * Recorded declare_compatibility calls.
			 *
			 * @var array
			 */
			public static $calls = array();

			/**
			 * Records compatibility declarations.
			 *
			 * @param string $feature_id Feature ID.
			 * @param string $plugin_file Plugin file path.
			 * @param bool   $compatible Compatibility state.
			 * @return void
			 */
			public static function declare_compatibility( string $feature_id, string $plugin_file, bool $compatible ): void {
				self::$calls[] = array( $feature_id, $plugin_file, $compatible );
			}
		}
	}
}

namespace {
	use PHPUnit\Framework\TestCase;

	require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine.php';

	/**
	 * Tests HPOS declaration behavior.
	 */
	final class CIE_Test_HPOS_Compatibility extends TestCase {

		/**
		 * Test setup.
		 *
		 * @return void
		 */
		protected function setUp(): void {
			if ( property_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil', 'calls' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::$calls = array();
			}
		}

		/**
		 * Verifies CIE declares custom order tables compatibility once.
		 *
		 * @return void
		 */
		public function test_declare_woocommerce_compatibility_is_idempotent(): void {
			$engine = new Commerce_Intelligence_Engine();
			$engine->declare_woocommerce_compatibility();
			$engine->declare_woocommerce_compatibility();

			$reflection = new ReflectionClass( $engine );
			$property   = $reflection->getProperty( 'hpos_compatibility_declared' );
			$property->setAccessible( true );

			$this->assertTrue( (bool) $property->getValue( $engine ) );

			if ( property_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil', 'calls' ) ) {
				$calls = \Automattic\WooCommerce\Utilities\FeaturesUtil::$calls;

				$this->assertCount( 1, $calls );
				$this->assertSame( 'custom_order_tables', $calls[0][0] );
				$this->assertTrue( (bool) $calls[0][2] );
				$this->assertStringEndsWith( '/commerce-intelligence-engine.php', (string) $calls[0][1] );
			}
		}
	}
}
