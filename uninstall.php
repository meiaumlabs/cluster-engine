<?php
/**
 * Uninstall — remove Cluster Engine tables and options.
 *
 * @package ClusterEngine
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ce_index" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ce_clusters" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ce_relations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ce_queue" );

wp_clear_scheduled_hook( 'ce61_queue_tick' );

delete_option( 'ce61_settings' );
delete_option( 'ce61_prompts' );
delete_option( 'ce61_last_scan' );
delete_option( 'ce61_redirects' );
delete_option( 'ce61_db_version' );
