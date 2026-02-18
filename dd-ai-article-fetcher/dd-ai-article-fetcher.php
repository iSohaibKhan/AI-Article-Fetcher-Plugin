<?php
/*
Plugin Name: Digital Doer AI Article Fetcher
Plugin URI:  https://digitaldoer.com/downloads/dd-ai-article-fetcher
Description: Bridge WordPress with an external AI micro-service to fetch, rewrite and publish articles. Includes REST endpoints (/import, /categories), external featured image support (FIFU-style), optional custom post slugs, and optional queue/cron pull mode.
Version:     1.1
Author:      Sohaib S. Khan
Author URI:  https://isohaibkhan.github.io
License:     GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: dd-ai-article-fetcher
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * --------------------------------------------------------------------------
 * Constants
 * --------------------------------------------------------------------------
 */
define( 'DDAAF_VERSION',           '1.1' );
define( 'DDAAF_PLUGIN_FILE',       __FILE__ );
define( 'DDAAF_PLUGIN_BASENAME',   plugin_basename( __FILE__ ) );
define( 'DDAAF_DIR',               plugin_dir_path( __FILE__ ) );
define( 'DDAAF_URL',               plugin_dir_url( __FILE__ ) );

/**
 * --------------------------------------------------------------------------
 * Bootstrap Loader
 * --------------------------------------------------------------------------
 * Loader creates DB table, schedules cron, and boots all classes
 * (Admin, REST, Cron, Featured Image, Auth, etc.).
 */
require_once DDAAF_DIR . 'includes/class-ddaaf-loader.php';

/**
 * Activation / Deactivation Hooks
 */
register_activation_hook(   __FILE__, [ 'DDAAF_Loader', 'activate'   ] );
register_deactivation_hook( __FILE__, [ 'DDAAF_Loader', 'deactivate' ] );

/**
 * Kick off the plugin
 */
DDAAF_Loader::instance();