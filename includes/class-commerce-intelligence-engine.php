<?php

/**
 * Core runtime coordinator.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates runtime hooks for the plugin.
 */
class Commerce_Intelligence_Engine {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	protected $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Settings controller instance.
	 *
	 * @var CIE_Admin_Settings|null
	 */
	protected $admin_settings;

	/**
	 * Tracks whether HPOS compatibility has been declared.
	 *
	 * @var bool
	 */
	protected $hpos_compatibility_declared;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin_name = 'commerce-intelligence-engine';
		$this->version     = defined( 'CIE_VERSION' ) ? CIE_VERSION : '1.0.0';
		$this->admin_settings               = null;
		$this->hpos_compatibility_declared  = false;
	}

	/**
	 * Registers runtime hooks.
	 *
	 * @return void
	 */
	public function run(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );
		if ( function_exists( 'did_action' ) && did_action( 'before_woocommerce_init' ) > 0 ) {
			$this->declare_woocommerce_compatibility();
		}

		add_action( 'plugins_loaded', array( $this, 'maybe_migrate' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'register_scheduler' ), 20 );
		add_action( 'plugins_loaded', array( $this, 'register_feature_modules' ), 25 );
		add_action( 'admin_notices', array( $this, 'maybe_render_wc_missing_notice' ) );
		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'init', array( $this, 'register_admin_ajax_handlers' ) );
	}

	/**
	 * Declares HPOS compatibility when WooCommerce utilities are available.
	 *
	 * @return void
	 */
	public function declare_woocommerce_compatibility(): void {
		if ( $this->hpos_compatibility_declared ) {
			return;
		}

		$features_util_class = 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
		if ( ! class_exists( $features_util_class ) || ! method_exists( $features_util_class, 'declare_compatibility' ) ) {
			return;
		}

		$plugin_file = defined( 'CIE_PLUGIN_DIR' )
			? CIE_PLUGIN_DIR . 'commerce-intelligence-engine.php'
			: dirname( __DIR__ ) . '/commerce-intelligence-engine.php';

		$features_util_class::declare_compatibility( 'custom_order_tables', $plugin_file, true );
		$this->hpos_compatibility_declared = true;
	}

	/**
	 * Runs DB migrations when needed.
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		CIE_DB_Migrator::maybe_migrate();
	}

	/**
	 * Registers rebuild scheduler hooks.
	 *
	 * @return void
	 */
	public function register_scheduler(): void {
		require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-scheduler.php';
		CIE_Scheduler::register_hooks();
	}

	/**
	 * Registers feature modules added in later phases.
	 *
	 * @return void
	 */
	public function register_feature_modules(): void {
		require_once CIE_PLUGIN_DIR . 'includes/api/class-commerce-intelligence-engine-rest-controller.php';
		require_once CIE_PLUGIN_DIR . 'includes/cli/class-commerce-intelligence-engine-cli.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-cache.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-association-repository.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-recommender.php';
		require_once CIE_PLUGIN_DIR . 'public/class-commerce-intelligence-engine-renderer.php';

		if ( class_exists( 'CIE_Renderer' ) ) {
			CIE_Renderer::register_hooks();
		}

		if ( is_admin() ) {
			require_once CIE_PLUGIN_DIR . 'admin/class-commerce-intelligence-engine-admin-settings.php';
			require_once CIE_PLUGIN_DIR . 'admin/class-commerce-intelligence-engine-dashboard-widget.php';
			require_once CIE_PLUGIN_DIR . 'admin/class-commerce-intelligence-engine-product-metabox.php';

			if ( null === $this->admin_settings && class_exists( 'CIE_Admin_Settings' ) ) {
				$this->admin_settings = new CIE_Admin_Settings();
			}
		}
	}

	/**
	 * Renders and clears WooCommerce missing notice.
	 *
	 * @return void
	 */
	public function maybe_render_wc_missing_notice(): void {
		$notice = get_transient( 'cie_wc_missing_notice' );

		if ( false === $notice ) {
			return;
		}

		delete_transient( 'cie_wc_missing_notice' );

		if ( ! is_scalar( $notice ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html( (string) $notice ) . '</p></div>';
	}

	/**
	 * Registers plugin shortcodes.
	 *
	 * @return void
	 */
	public function register_shortcodes(): void {
		add_shortcode( 'cie_recommendations', array( $this, 'render_recommendations_shortcode' ) );
	}

	/**
	 * Registers admin AJAX handlers.
	 *
	 * @return void
	 */
	public function register_admin_ajax_handlers(): void {
		if ( ! is_admin() ) {
			return;
		}

		require_once CIE_PLUGIN_DIR . 'admin/class-commerce-intelligence-engine-ajax-handlers.php';

		$ajax_handlers = new CIE_Ajax_Handlers();
		$ajax_handlers->register_hooks();
	}

	/**
	 * Renders [cie_recommendations] shortcode output.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_recommendations_shortcode( $atts ): string {
		if ( ! CIE_Settings::is_enabled() ) {
			return '';
		}

		$defaults = array(
			'product_id' => 0,
			'limit'      => 0,
			'context'    => 'product',
		);
		$atts     = shortcode_atts( $defaults, (array) $atts, 'cie_recommendations' );

		$product_id = absint( $atts['product_id'] );
		if ( $product_id <= 0 ) {
			$product_id = absint( get_the_ID() );
		}

		if ( $product_id <= 0 ) {
			return '';
		}

		$limit   = absint( $atts['limit'] );
		$context = sanitize_key( (string) $atts['context'] );
		if ( ! in_array( $context, array( 'product', 'cart', 'checkout' ), true ) ) {
			$context = 'product';
		}

		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-cache.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-association-repository.php';
		require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-recommender.php';
		require_once CIE_PLUGIN_DIR . 'public/class-commerce-intelligence-engine-renderer.php';

		ob_start();

		$renderer = new CIE_Renderer();
		$renderer->render( $product_id, $context, $limit );

		return (string) ob_get_clean();
	}

	/**
	 * Returns plugin slug.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return $this->plugin_name;
	}

	/**
	 * Returns plugin version.
	 *
	 * @return string
	 */
	public function get_version(): string {
		return $this->version;
	}
}
