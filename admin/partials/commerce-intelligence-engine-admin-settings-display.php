<?php
/**
 * Settings page display template.
 *
 * @package Commerce_Intelligence_Engine
 */

defined( 'ABSPATH' ) || exit;

$settings = is_array( $settings ) ? $settings : array();

$tabs               = is_array( $tabs ) ? $tabs : array();
$active_tab         = isset( $active_tab ) ? sanitize_key( (string) $active_tab ) : 'general';
$order_statuses     = is_array( $order_statuses ) ? $order_statuses : array();
$product_categories = is_array( $product_categories ) ? $product_categories : array();
$last_rebuild       = is_array( $last_rebuild ) ? $last_rebuild : array();
$recent_rebuilds    = isset( $recent_rebuilds ) && is_array( $recent_rebuilds ) ? $recent_rebuilds : array();

$display_settings  = isset( $settings['display'] ) && is_array( $settings['display'] ) ? $settings['display'] : array();
$weights           = isset( $settings['weights'] ) && is_array( $settings['weights'] ) ? $settings['weights'] : array();
$rest_api_settings = isset( $settings['rest_api'] ) && is_array( $settings['rest_api'] ) ? $settings['rest_api'] : array();

$selected_statuses  = isset( $settings['included_statuses'] ) && is_array( $settings['included_statuses'] ) ? $settings['included_statuses'] : array();
$excluded_categories = isset( $settings['excluded_category_ids'] ) && is_array( $settings['excluded_category_ids'] ) ? $settings['excluded_category_ids'] : array();

$rebuild_nonce = wp_create_nonce( 'cie_rebuild_nonce' );
$status_nonce  = wp_create_nonce( 'cie_status_nonce' );
$cache_nonce   = wp_create_nonce( 'cie_cache_nonce' );
$rebuild_state = get_option( 'cie_rebuild_state', array() );

if ( ! is_array( $rebuild_state ) ) {
	$rebuild_state = array();
}

$status               = isset( $last_rebuild['status'] ) ? sanitize_key( (string) $last_rebuild['status'] ) : 'idle';
$orders_processed     = isset( $last_rebuild['orders_processed'] ) ? absint( $last_rebuild['orders_processed'] ) : 0;
$associations_written = isset( $last_rebuild['associations_written'] ) ? absint( $last_rebuild['associations_written'] ) : 0;
$duration_seconds     = isset( $last_rebuild['duration_seconds'] ) ? absint( $last_rebuild['duration_seconds'] ) : 0;
$started_at           = isset( $last_rebuild['started_at'] ) ? sanitize_text_field( (string) $last_rebuild['started_at'] ) : '';
$error_message        = isset( $last_rebuild['error_message'] ) ? sanitize_text_field( (string) $last_rebuild['error_message'] ) : '';

if ( isset( $rebuild_state['status'] ) && is_scalar( $rebuild_state['status'] ) ) {
	$status = sanitize_key( (string) $rebuild_state['status'] );
}

if ( isset( $rebuild_state['orders_processed'] ) && is_scalar( $rebuild_state['orders_processed'] ) ) {
	$orders_processed = absint( $rebuild_state['orders_processed'] );
}

if ( isset( $rebuild_state['associations_written'] ) && is_scalar( $rebuild_state['associations_written'] ) ) {
	$associations_written = absint( $rebuild_state['associations_written'] );
}

if ( isset( $rebuild_state['duration_seconds'] ) && is_scalar( $rebuild_state['duration_seconds'] ) ) {
	$duration_seconds = absint( $rebuild_state['duration_seconds'] );
}

if ( isset( $rebuild_state['started_at'] ) && is_scalar( $rebuild_state['started_at'] ) ) {
	$started_at = sanitize_text_field( (string) $rebuild_state['started_at'] );
}

$has_queued_rebuild = false;

if ( function_exists( 'as_has_scheduled_action' ) ) {
	$has_queued_rebuild = false !== as_has_scheduled_action( 'cie_run_rebuild', null, 'commerce-intelligence-engine' );
} else {
	$has_queued_rebuild = false !== wp_next_scheduled( 'cie_run_rebuild' )
		|| false !== wp_next_scheduled( 'cie_run_rebuild', array( 'mode' => 'incremental' ) )
		|| false !== wp_next_scheduled( 'cie_run_rebuild', array( 'mode' => 'full' ) );
}

