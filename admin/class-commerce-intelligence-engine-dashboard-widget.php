<?php
/**
 * Dashboard widget for CIE operational insights.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Commerce Intelligence Engine dashboard widget.
 */
class CIE_Dashboard_Widget {

	/**
	 * Dashboard widget ID.
	 *
	 * @var string
	 */
	const WIDGET_ID = 'cie_dashboard_widget';

	/**
	 * Transient key for widget query cache.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'cie_dashboard_widget_data';

	/**
	 * Cache TTL in seconds.
	 *
	 * @var int
	 */
	const CACHE_TTL = 3600;

	/**
	 * Registers WordPress hooks for widget lifecycle.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
		add_action( 'cie_rebuild_completed', array( __CLASS__, 'delete_cache' ), 10, 2 );
	}

	/**
	 * Registers dashboard widget.
	 *
	 * @return void
	 */
	public static function register_widget(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Commerce Intelligence Engine', 'commerce-intelligence-engine' ),
			array( __CLASS__, 'render_widget' )
		);
	}

	/**
	 * Renders dashboard widget content.
	 *
	 * @return void
	 */
	public static function render_widget(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			echo '<p>' . esc_html__( 'Permission denied.', 'commerce-intelligence-engine' ) . '</p>';
			return;
		}

		$data       = self::get_widget_data();
		$status     = isset( $data['last_run_status'] ) ? sanitize_key( (string) $data['last_run_status'] ) : 'never';
		$badge_data = self::get_status_badge( $status );
		$started_at = isset( $data['last_run_started_at'] ) ? (string) $data['last_run_started_at'] : '';
		$analytics_warning = isset( $data['analytics_warning'] ) ? (string) $data['analytics_warning'] : '';

		echo '<p>';
		echo '<strong>' . esc_html__( 'Last run status:', 'commerce-intelligence-engine' ) . '</strong> ';
		echo '<span style="display:inline-block;padding:2px 8px;border-radius:999px;color:#ffffff;background-color:' . esc_attr( $badge_data['color'] ) . ';">' . esc_html( $badge_data['label'] ) . '</span>';
		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Last run:', 'commerce-intelligence-engine' ) . '</strong> ';
		if ( '' === $started_at ) {
			echo esc_html__( 'No rebuild has run yet', 'commerce-intelligence-engine' );
		} else {
			echo esc_html( self::format_timestamp( $started_at ) );
		}
		echo '</p>';

		if ( '' !== $analytics_warning ) {
			echo '<p><strong>' . esc_html__( 'Data source warning:', 'commerce-intelligence-engine' ) . '</strong> ';
			echo esc_html( $analytics_warning );
			echo '</p>';
		}

		echo '<p><strong>' . esc_html__( 'Total associations:', 'commerce-intelligence-engine' ) . '</strong> ';
		echo esc_html( (string) absint( $data['associations_count'] ?? 0 ) );
		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Products with zero associations:', 'commerce-intelligence-engine' ) . '</strong> ';
		echo esc_html( (string) absint( $data['zero_association_products'] ?? 0 ) );
		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Top associations by lift:', 'commerce-intelligence-engine' ) . '</strong></p>';

		$top_associations = isset( $data['top_associations'] ) && is_array( $data['top_associations'] ) ? $data['top_associations'] : array();

		if ( empty( $top_associations ) ) {
			echo '<p>' . esc_html__( 'No associations yet', 'commerce-intelligence-engine' ) . '</p>';
			return;
		}

		echo '<ul>';
		foreach ( $top_associations as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$product_a = isset( $item['product_a_name'] ) ? (string) $item['product_a_name'] : '';
			$product_b = isset( $item['product_b_name'] ) ? (string) $item['product_b_name'] : '';
			$lift      = isset( $item['lift'] ) ? (float) $item['lift'] : 0.0;

			$line = sprintf(
				/* translators: 1: product A, 2: product B, 3: lift value */
				__( '%1$s -> %2$s (lift %3$sx)', 'commerce-intelligence-engine' ),
				$product_a,
				$product_b,
				number_format_i18n( $lift, 2 )
			);

			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Clears widget transient cache.
	 *
	 * @param string $run_id  Rebuild run ID.
	 * @param array  $metrics Rebuild metrics.
	 * @return void
	 */
	public static function delete_cache( $run_id = '', $metrics = array() ): void {
		unset( $run_id, $metrics );
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Loads or builds widget data.
	 *
	 * @return array
	 */
	private static function get_widget_data(): array {
		global $wpdb;

		if ( ! class_exists( 'CIE_DB_Migrator' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-db-migrator.php';
		}

		$health_snapshot = get_option( 'cie_health_snapshot', array() );
		$last_run_at     = get_option( 'cie_last_run_at', '' );

		if ( ! is_array( $health_snapshot ) ) {
			$health_snapshot = array();
		}

		$table_associations = CIE_DB_Migrator::get_table_name( 'associations' );
		$analytics_diag     = self::get_analytics_diagnostic();

		$associations_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_associations}" );
		$associations_count = null === $associations_count ? 0 : absint( $associations_count );

		$top_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, associated_product_id, lift FROM {$table_associations} ORDER BY lift DESC LIMIT %d",
				5
			),
			ARRAY_A
		);

		$top_associations = array();

		if ( is_array( $top_rows ) ) {
			foreach ( $top_rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$product_id            = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
				$associated_product_id = isset( $row['associated_product_id'] ) ? absint( $row['associated_product_id'] ) : 0;

				if ( $product_id <= 0 || $associated_product_id <= 0 ) {
					continue;
				}

				$product_a_name = get_the_title( $product_id );
				$product_b_name = get_the_title( $associated_product_id );

				if ( '' === $product_a_name ) {
					$product_a_name = sprintf(
						/* translators: %d: product ID */
						__( 'Product #%d', 'commerce-intelligence-engine' ),
						$product_id
					);
				}

				if ( '' === $product_b_name ) {
					$product_b_name = sprintf(
						/* translators: %d: product ID */
						__( 'Product #%d', 'commerce-intelligence-engine' ),
						$associated_product_id
					);
				}

				$top_associations[] = array(
					'product_a_name' => $product_a_name,
					'product_b_name' => $product_b_name,
					'lift'           => isset( $row['lift'] ) ? (float) $row['lift'] : 0.0,
				);
			}
		}

		$zero_association_products = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1)
				FROM {$wpdb->posts} AS p
				LEFT JOIN {$table_associations} AS a ON p.ID = a.product_id
				WHERE p.post_type = %s
					AND p.post_status = %s
					AND a.product_id IS NULL",
				'product',
				'publish'
			)
		);
		$zero_association_products = null === $zero_association_products ? 0 : absint( $zero_association_products );

		$last_run_status = 'never';
		if ( isset( $health_snapshot['status'] ) && is_scalar( $health_snapshot['status'] ) ) {
			$candidate_status = sanitize_key( (string) $health_snapshot['status'] );
			if ( in_array( $candidate_status, array( 'completed', 'running', 'failed' ), true ) ) {
				$last_run_status = $candidate_status;
			}
		}

		$last_run_started_at = '';
		if ( is_scalar( $last_run_at ) ) {
			$last_run_started_at = sanitize_text_field( (string) $last_run_at );
		}

		$data = array(
			'last_run_status'           => $last_run_status,
			'last_run_started_at'       => $last_run_started_at,
			'associations_count'        => $associations_count,
			'top_associations'          => $top_associations,
			'zero_association_products' => $zero_association_products,
			'analytics_warning'         => isset( $analytics_diag['warning'] ) ? (string) $analytics_diag['warning'] : '',
		);

		return $data;
	}

	/**
	 * Returns diagnostic warning for WooCommerce analytics lookup source.
	 *
	 * @return array
	 */
	private static function get_analytics_diagnostic(): array {
		global $wpdb;

		$order_stats_table  = $wpdb->prefix . 'wc_order_stats';
		$order_lookup_table = $wpdb->prefix . 'wc_order_product_lookup';

		if ( ! self::table_exists( $order_stats_table ) || ! self::table_exists( $order_lookup_table ) ) {
			return array(
				'warning' => __( 'WooCommerce Analytics lookup tables are missing. CIE requires wc_order_stats and wc_order_product_lookup.', 'commerce-intelligence-engine' ),
			);
		}

		$has_lookup_rows = $wpdb->get_var( "SELECT 1 FROM {$order_lookup_table} LIMIT 1" );
		if ( null === $has_lookup_rows ) {
			return array(
				'warning' => __( 'WooCommerce Analytics lookup tables are empty. Recommendations cannot be mined until analytics data is populated (or if analytics is disabled).', 'commerce-intelligence-engine' ),
			);
		}

		return array(
			'warning' => '',
		);
	}

	/**
	 * Returns label and color for status badge.
	 *
	 * @param string $status Run status.
	 * @return array
	 */
	private static function get_status_badge( string $status ): array {
		if ( 'completed' === $status ) {
			return array(
				'label' => __( 'Completed', 'commerce-intelligence-engine' ),
				'color' => '#15803d',
			);
		}

		if ( 'running' === $status ) {
			return array(
				'label' => __( 'Running', 'commerce-intelligence-engine' ),
				'color' => '#d97706',
			);
		}

		if ( 'failed' === $status ) {
			return array(
				'label' => __( 'Failed', 'commerce-intelligence-engine' ),
				'color' => '#b91c1c',
			);
		}

		return array(
			'label' => __( 'Never run', 'commerce-intelligence-engine' ),
			'color' => '#6b7280',
		);
	}

	/**
	 * Formats SQL datetime for human-readable display.
	 *
	 * @param string $started_at MySQL datetime.
	 * @return string
	 */
	private static function format_timestamp( string $started_at ): string {
		$timestamp = strtotime( $started_at );

		if ( false === $timestamp ) {
			return $started_at;
		}

		$formatted = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
		$relative  = human_time_diff( $timestamp, current_time( 'timestamp', true ) );

		return sprintf(
			/* translators: 1: formatted datetime, 2: relative time */
			__( '%1$s (%2$s ago)', 'commerce-intelligence-engine' ),
			$formatted,
			$relative
		);
	}

	/**
	 * Returns true when table exists.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private static function table_exists( string $table_name ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		return $found === $table_name;
	}
}

CIE_Dashboard_Widget::register_hooks();
