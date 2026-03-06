<?php

/**
 * Frontend renderer for recommendations.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders recommendation blocks.
 */
class CIE_Renderer {

	/**
	 * Recommender service.
	 *
	 * @var CIE_Recommender
	 */
	private $recommender;

	/**
	 * Constructor.
	 *
	 * @param CIE_Recommender|null $recommender Recommender instance.
	 */
	public function __construct( CIE_Recommender $recommender = null ) {
		if ( null === $recommender ) {
			$recommender = new CIE_Recommender( new CIE_Association_Repository(), new CIE_Cache() );
		}

		$this->recommender = $recommender;
	}

	/**
	 * Renders recommendations for a product/context.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $context    Rendering context.
	 * @param int    $limit      Display limit.
	 * @return void
	 */
	public function render( int $product_id, string $context = 'product', int $limit = 0 ): void {
		if ( ! CIE_Settings::is_enabled() ) {
			return;
		}

		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return;
		}

		$settings = CIE_Settings::get();
		if ( $limit <= 0 ) {
			$limit = isset( $settings['max_recommendations'] ) ? max( 1, absint( $settings['max_recommendations'] ) ) : 4;
		}

		$result = $this->recommender->get_recommendations( $product_id, $context, $limit );
		if ( ! is_array( $result ) || empty( $result['items'] ) || ! is_array( $result['items'] ) ) {
			return;
		}

		wc_get_template(
			'recommendations.php',
			array(
				'result'   => $result,
				'settings' => $settings,
			),
			'commerce-intelligence-engine/',
			CIE_PLUGIN_DIR . 'public/templates/'
		);
	}

	/**
	 * Registers renderer hooks based on display settings.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		$settings = CIE_Settings::get();
		$display  = isset( $settings['display'] ) && is_array( $settings['display'] ) ? $settings['display'] : array();

		if ( ! empty( $display['show_on_product'] ) ) {
			add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'render_product_hook' ), 15 );
		}

		if ( ! empty( $display['show_on_cart'] ) ) {
			add_action( self::get_cart_hook(), array( __CLASS__, 'render_cart_hook' ), self::get_cart_priority() );
		}

		if ( ! empty( $display['show_on_checkout'] ) ) {
			add_action( self::get_checkout_hook(), array( __CLASS__, 'render_checkout_hook' ), self::get_checkout_priority() );
		}
	}

	/**
	 * Product hook callback.
	 *
	 * @return void
	 */
	public static function render_product_hook(): void {
		global $product;

		$product_id = 0;

		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$product_id = absint( $product->get_id() );
		}

		if ( $product_id <= 0 ) {
			$product_id = absint( get_the_ID() );
		}

		if ( $product_id <= 0 ) {
			return;
		}

		$renderer = new self();
		$renderer->render( $product_id, 'product' );
	}

	/**
	 * Cart hook callback.
	 *
	 * @return void
	 */
	public static function render_cart_hook(): void {
		$product_id = self::resolve_first_cart_product_id();
		if ( $product_id <= 0 ) {
			return;
		}

		$renderer = new self();
		$renderer->render( $product_id, 'cart' );
	}

	/**
	 * Checkout hook callback.
	 *
	 * @return void
	 */
	public static function render_checkout_hook(): void {
		$product_id = self::resolve_first_cart_product_id();
		if ( $product_id <= 0 ) {
			return;
		}

		$renderer = new self();
		$renderer->render( $product_id, 'checkout' );
	}

	/**
	 * Resolves a product ID from first cart line item.
	 *
	 * @return int
	 */
	private static function resolve_first_cart_product_id(): int {
		if ( ! function_exists( 'WC' ) ) {
			return 0;
		}

		$wc = WC();
		if ( ! $wc || ! isset( $wc->cart ) || ! is_object( $wc->cart ) ) {
			return 0;
		}

		$cart_items = $wc->cart->get_cart();
		if ( ! is_array( $cart_items ) || empty( $cart_items ) ) {
			return 0;
		}

		$first_item = reset( $cart_items );
		if ( ! is_array( $first_item ) ) {
			return 0;
		}

		$variation_id = isset( $first_item['variation_id'] ) ? absint( $first_item['variation_id'] ) : 0;
		$product_id   = isset( $first_item['product_id'] ) ? absint( $first_item['product_id'] ) : 0;

		if ( $variation_id > 0 ) {
			return $variation_id;
		}

		return $product_id;
	}

	/**
	 * Returns cart hook for rendering recommendations.
	 *
	 * @return string
	 */
	private static function get_cart_hook(): string {
		$hook = apply_filters( 'cie_cart_render_hook', 'woocommerce_after_cart' );

		if ( ! is_string( $hook ) || '' === $hook ) {
			return 'woocommerce_after_cart';
		}

		// Avoid sidebar/floated collaterals area that breaks layout on themes like Shoptimizer.
		if ( 'woocommerce_cart_collaterals' === $hook ) {
			return 'woocommerce_after_cart';
		}

		return $hook;
	}

	/**
	 * Returns checkout hook for rendering recommendations.
	 *
	 * @return string
	 */
	private static function get_checkout_hook(): string {
		$hook = apply_filters( 'cie_checkout_render_hook', 'woocommerce_after_checkout_form' );

		if ( ! is_string( $hook ) || '' === $hook ) {
			return 'woocommerce_after_checkout_form';
		}

		return $hook;
	}

	/**
	 * Returns cart hook priority for rendering recommendations.
	 *
	 * @return int
	 */
	private static function get_cart_priority(): int {
		$priority = apply_filters( 'cie_cart_render_priority', 15 );

		return is_numeric( $priority ) ? (int) $priority : 15;
	}

	/**
	 * Returns checkout hook priority for rendering recommendations.
	 *
	 * @return int
	 */
	private static function get_checkout_priority(): int {
		$priority = apply_filters( 'cie_checkout_render_priority', 15 );

		return is_numeric( $priority ) ? (int) $priority : 15;
	}
}