if ( ! in_array( $status, array( 'running', 'queued' ), true ) && $has_queued_rebuild ) {
	$queued_at = get_option( 'cie_rebuild_queued_at', '' );
	if ( ! is_string( $queued_at ) ) {
		$queued_at = '';
	}

	$status               = 'queued';
	$orders_processed     = 0;
	$associations_written = 0;
	$duration_seconds     = 0;
	$error_message        = '';
	$started_at           = '' !== $queued_at ? $queued_at : current_time( 'mysql' );
}

$progress_notice_class = 'notice-info';

if ( 'completed' === $status ) {
	$progress_notice_class = 'notice-success';
} elseif ( 'failed' === $status ) {
	$progress_notice_class = 'notice-error';
} elseif ( 'running' === $status ) {
	$progress_notice_class = 'notice-warning';
}

$progress_message = sprintf(
	/* translators: 1: status, 2: orders processed, 3: associations written, 4: duration in seconds, 5: started at timestamp. */
	__( 'Status: %1$s | Orders: %2$d | Associations: %3$d | Duration: %4$ds | Started: %5$s', 'commerce-intelligence-engine' ),
	$status,
	$orders_processed,
	$associations_written,
	$duration_seconds,
	'' !== $started_at ? $started_at : 'n/a'
);

if ( 'failed' === $status && '' !== $error_message ) {
	$progress_message .= sprintf(
		/* translators: %s: failure error message. */
		__( ' | Error: %s', 'commerce-intelligence-engine' ),
		$error_message
	);
}

