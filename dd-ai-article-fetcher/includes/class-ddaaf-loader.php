<?php
/**
 * Core Loader – boots everything
 *
 * File: includes/class-ddaaf-loader.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}



class DDAAF_Loader {

	/** @var self|null */
	private static $instance = null;

	/** Cron hook name */
	const CRON_HOOK = 'ddaaf_cron_event';

	/** Legacy single‑queue table name (still created for safety) */
	private static $table_name;

	/*--------------------------------------------------------------------
	 * Singleton
	 *-------------------------------------------------------------------*/
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/*--------------------------------------------------------------------
	 * Constructor – include classes, attach cron
	 *-------------------------------------------------------------------*/
	private function __construct() {

		global $wpdb;
		self::$table_name = "{$wpdb->prefix}dd_requests"; // legacy

		// Include feature classes
		require_once DDAAF_DIR . 'includes/class-ddaaf-admin.php';
		require_once DDAAF_DIR . 'includes/class-ddaaf-rest.php';
		require_once DDAAF_DIR . 'includes/class-ddaaf-cron.php';
		require_once DDAAF_DIR . 'includes/class-ddaaf-featured-image.php';
		require_once DDAAF_DIR . 'includes/class-ddaaf-auth.php';

		// Init
		if ( is_admin() ) {
			DDAAF_Admin::instance();
		}
		DDAAF_REST::instance();
		/* DDAAF_Cron has only static methods, no need to instantiate */

		DDAAF_Featured_Image::instance();

		// Hook cron processor
		add_action( self::CRON_HOOK, [ 'DDAAF_Cron', 'process_queue' ] );
	}

	/*--------------------------------------------------------------------
	 * Activation – create meta‑table (+legacy), schedule cron
	 *-------------------------------------------------------------------*/
	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		/* 1)  Meta‑table: category_id ↔ table_name */
		$map_tbl = "{$wpdb->prefix}dd_queue_map";
		$sql  = "
		CREATE TABLE IF NOT EXISTS {$map_tbl} (
			category_id BIGINT UNSIGNED PRIMARY KEY,
			table_name  VARCHAR(64) NOT NULL
		) {$charset};
		";

		/* 2)  Legacy single queue (optional fallback) */
		$legacy_tbl = "{$wpdb->prefix}dd_requests";
		$sql .= "
		CREATE TABLE IF NOT EXISTS {$legacy_tbl} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url          TEXT,
			category_id  BIGINT UNSIGNED,
			status       VARCHAR(20) DEFAULT 'pending',
			message      TEXT,
			post_id      BIGINT UNSIGNED,
			created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
			finished_at  DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset};
		";

		dbDelta( $sql );

		// Hourly cron schedule
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/*--------------------------------------------------------------------
	 * Deactivation – clear cron (data kept)
	 *-------------------------------------------------------------------*/
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		// Tables remain; uninstall.php handles clean‑up if user truly deletes plugin.
	}
}
