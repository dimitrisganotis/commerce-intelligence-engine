<?php
/**
 * Recommendation block template.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

$result   = isset( $result ) && is_array( $result ) ? $result : array();
$settings = isset( $settings ) && is_array( $settings ) ? $settings : array();
$items    = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();

if ( empty( $items ) ) {
	return;
}

$display_settings = isset( $settings['display'] ) && is_array( $settings['display'] ) ? $settings['display'] : array();
$title            = isset( $display_settings['title'] ) ? (string) $display_settings['title'] : __( 'Frequently bought together', 'commerce-intelligence-engine' );
$show_reason      = ! empty( $display_settings['show_reason'] );
$context          = isset( $result['context'] ) ? sanitize_key( (string) $result['context'] ) : 'product';
$loop_items       = array();

foreach ( $items as $item ) {
	if ( ! is_array( $item ) || empty( $item['product_id'] ) ) {
		continue;
	}

	$product_id = absint( $item['product_id'] );
	if ( $product_id <= 0 ) {
		continue;
	}

	$post_object = get_post( $product_id );
	if ( ! $post_object || 'publish' !== $post_object->post_status ) {
		continue;
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		continue;
	}

	$loop_items[] = array(
		'post'   => $post_object,
		'product' => $product,
		'reason' => isset( $item['reason'] ) ? (string) $item['reason'] : '',
		'source' => isset( $item['source'] ) ? sanitize_key( (string) $item['source'] ) : '',
	);
}

if ( empty( $loop_items ) ) {
	return;
}

$previous_post    = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$previous_product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;
$wrapper_class    = 'cie-recommendations';
$wrapper_style    = '';

if ( 'product' === $context ) {
	$wrapper_class .= ' related products';
} else {
	$wrapper_class .= ' cie-recommendations--' . $context;
}

if ( 'cart' === $context ) {
	$wrapper_class .= ' related products';
	$wrapper_style = 'clear: both; width: 100%;';
}

if ( 'checkout' === $context ) {
	$wrapper_class .= ' related products';
}
?>
<section class="<?php echo esc_attr( $wrapper_class ); ?>"<?php echo '' !== $wrapper_style ? ' style="' . esc_attr( $wrapper_style ) . '"' : ''; ?>>
	<h2><?php echo esc_html( $title ); ?></h2>
	<ul class="products columns-4">
		<?php foreach ( $loop_items as $loop_item ) : ?>
			<?php
			$GLOBALS['post']    = $loop_item['post'];
			$GLOBALS['product'] = $loop_item['product'];

			setup_postdata( $GLOBALS['post'] );

			ob_start();
			wc_get_template_part( 'content', 'product' );
			$product_card = (string) ob_get_clean();

			if ( $show_reason && '' !== $loop_item['reason'] && 'mined' === $loop_item['source'] ) {
				$reason_html = '<p class="cie-recommendations__reason">' . esc_html( $loop_item['reason'] ) . '</p>';
				$last_li_pos = strripos( $product_card, '</li>' );

				if ( false !== $last_li_pos ) {
					$product_card = substr_replace( $product_card, $reason_html . '</li>', $last_li_pos, 5 );
				}
			}
			?>
			<?php echo $product_card; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endforeach; ?>
	</ul>
</section>
<?php
wp_reset_postdata();

if ( null !== $previous_post ) {
	$GLOBALS['post'] = $previous_post;
}

if ( null !== $previous_product ) {
	$GLOBALS['product'] = $previous_product;
}