?>
<div class="wrap">
	<h1><?php esc_html_e( 'Commerce Intelligence Engine', 'commerce-intelligence-engine' ); ?></h1>

	<nav class="nav-tab-wrapper">
		<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
			<?php
			$tab_url   = add_query_arg(
				array(
					'page' => CIE_Admin_Settings::PAGE_SLUG,
					'tab'  => sanitize_key( (string) $tab_key ),
				),
				admin_url( 'admin.php' )
			);
			$is_active = ( $active_tab === $tab_key ) ? ' nav-tab-active' : '';
			?>
			<a class="nav-tab<?php echo esc_attr( $is_active ); ?>" href="<?php echo esc_url( $tab_url ); ?>">
				<?php echo esc_html( $tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form method="post" action="<?php echo esc_url( add_query_arg( array( 'page' => CIE_Admin_Settings::PAGE_SLUG, 'tab' => $active_tab ), admin_url( 'admin.php' ) ) ); ?>">
		<?php wp_nonce_field( 'cie_settings_save', 'cie_settings_nonce' ); ?>
		<input type="hidden" name="cie_settings_submit" value="1" />

		<table class="form-table" role="presentation">
			<?php if ( 'general' === $active_tab ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable engine', 'commerce-intelligence-engine' ); ?></th>
					<td>
						<input type="hidden" name="enabled" value="0" />
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( 'Enable recommendations engine', 'commerce-intelligence-engine' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-lookback-days"><?php esc_html_e( 'Lookback days', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input id="cie-lookback-days" name="lookback_days" type="number" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $settings['lookback_days'] ) ? $settings['lookback_days'] : 180 ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-included-statuses"><?php esc_html_e( 'Included order statuses', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input type="hidden" name="included_statuses[]" value="" />
						<select id="cie-included-statuses" name="included_statuses[]" multiple="multiple" style="min-width: 280px; min-height: 120px;">
							<?php foreach ( $order_statuses as $status_key => $status_label ) : ?>
								<option value="<?php echo esc_attr( (string) $status_key ); ?>" <?php selected( in_array( (string) $status_key, $selected_statuses, true ) ); ?>>
									<?php echo esc_html( (string) $status_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-max-recommendations"><?php esc_html_e( 'Max recommendations', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input id="cie-max-recommendations" name="max_recommendations" type="number" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $settings['max_recommendations'] ) ? $settings['max_recommendations'] : 4 ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-variation-mode"><?php esc_html_e( 'Variation mode', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<select id="cie-variation-mode" name="variation_mode">
							<option value="parent" <?php selected( isset( $settings['variation_mode'] ) ? $settings['variation_mode'] : '', 'parent' ); ?>><?php esc_html_e( 'Parent', 'commerce-intelligence-engine' ); ?></option>
							<option value="individual" <?php selected( isset( $settings['variation_mode'] ) ? $settings['variation_mode'] : '', 'individual' ); ?>><?php esc_html_e( 'Individual', 'commerce-intelligence-engine' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-excluded-categories"><?php esc_html_e( 'Excluded categories', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input type="hidden" name="excluded_category_ids[]" value="" />
						<select id="cie-excluded-categories" name="excluded_category_ids[]" multiple="multiple" style="min-width: 280px; min-height: 120px;">
							<?php foreach ( $product_categories as $category_id => $category_name ) : ?>
								<option value="<?php echo esc_attr( (string) $category_id ); ?>" <?php selected( in_array( (int) $category_id, $excluded_categories, true ) ); ?>>
									<?php echo esc_html( (string) $category_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'REST API enabled', 'commerce-intelligence-engine' ); ?></th>
					<td>
						<input type="hidden" name="rest_api[enabled]" value="0" />
						<label>
							<input type="checkbox" name="rest_api[enabled]" value="1" <?php checked( ! empty( $rest_api_settings['enabled'] ) ); ?> />
							<?php esc_html_e( 'Enable REST recommendations endpoint', 'commerce-intelligence-engine' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-rest-access-mode"><?php esc_html_e( 'REST API access mode', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<select id="cie-rest-access-mode" name="rest_api[access_mode]">
							<option value="public" <?php selected( isset( $rest_api_settings['access_mode'] ) ? $rest_api_settings['access_mode'] : '', 'public' ); ?>><?php esc_html_e( 'Public', 'commerce-intelligence-engine' ); ?></option>
							<option value="authenticated-only" <?php selected( isset( $rest_api_settings['access_mode'] ) ? $rest_api_settings['access_mode'] : '', 'authenticated-only' ); ?>><?php esc_html_e( 'Authenticated only', 'commerce-intelligence-engine' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-rest-cache-max-age"><?php esc_html_e( 'REST API cache max age (seconds)', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input id="cie-rest-cache-max-age" name="rest_api[cache_max_age]" type="number" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $rest_api_settings['cache_max_age'] ) ? $rest_api_settings['cache_max_age'] : 300 ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall cleanup', 'commerce-intelligence-engine' ); ?></th>
					<td>
						<input type="hidden" name="uninstall_delete_data" value="0" />
						<label>
							<input type="checkbox" name="uninstall_delete_data" value="1" <?php checked( ! empty( $settings['uninstall_delete_data'] ) ); ?> />
							<?php esc_html_e( 'Delete all plugin data on uninstall (irreversible)', 'commerce-intelligence-engine' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Warning: this permanently removes all CIE data and cannot be undone.', 'commerce-intelligence-engine' ); ?></p>
					</td>
				</tr>
			<?php elseif ( 'algorithm' === $active_tab ) : ?>
				<tr>
					<th scope="row"><label for="cie-algorithm-preset"><?php esc_html_e( 'Algorithm preset', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<select id="cie-algorithm-preset">
							<option value=""><?php esc_html_e( 'Select preset', 'commerce-intelligence-engine' ); ?></option>
							<option value="coverage"><?php esc_html_e( 'Coverage (more products)', 'commerce-intelligence-engine' ); ?></option>
							<option value="balanced"><?php esc_html_e( 'Balanced', 'commerce-intelligence-engine' ); ?></option>
							<option value="precision"><?php esc_html_e( 'Precision (stricter)', 'commerce-intelligence-engine' ); ?></option>
							<option value="sparse_basket"><?php esc_html_e( 'Sparse Basket', 'commerce-intelligence-engine' ); ?></option>
						</select>
						<button type="button" class="button" id="cie-apply-algorithm-preset"><?php esc_html_e( 'Apply preset', 'commerce-intelligence-engine' ); ?></button>
						<p class="description"><?php esc_html_e( 'Applies threshold/weight defaults. Save settings to persist.', 'commerce-intelligence-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-min-co-occurrence"><?php esc_html_e( 'Minimum co-occurrence', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input id="cie-min-co-occurrence" name="min_co_occurrence" type="number" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $settings['min_co_occurrence'] ) ? $settings['min_co_occurrence'] : 3 ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-min-support"><?php esc_html_e( 'Minimum support', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input id="cie-min-support" name="min_support" type="number" step="0.0001" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $settings['min_support'] ) ? $settings['min_support'] : 0.01 ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-min-confidence"><?php esc_html_e( 'Minimum confidence', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input id="cie-min-confidence" name="min_confidence" type="number" step="0.0001" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $settings['min_confidence'] ) ? $settings['min_confidence'] : 0.05 ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-min-lift"><?php esc_html_e( 'Minimum lift', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input id="cie-min-lift" name="min_lift" type="number" step="0.0001" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $settings['min_lift'] ) ? $settings['min_lift'] : 1.0 ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-weight-confidence"><?php esc_html_e( 'Weight: confidence', 'commerce-intelligence-engine' ); ?></label></th>
					<td><input id="cie-weight-confidence" name="weights[confidence]" type="number" step="0.0001" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $weights['confidence'] ) ? $weights['confidence'] : 0.40 ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-weight-lift"><?php esc_html_e( 'Weight: lift', 'commerce-intelligence-engine' ); ?></label></th>
					<td><input id="cie-weight-lift" name="weights[lift]" type="number" step="0.0001" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $weights['lift'] ) ? $weights['lift'] : 0.30 ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-weight-margin"><?php esc_html_e( 'Weight: margin', 'commerce-intelligence-engine' ); ?></label></th>
					<td><input id="cie-weight-margin" name="weights[margin]" type="number" step="0.0001" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $weights['margin'] ) ? $weights['margin'] : 0.15 ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-weight-stock"><?php esc_html_e( 'Weight: stock', 'commerce-intelligence-engine' ); ?></label></th>
					<td><input id="cie-weight-stock" name="weights[stock]" type="number" step="0.0001" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $weights['stock'] ) ? $weights['stock'] : 0.10 ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-weight-recency"><?php esc_html_e( 'Weight: recency', 'commerce-intelligence-engine' ); ?></label></th>
					<td><input id="cie-weight-recency" name="weights[recency]" type="number" step="0.0001" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $weights['recency'] ) ? $weights['recency'] : 0.05 ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-decay-rate"><?php esc_html_e( 'Decay rate', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input id="cie-decay-rate" name="decay_rate" type="number" step="0.0001" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $settings['decay_rate'] ) ? $settings['decay_rate'] : 0.01 ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cie-query-headroom-mult"><?php esc_html_e( 'Query headroom multiplier', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input id="cie-query-headroom-mult" name="query_headroom_mult" type="number" min="0" class="small-text" value="<?php echo esc_attr( (string) ( isset( $settings['query_headroom_mult'] ) ? $settings['query_headroom_mult'] : 3 ) ); ?>" />
					</td>
				</tr>
			<?php elseif ( 'display' === $active_tab ) : ?>
				<tr>
					<th scope="row"><label for="cie-display-title"><?php esc_html_e( 'Block title', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<input id="cie-display-title" name="display[title]" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( isset( $display_settings['title'] ) ? $display_settings['title'] : '' ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Show reason text', 'commerce-intelligence-engine' ); ?></th>
					<td>
						<input type="hidden" name="display[show_reason]" value="0" />
						<label>
							<input type="checkbox" name="display[show_reason]" value="1" <?php checked( ! empty( $display_settings['show_reason'] ) ); ?> />
							<?php esc_html_e( 'Display recommendation reason text', 'commerce-intelligence-engine' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Show on product page', 'commerce-intelligence-engine' ); ?></th>
					<td>
						<input type="hidden" name="display[show_on_product]" value="0" />
						<label><input type="checkbox" name="display[show_on_product]" value="1" <?php checked( ! empty( $display_settings['show_on_product'] ) ); ?> /> <?php esc_html_e( 'Enable on product page', 'commerce-intelligence-engine' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Show on cart', 'commerce-intelligence-engine' ); ?></th>
					<td>
						<input type="hidden" name="display[show_on_cart]" value="0" />
						<label><input type="checkbox" name="display[show_on_cart]" value="1" <?php checked( ! empty( $display_settings['show_on_cart'] ) ); ?> /> <?php esc_html_e( 'Enable on cart page', 'commerce-intelligence-engine' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Show on checkout', 'commerce-intelligence-engine' ); ?></th>
					<td>
						<input type="hidden" name="display[show_on_checkout]" value="0" />
						<label><input type="checkbox" name="display[show_on_checkout]" value="1" <?php checked( ! empty( $display_settings['show_on_checkout'] ) ); ?> /> <?php esc_html_e( 'Enable on checkout page', 'commerce-intelligence-engine' ); ?></label>
					</td>
				</tr>
			<?php elseif ( 'operations' === $active_tab ) : ?>
				<tr>
					<th scope="row"><label for="cie-schedule"><?php esc_html_e( 'Rebuild schedule', 'commerce-intelligence-engine' ); ?></label></th>
					<td>
						<select id="cie-schedule" name="schedule">
							<option value="nightly" <?php selected( isset( $settings['schedule'] ) ? $settings['schedule'] : '', 'nightly' ); ?>><?php esc_html_e( 'Nightly', 'commerce-intelligence-engine' ); ?></option>
							<option value="weekly" <?php selected( isset( $settings['schedule'] ) ? $settings['schedule'] : '', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'commerce-intelligence-engine' ); ?></option>
							<option value="manual" <?php selected( isset( $settings['schedule'] ) ? $settings['schedule'] : '', 'manual' ); ?>><?php esc_html_e( 'Manual', 'commerce-intelligence-engine' ); ?></option>
						</select>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<?php submit_button( __( 'Save settings', 'commerce-intelligence-engine' ) ); ?>

		<?php if ( 'operations' === $active_tab ) : ?>
			<hr />
			<h2><?php esc_html_e( 'Operations', 'commerce-intelligence-engine' ); ?></h2>
			<input type="hidden" id="cie-rebuild-nonce" value="<?php echo esc_attr( $rebuild_nonce ); ?>" />
			<input type="hidden" id="cie-cache-nonce" value="<?php echo esc_attr( $cache_nonce ); ?>" />
			<input type="hidden" id="cie-status-nonce" value="<?php echo esc_attr( $status_nonce ); ?>" />
			<p>
				<label for="cie-rebuild-mode" class="screen-reader-text"><?php esc_html_e( 'Rebuild mode', 'commerce-intelligence-engine' ); ?></label>
				<select id="cie-rebuild-mode" style="margin-right: 8px;">
					<option value="auto"><?php esc_html_e( 'Auto mode', 'commerce-intelligence-engine' ); ?></option>
					<option value="incremental"><?php esc_html_e( 'Incremental', 'commerce-intelligence-engine' ); ?></option>
					<option value="full"><?php esc_html_e( 'Force Full Rebuild', 'commerce-intelligence-engine' ); ?></option>
				</select>
				<button type="button" class="button button-secondary" id="cie-rebuild-now" data-nonce="<?php echo esc_attr( $rebuild_nonce ); ?>">
					<?php esc_html_e( 'Rebuild Now', 'commerce-intelligence-engine' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="cie-clear-cache" data-nonce="<?php echo esc_attr( $cache_nonce ); ?>">
					<?php esc_html_e( 'Clear Cache', 'commerce-intelligence-engine' ); ?>
				</button>
			</p>
			<div id="cie-rebuild-progress" class="notice <?php echo esc_attr( $progress_notice_class ); ?> inline">
				<p><?php echo esc_html( $progress_message ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Last rebuild stats', 'commerce-intelligence-engine' ); ?></h3>
			<?php if ( ! empty( $last_rebuild ) ) : ?>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Status', 'commerce-intelligence-engine' ); ?></th>
							<td><?php echo esc_html( isset( $last_rebuild['status'] ) ? (string) $last_rebuild['status'] : '' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Mode', 'commerce-intelligence-engine' ); ?></th>
							<td><?php echo esc_html( isset( $last_rebuild['mode'] ) ? (string) $last_rebuild['mode'] : '—' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Started at', 'commerce-intelligence-engine' ); ?></th>
							<td><?php echo esc_html( isset( $last_rebuild['started_at'] ) ? (string) $last_rebuild['started_at'] : '' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Orders processed', 'commerce-intelligence-engine' ); ?></th>
							<td><?php echo esc_html( isset( $last_rebuild['orders_processed'] ) ? (string) $last_rebuild['orders_processed'] : '0' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Associations written', 'commerce-intelligence-engine' ); ?></th>
							<td><?php echo esc_html( isset( $last_rebuild['associations_written'] ) ? (string) $last_rebuild['associations_written'] : '0' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Duration (seconds)', 'commerce-intelligence-engine' ); ?></th>
							<td><?php echo esc_html( isset( $last_rebuild['duration_seconds'] ) ? (string) $last_rebuild['duration_seconds'] : '0' ); ?></td>
						</tr>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No rebuild runs found yet.', 'commerce-intelligence-engine' ); ?></p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Recent runs', 'commerce-intelligence-engine' ); ?></h3>
			<?php if ( ! empty( $recent_rebuilds ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Run ID', 'commerce-intelligence-engine' ); ?></th>
							<th><?php esc_html_e( 'Mode', 'commerce-intelligence-engine' ); ?></th>
							<th><?php esc_html_e( 'Status', 'commerce-intelligence-engine' ); ?></th>
							<th><?php esc_html_e( 'Started', 'commerce-intelligence-engine' ); ?></th>
							<th><?php esc_html_e( 'Completed', 'commerce-intelligence-engine' ); ?></th>
							<th><?php esc_html_e( 'Duration (s)', 'commerce-intelligence-engine' ); ?></th>
							<th><?php esc_html_e( 'Orders', 'commerce-intelligence-engine' ); ?></th>
							<th><?php esc_html_e( 'Associations', 'commerce-intelligence-engine' ); ?></th>
							<th><?php esc_html_e( 'Error', 'commerce-intelligence-engine' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_rebuilds as $run_row ) : ?>
							<?php
							if ( ! is_array( $run_row ) ) {
								continue;
							}
							$run_id       = isset( $run_row['run_id'] ) ? (string) $run_row['run_id'] : '';
							$mode_value   = isset( $run_row['mode'] ) ? sanitize_key( (string) $run_row['mode'] ) : '';
							$status_value = isset( $run_row['status'] ) ? (string) $run_row['status'] : '';
							$started      = isset( $run_row['started_at'] ) ? (string) $run_row['started_at'] : '';
							$completed    = isset( $run_row['completed_at'] ) ? (string) $run_row['completed_at'] : '';
							$duration     = isset( $run_row['duration_seconds'] ) ? (string) absint( $run_row['duration_seconds'] ) : '0';
							$orders       = isset( $run_row['orders_processed'] ) ? (string) absint( $run_row['orders_processed'] ) : '0';
							$assoc        = isset( $run_row['associations_written'] ) ? (string) absint( $run_row['associations_written'] ) : '0';
							$error_text   = isset( $run_row['error_message'] ) ? sanitize_text_field( (string) $run_row['error_message'] ) : '';
							?>
							<tr>
								<td><code><?php echo esc_html( $run_id ); ?></code></td>
								<td><?php echo esc_html( '' !== $mode_value ? $mode_value : '—' ); ?></td>
								<td><?php echo esc_html( $status_value ); ?></td>
								<td><?php echo esc_html( '' !== $started ? $started : '—' ); ?></td>
								<td><?php echo esc_html( '' !== $completed ? $completed : '—' ); ?></td>
								<td><?php echo esc_html( $duration ); ?></td>
								<td><?php echo esc_html( $orders ); ?></td>
								<td><?php echo esc_html( $assoc ); ?></td>
								<td><?php echo esc_html( '' !== $error_text ? $error_text : '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No recent runs found.', 'commerce-intelligence-engine' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</form>
</div>
