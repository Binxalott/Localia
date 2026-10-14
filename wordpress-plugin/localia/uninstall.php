<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Data is only removed if "Delete all Localia data" was ticked in Settings.
 *
 * @package Localia
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'localia_tick' );
wp_clear_scheduled_hook( 'localia_maintenance' );

$localia_settings = get_option( 'localia_settings', array() );
if ( empty( $localia_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'jobs', 'nonces', 'tasks', 'log' ) as $localia_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'localia_' . $localia_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing our own tables.
}
foreach ( array( 'localia_settings', 'localia_agent_status', 'localia_db_version', 'localia_last_tick' ) as $localia_option ) {
	delete_option( $localia_option );
}
delete_transient( 'localia_tick_lock' );
foreach ( array( '_localia_job', '_localia_task', '_localia_rewritten_at', '_localia_rewrite_of' ) as $localia_key ) {
	delete_post_meta_by_key( $localia_key );
}
