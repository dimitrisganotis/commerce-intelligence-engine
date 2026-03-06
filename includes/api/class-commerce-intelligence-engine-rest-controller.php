<?php
/**
 * REST API controller for CIE recommendations.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	require_once ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-controller.php';
}

/**
 * Handles CIE recommendations REST endpoint.
 */
class CIE_REST_Controller extends WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'cie/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'recommendations';

	/**
	 * Registers controller hooks when endpoint is enabled.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( ! self::is_endpoint_enabled() ) {
			return;
		}

		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
	}

	/**
	 * Registers endpoint routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		if ( ! self::is_endpoint_enabled() ) {
			return;
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<product_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => array(
						'product_id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'limit'      => array(
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'context'    => array(
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Validates endpoint permission rules.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function permission_callback( $request ) {
		$settings = self::get_rest_settings();

		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error(
				'cie_disabled',
				__( 'Recommendations endpoint is disabled.', 'commerce-intelligence-engine' ),
				array( 'status' => 403 )
			);
		}

		$access_mode = isset( $settings['access_mode'] ) ? sanitize_key( (string) $settings['access_mode'] ) : 'public';
		if ( 'authenticated-only' === $access_mode ) {
			return is_user_logged_in();
		}

		$product_id = absint( $request->get_param( 'product_id' ) );
		if ( ! $this->is_published_product( $product_id ) ) {
			return new WP_Error(
				'cie_invalid_product',
				__( 'Invalid product_id.', 'commerce-intelligence-engine' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Returns endpoint response for recommendations.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$product_id = absint( $request->get_param( 'product_id' ) );
		$limit      = $this->resolve_limit( $request->get_param( 'limit' ) );
		$context    = $this->resolve_context( $request->get_param( 'context' ) );

		$recommender = $this->get_recommender();
		$result      = $recommender->get_recommendations( $product_id, $context, $limit );

		$items            = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		$recommendations  = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$recommendations[] = array(
				'product_id' => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
				'score'      => isset( $item['score'] ) ? (float) $item['score'] : 0.0,
				'confidence' => isset( $item['confidence'] ) ? (float) $item['confidence'] : 0.0,
				'lift'       => isset( $item['lift'] ) ? (float) $item['lift'] : 0.0,
				'reason'     => isset( $item['reason'] ) ? sanitize_text_field( (string) $item['reason'] ) : '',
				'source'     => $this->map_source( isset( $item['source'] ) ? (string) $item['source'] : '' ),
			);
		}

		$response = new WP_REST_Response(
			array(
				'product_id'       => isset( $result['product_id'] ) ? absint( $result['product_id'] ) : $product_id,
				'recommendations'  => $recommendations,
				'source'           => $this->map_source( isset( $result['source'] ) ? (string) $result['source'] : '' ),
				'generated_at'     => isset( $result['generated_at'] ) ? sanitize_text_field( (string) $result['generated_at'] ) : '',
			)
		);

		$settings    = self::get_rest_settings();
		$access_mode = isset( $settings['access_mode'] ) ? sanitize_key( (string) $settings['access_mode'] ) : 'public';
		if ( 'public' === $access_mode ) {
			$cache_max_age = isset( $settings['cache_max_age'] ) ? absint( $settings['cache_max_age'] ) : 300;
			$response->header( 'Cache-Control', 'public, max-age=' . $cache_max_age );
		}

		return $response;
	}

	/**
	 * Returns response schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->schema;
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'cie_recommendations_response',
			'type'       => 'object',
			'properties' => array(
				'product_id'      => array(
					'type' => 'integer',
				),
				'recommendations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'product_id' => array( 'type' => 'integer' ),
							'score'      => array( 'type' => 'number' ),
							'confidence' => array( 'type' => 'number' ),
							'lift'       => array( 'type' => 'number' ),
							'reason'     => array( 'type' => 'string' ),
							'source'     => array( 'type' => 'string' ),
						),
					),
				),
				'source'          => array(
					'type' => 'string',
				),
				'generated_at'    => array(
					'type' => 'string',
				),
			),
		);

		return $this->schema;
	}

	/**
	 * Maps internal source names to REST source names.
	 *
	 * @param string $source Internal source.
	 * @return string
	 */
	private function map_source( string $source ): string {
		$source = sanitize_key( $source );

		if ( 'mined' === $source ) {
			return 'associations';
		}

		if ( in_array( $source, array( 'cross_sell', 'category_bestseller', 'global_bestseller' ), true ) ) {
			return $source;
		}

		return 'associations';
	}

	/**
	 * Resolves request limit into accepted range.
	 *
	 * @param mixed $limit Request limit.
	 * @return int
	 */
	private function resolve_limit( $limit ): int {
		$limit = absint( $limit );

		if ( $limit <= 0 ) {
			return 4;
		}

		return min( 20, $limit );
	}

	/**
	 * Resolves request context against allowlist.
	 *
	 * @param mixed $context Request context.
	 * @return string
	 */
	private function resolve_context( $context ): string {
		$context = sanitize_key( (string) $context );

		if ( in_array( $context, array( 'product', 'cart', 'checkout' ), true ) ) {
			return $context;
		}

		return 'product';
	}

	/**
	 * Checks if product is published.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private function is_published_product( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		return 'product' === get_post_type( $product_id ) && 'publish' === get_post_status( $product_id );
	}

	/**
	 * Builds recommender instance.
	 *
	 * @return CIE_Recommender
	 */
	private function get_recommender(): CIE_Recommender {
		if ( ! class_exists( 'CIE_Association_Repository' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-association-repository.php';
		}

		if ( ! class_exists( 'CIE_Cache' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-cache.php';
		}

		if ( ! class_exists( 'CIE_Recommender' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/engine/class-commerce-intelligence-engine-recommender.php';
		}

		return new CIE_Recommender( new CIE_Association_Repository(), new CIE_Cache() );
	}

	/**
	 * Returns REST settings section.
	 *
	 * @return array
	 */
	private static function get_rest_settings(): array {
		if ( ! class_exists( 'CIE_Settings' ) ) {
			require_once CIE_PLUGIN_DIR . 'includes/class-commerce-intelligence-engine-settings.php';
		}

		$settings = CIE_Settings::get();
		if ( ! is_array( $settings ) ) {
			return array();
		}

		$rest_settings = isset( $settings['rest_api'] ) && is_array( $settings['rest_api'] ) ? $settings['rest_api'] : array();

		return array(
			'enabled'      => isset( $rest_settings['enabled'] ) ? (bool) $rest_settings['enabled'] : false,
			'access_mode'  => isset( $rest_settings['access_mode'] ) ? (string) $rest_settings['access_mode'] : 'public',
			'cache_max_age' => isset( $rest_settings['cache_max_age'] ) ? absint( $rest_settings['cache_max_age'] ) : 300,
		);
	}

	/**
	 * Returns true when endpoint is enabled in settings.
	 *
	 * @return bool
	 */
	private static function is_endpoint_enabled(): bool {
		$rest_settings = self::get_rest_settings();
		return ! empty( $rest_settings['enabled'] );
	}
}

CIE_REST_Controller::register_hooks();
