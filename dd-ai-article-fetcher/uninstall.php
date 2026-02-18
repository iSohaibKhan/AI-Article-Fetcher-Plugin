<?php
/**
 * Fired when the plugin is *deleted* (not just deactivated).
 * Location: /dd-ai-article-fetcher/uninstall.php
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*--------------------------------------------------------------
1)  Delete plugin options
--------------------------------------------------------------*/
delete_option( 'ddaaf_api_url' );
delete_option( 'ddaaf_api_token' );
delete_option( 'ddaaf_use_tags' );

/*--------------------------------------------------------------
2)  Drop all queue tables
    –  wp_dd_queue_map     (meta)
    –  every wp_dd_queue_{catID}
    –  legacy wp_dd_requests
   ------------------------------------------------------------*/
global $wpdb;

/* meta map table */
$map_tbl = $wpdb->prefix . 'dd_queue_map';

if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $map_tbl ) ) ) {
	/* collect and drop per‑category tables */
	$tables = $wpdb->get_col( "SELECT table_name FROM {$map_tbl}" );
	foreach ( $tables as $tbl ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$tbl}" );
	}
	/* drop the map itself */
	$wpdb->query( "DROP TABLE IF EXISTS {$map_tbl}" );
}

/* legacy single‑queue table (if it still exists) */
$legacy = $wpdb->prefix . 'dd_requests';
$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" );

/*--------------------------------------------------------------
3)  (Optional) Remove external thumb meta from all posts
    Uncomment if you want a 100 % clean DB.
--------------------------------------------------------------*/
// $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'ddaaf_image_url'" );
