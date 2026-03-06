<?php
/**
 * Admin settings page controller.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles CIE settings page registration, rendering, and form submissions.
 */
class CIE_Admin_Settings {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'cie-settings';

	/**
	 * Notice query arg key.
	 *
	 * @var string
	 */
	const NOTICE_KEY = 'cie_settings_notice';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_form_submission' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueues admin assets for the settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$script_handle = 'cie-admin-settings';
		$script_src    = CIE_PLUGIN_URL . 'admin/js/commerce-intelligence-engine-admin.js';

		wp_enqueue_script(
			$script_handle,
			$script_src,
			array( 'jquery' ),
			defined( 'CIE_VERSION' ) ? CIE_VERSION : '1.0.0',
			true
		);

		wp_localize_script(
			$script_handle,
			'CIEAdminSettings',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'statusInterval' => 3000,
				'rebuildModeDefault' => 'auto',
				'algorithmPresets' => array(
					'coverage'  => array(
						'min_co_occurrence' => 1,
						'min_support'       => 0.0001,
						'min_confidence'    => 0.003,
						'min_lift'          => 0.2,
						'weights'           => array(
							'confidence' => 0.50,
							'lift'       => 0.20,
							'margin'     => 0.10,
							'stock'      => 0.10,
							'recency'    => 0.10,
						),
						'decay_rate'        => 0.01,
						'query_headroom_mult' => 6,
					),
					'balanced'  => array(
						'min_co_occurrence' => 2,
						'min_support'       => 0.0005,
						'min_confidence'    => 0.01,
						'min_lift'          => 0.5,
						'weights'           => array(
							'confidence' => 0.40,
							'lift'       => 0.30,
							'margin'     => 0.15,
							'stock'      => 0.10,
							'recency'    => 0.05,
						),
						'decay_rate'        => 0.01,
						'query_headroom_mult' => 4,
					),
					'precision' => array(
						'min_co_occurrence' => 3,
						'min_support'       => 0.001,
						'min_confidence'    => 0.03,
						'min_lift'          => 1.0,
						'weights'           => array(
							'confidence' => 0.45,
							'lift'       => 0.35,
							'margin'     => 0.10,
							'stock'      => 0.05,
							'recency'    => 0.05,
						),
						'decay_rate'        => 0.01,
						'query_headroom_mult' => 3,
					),
					'sparse_basket' => array(
						'min_co_occurrence' => 2,
						'min_support'       => 0.0004,
						'min_confidence'    => 0.008,
						'min_lift'          => 0.5,
						'weights'           => array(
							'confidence' => 0.45,
							'lift'       => 0.25,
							'margin'     => 0.10,
							'stock'      => 0.10,
							'recency'    => 0.10,
						),
						'decay_rate'        => 0.006,
						'query_headroom_mult' => 6,
					),
				),
				'messages'       => array(
					'rebuildQueued' => __( 'Rebuild queued. Checking progress...', 'commerce-intelligence-engine' ),
					'cacheCleared'  => __( 'Cache cleared.', 'commerce-intelligence-engine' ),
					'requestFailed' => __( 'Request failed. Please try again.', 'commerce-intelligence-engine' ),
					'presetApplied' => __( 'Algorithm preset applied. Save settings to persist.', 'commerce-intelligence-engine' ),
				),
			)
		);
	}

	/**
	 * Registers settings page under WooCommerce.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Commerce Intelligence Engine', 'commerce-intelligence-engine' ),
			__( 'Commerce Intelligence', 'commerce-intelligence-engine' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handles settings form POST on page load.
	 *
	 * @return void
	 */
	public function maybe_handle_form_submission(): void {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			return;
		}

		if ( self::PAGE_SLUG !== $this->get_request_tab_or_page( 'page' ) ) {
			return;
		}

		if ( ! isset( $_POST['cie_settings_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'commerce-intelligence-engine' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['cie_settings_nonce'] ) && ! is_array( $_POST['cie_settings_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['cie_settings_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'cie_settings_save' ) ) {
			$this->redirect_with_notice( 'nonce_error' );
		}

		if ( ! class_exists( 'CIE_Settings' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-settings.php';
		}

		$input   = wp_unslash( $_POST );
		$updated = CIE_Settings::update( is_array( $input ) ? $input : array() );

		$this->redirect_with_notice( $updated ? 'saved' : 'save_error' );
	}

	/**
	 * Renders admin notices for page events.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( self::PAGE_SLUG !== $this->get_request_tab_or_page( 'page' ) ) {
			return;
		}

		$notice = $this->get_request_tab_or_page( self::NOTICE_KEY );
		if ( '' === $notice ) {
			return;
		}

		$class   = 'notice notice-info';
		$message = '';

		if ( 'saved' === $notice ) {
			$class   = 'notice notice-success';
			$message = __( 'Settings saved.', 'commerce-intelligence-engine' );
		} elseif ( 'save_error' === $notice ) {
			$class   = 'notice notice-error';
			$message = __( 'Settings could not be saved.', 'commerce-intelligence-engine' );
		} elseif ( 'nonce_error' === $notice ) {
			$class   = 'notice notice-error';
			$message = __( 'Security check failed. Please refresh and try again.', 'commerce-intelligence-engine' );
		}

		if ( '' === $message ) {
			return;
		}

		echo '<div class="' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Renders settings page content.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'commerce-intelligence-engine' ), '', array( 'response' => 403 ) );
		}

		if ( ! class_exists( 'CIE_Settings' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-settings.php';
		}

		$settings           = CIE_Settings::get();
		$active_tab         = $this->get_active_tab();
		$tabs               = $this->get_tabs();
		$order_statuses     = $this->get_order_statuses();
		$product_categories = $this->get_product_categories();
		$last_rebuild       = $this->get_last_rebuild();
		$recent_rebuilds    = $this->get_recent_rebuilds( 10 );

		require CIE_PLUGIN_DIR . 'admin/partials/commerce-intelligence-engine-admin-settings-display.php';
	}

	/**
	 * Returns page tabs.
	 *
	 * @return array
	 */
	private function get_tabs(): array {
		return array(
			'general'    => __( 'General', 'commerce-intelligence-engine' ),
			'algorithm'  => __( 'Algorithm', 'commerce-intelligence-engine' ),
			'display'    => __( 'Display', 'commerce-intelligence-engine' ),
			'operations' => __( 'Operations', 'commerce-intelligence-engine' ),
		);
	}

	/**
	 * Returns current active tab.
	 *
	 * @return string
	 */
	private function get_active_tab(): string {
		$tab     = $this->get_request_tab_or_page( 'tab' );
		$allowed = array_keys( $this->get_tabs() );

		if ( in_array( $tab, $allowed, true ) ) {
			return $tab;
		}

		return 'general';
	}

	/**
	 * Returns last rebuild row.
	 *
	 * @return array
	 */
	private function get_last_rebuild(): array {
		if ( ! class_exists( 'CIE_Rebuild_Log_Repository' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-rebuild-log-repository.php';
		}

		$repository = new CIE_Rebuild_Log_Repository();
		$rows       = $repository->get_recent( 1 );

		if ( empty( $rows ) || ! is_array( $rows[0] ) ) {
			return array();
		}

		return $rows[0];
	}

	/**
	 * Returns recent rebuild rows.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	private function get_recent_rebuilds( int $limit = 10 ): array {
		if ( ! class_exists( 'CIE_Rebuild_Log_Repository' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-rebuild-log-repository.php';
		}

		$repository = new CIE_Rebuild_Log_Repository();
		$rows       = $repository->get_recent( max( 1, $limit ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns WooCommerce order statuses.
	 *
	 * @return array
	 */
	private function get_order_statuses(): array {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$statuses = wc_get_order_statuses();
			if ( is_array( $statuses ) ) {
				return $statuses;
			}
		}

		return array();
	}

	/**
	 * Returns product categories for exclusion field.
	 *
	 * @return array
	 */
	private function get_product_categories(): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$categories = array();

		foreach ( $terms as $term ) {
			if ( ! isset( $term->term_id ) || ! isset( $term->name ) ) {
				continue;
			}

			$categories[ (int) $term->term_id ] = (string) $term->name;
		}

		return $categories;
	}

	/**
	 * Redirects to settings page with a notice code.
	 *
	 * @param string $notice Notice code.
	 * @return void
	 */
	private function redirect_with_notice( string $notice ): void {
		$url = add_query_arg(
			array(
				'page'              => self::PAGE_SLUG,
				'tab'               => $this->get_active_tab(),
				self::NOTICE_KEY    => sanitize_key( $notice ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Returns sanitized scalar request value.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function get_request_tab_or_page( string $key ): string {
		if ( ! isset( $_REQUEST[ $key ] ) || is_array( $_REQUEST[ $key ] ) ) {
			return '';
		}

		return sanitize_key( wp_unslash( $_REQUEST[ $key ] ) );
	}
}
