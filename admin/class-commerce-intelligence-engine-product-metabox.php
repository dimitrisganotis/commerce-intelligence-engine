<?php
/**
 * Product edit metabox for CIE insights and overrides.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the CIE product metabox and handles override saves.
 */
class CIE_Product_Metabox {

	/**
	 * Metabox identifier.
	 *
	 * @var string
	 */
	const METABOX_ID = 'cie_intelligence_metabox';

	/**
	 * Registers metabox and AJAX hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ) );
		add_action( 'wp_ajax_cie_save_override', array( __CLASS__, 'handle_save_override' ) );
		add_action( 'cie_overrides_updated', array( __CLASS__, 'handle_overrides_updated' ), 10, 3 );
	}

	/**
	 * Registers the product metabox.
	 *
	 * @return void
	 */
	public static function register_metabox(): void {
		add_meta_box(
			self::METABOX_ID,
			__( 'CIE Intelligence', 'commerce-intelligence-engine' ),
			array( __CLASS__, 'render_metabox' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Renders metabox content.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_metabox( $post ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			echo '<p>' . esc_html__( 'Permission denied.', 'commerce-intelligence-engine' ) . '</p>';
			return;
		}

		$product_id = isset( $post->ID ) ? absint( $post->ID ) : 0;
		if ( $product_id <= 0 ) {
			echo '<p>' . esc_html__( 'No associations yet — run a rebuild.', 'commerce-intelligence-engine' ) . '</p>';
			return;
		}

		$repo        = self::get_association_repository();
		$recommender = self::get_recommender();
		$rows        = $repo->get_for_product( $product_id, 50 );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No associations yet — run a rebuild.', 'commerce-intelligence-engine' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Associated Product', 'commerce-intelligence-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Support %', 'commerce-intelligence-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Confidence %', 'commerce-intelligence-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Lift', 'commerce-intelligence-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Score', 'commerce-intelligence-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'commerce-intelligence-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Pin', 'commerce-intelligence-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Exclude', 'commerce-intelligence-engine' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$associated_product_id = isset( $row['associated_product_id'] ) ? absint( $row['associated_product_id'] ) : 0;
			if ( $associated_product_id <= 0 ) {
				continue;
			}

			$product_title = get_the_title( $associated_product_id );
			if ( '' === $product_title ) {
				$product_title = sprintf(
					/* translators: %d: product ID */
					__( 'Product #%d', 'commerce-intelligence-engine' ),
					$associated_product_id
				);
			}

			$product_link = get_edit_post_link( $associated_product_id, '' );
			$support      = isset( $row['support'] ) ? (float) $row['support'] : 0.0;
			$confidence   = isset( $row['confidence'] ) ? (float) $row['confidence'] : 0.0;
			$lift         = isset( $row['lift'] ) ? (float) $row['lift'] : 0.0;
			$score        = isset( $row['score'] ) ? (float) $row['score'] : 0.0;
			$source       = isset( $row['source'] ) ? sanitize_key( (string) $row['source'] ) : 'mined';

			$reason = $recommender->generate_reason(
				array(
					'confidence' => $confidence,
					'lift'       => $lift,
					'source'     => $source,
				)
			);

			echo '<tr>';
			echo '<td>';
			if ( '' !== $product_link ) {
				echo '<a href="' . esc_url( $product_link ) . '">' . esc_html( $product_title ) . '</a>';
			} else {
				echo esc_html( $product_title );
			}
			echo '</td>';
			echo '<td>' . esc_html( number_format_i18n( $support * 100, 2 ) ) . '%</td>';
			echo '<td>' . esc_html( number_format_i18n( $confidence * 100, 2 ) ) . '%</td>';
			echo '<td>' . esc_html( number_format_i18n( $lift, 2 ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $score, 4 ) ) . '</td>';
			echo '<td>' . esc_html( $reason ) . '</td>';
			echo '<td>' . self::render_override_form( $product_id, $associated_product_id, 'pin' ) . '</td>';
			echo '<td>' . self::render_override_form( $product_id, $associated_product_id, 'exclude' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Saves manual override action via admin AJAX.
	 *
	 * @return void
	 */
	public static function handle_save_override(): void {
		$product_id = 0;
		if ( isset( $_POST['product_id'] ) && ! is_array( $_POST['product_id'] ) ) {
			$product_id = absint( wp_unslash( $_POST['product_id'] ) );
		}

		$nonce      = isset( $_POST['cie_override_nonce'] ) && ! is_array( $_POST['cie_override_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['cie_override_nonce'] ) )
			: '';

		if ( $product_id <= 0 || '' === $nonce || ! wp_verify_nonce( $nonce, 'cie_override_nonce_' . $product_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid nonce', 'commerce-intelligence-engine' ),
				),
				403
			);
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied', 'commerce-intelligence-engine' ),
				),
				403
			);
		}

		$associated_product_id = 0;
		if ( isset( $_POST['associated_product_id'] ) && ! is_array( $_POST['associated_product_id'] ) ) {
			$associated_product_id = absint( wp_unslash( $_POST['associated_product_id'] ) );
		}
		$override_action       = isset( $_POST['override_action'] ) && ! is_array( $_POST['override_action'] )
			? sanitize_key( wp_unslash( $_POST['override_action'] ) )
			: '';

		if ( $product_id <= 0 || $associated_product_id <= 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid product IDs', 'commerce-intelligence-engine' ),
				),
				400
			);
		}

		if ( ! in_array( $override_action, array( 'pin', 'exclude' ), true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid action', 'commerce-intelligence-engine' ),
				),
				400
			);
		}

		if ( ! class_exists( 'CIE_DB_Migrator' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-db-migrator.php';
		}

		global $wpdb;

		$table_name = CIE_DB_Migrator::get_table_name( 'overrides' );
		$query      = $wpdb->prepare(
			"INSERT INTO {$table_name} (product_id, associated_product_id, action, created_by, created_at)
			VALUES (%d, %d, %s, %d, NOW())
			ON DUPLICATE KEY UPDATE
				action = VALUES(action),
				created_by = VALUES(created_by),
				created_at = VALUES(created_at)",
			$product_id,
			$associated_product_id,
			$override_action,
			get_current_user_id()
		);
		$result     = $wpdb->query( $query );

		if ( false === $result ) {
			wp_send_json_error(
				array(
					'message' => __( 'Could not save override', 'commerce-intelligence-engine' ),
				),
				500
			);
		}

		do_action( 'cie_overrides_updated', $product_id, $associated_product_id, $override_action );

		wp_send_json_success(
			array(
				'product_id'            => $product_id,
				'associated_product_id' => $associated_product_id,
				'action'                => $override_action,
			)
		);
	}

	/**
	 * Invalidates recommendation cache namespace when overrides change.
	 *
	 * @param int    $product_id            Base product ID.
	 * @param int    $associated_product_id Associated product ID.
	 * @param string $override_action       Override action.
	 * @return void
	 */
	public static function handle_overrides_updated( int $product_id, int $associated_product_id, string $override_action ): void {
		unset( $product_id, $associated_product_id, $override_action );

		if ( ! class_exists( 'CIE_Cache' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-cache.php';
		}

		$cache = new CIE_Cache();
		$cache->flush_namespace( 'cie_recs' );
	}

	/**
	 * Returns override action form markup.
	 *
	 * @param int    $product_id            Base product ID.
	 * @param int    $associated_product_id Associated product ID.
	 * @param string $override_action       Override action.
	 * @return string
	 */
	private static function render_override_form( int $product_id, int $associated_product_id, string $override_action ): string {
		$button_label = ( 'pin' === $override_action )
			? __( 'Pin', 'commerce-intelligence-engine' )
			: __( 'Exclude', 'commerce-intelligence-engine' );

		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<input type="hidden" name="action" value="cie_save_override" />
			<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />
			<input type="hidden" name="associated_product_id" value="<?php echo esc_attr( (string) $associated_product_id ); ?>" />
			<input type="hidden" name="override_action" value="<?php echo esc_attr( $override_action ); ?>" />
			<?php wp_nonce_field( 'cie_override_nonce_' . $product_id, 'cie_override_nonce' ); ?>
			<button type="submit" class="button button-small"><?php echo esc_html( $button_label ); ?></button>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Loads and returns association repository.
	 *
	 * @return CIE_Association_Repository
	 */
	private static function get_association_repository(): CIE_Association_Repository {
		if ( ! class_exists( 'CIE_Association_Repository' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-association-repository.php';
		}

		return new CIE_Association_Repository();
	}

	/**
	 * Loads and returns recommender.
	 *
	 * @return CIE_Recommender
	 */
	private static function get_recommender(): CIE_Recommender {
		if ( ! class_exists( 'CIE_Cache' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-cache.php';
		}

		if ( ! class_exists( 'CIE_Recommender' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-recommender.php';
		}

		return new CIE_Recommender( self::get_association_repository(), new CIE_Cache() );
	}
}

CIE_Product_Metabox::register_hooks();
