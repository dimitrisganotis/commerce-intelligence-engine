<?php

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$settings      = get_option( 'cie_settings', array() );
$delete_data   = false;

if ( is_array( $settings ) && isset( $settings['uninstall_delete_data'] ) ) {
	$delete_data = (bool) $settings['uninstall_delete_data'];
}

if ( ! $delete_data ) {
	return;
}

$tables = array(
	$wpdb->prefix . 'ci_associations',
	$wpdb->prefix . 'ci_associations_temp',
	$wpdb->prefix . 'ci_pair_counts',
	$wpdb->prefix . 'ci_rebuild_log',
	$wpdb->prefix . 'ci_overrides',
);

foreach ( $tables as $table_name ) {
	$table_name = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table_name );
	if ( '' === $table_name ) {
		continue;
	}

	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
}

$option_like = $wpdb->esc_like( 'cie_' ) . '%';
$query       = $wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
	$option_like
);

$wpdb->query( $query );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', null, 'commerce-intelligence-engine' );
}
