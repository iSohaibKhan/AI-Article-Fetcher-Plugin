<?php
/**
 * Queue Processor – Digital Doer AI Article Fetcher
 *
 * 1.  `wp_dd_queue_map`  ↔  category_id → table_name
 * 2.  Each queue table = `wp_dd_queue_{catID}`
 * 3.  Cron loops through every mapped table and processes 10 pending rows.
 *
 * NOTE:
 *  – Admin / REST “add to queue” calls MUST use
 *      DDAAF_Cron::get_queue_table( $cat_id ) to insert rows.
 *  – If an old monolithic table `wp_dd_requests` still exists, we ALSO
 *      process it for backward compatibility (no data lost).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DDAAF_Cron {

	/*--------------------------------------------------------------------
	* Public helpers
	*-------------------------------------------------------------------*/
	/**
	 * Return per‑category queue table; create if missing.
	 */
	public static function get_queue_table( $cat_id ) {
		global $wpdb;

		$cat_id   = intval( $cat_id );
		$map_tbl  = "{$wpdb->prefix}dd_queue_map";
		$queue_tbl = $wpdb->get_var( $wpdb->prepare(
			"SELECT table_name FROM {$map_tbl} WHERE category_id = %d", $cat_id
		) );

		if ( $queue_tbl ) {
			return $queue_tbl;
		}

		// Create new table
		$queue_tbl = $wpdb->prefix . 'dd_queue_' . $cat_id;
		$charset   = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "
		CREATE TABLE {$queue_tbl} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url          TEXT,
			status       VARCHAR(20) DEFAULT 'pending',
			message      TEXT,
			post_id      BIGINT UNSIGNED,
			category_id  BIGINT UNSIGNED,
			created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
			finished_at  DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset};
		";
		dbDelta( $sql );

		// Save map
		$wpdb->insert( $map_tbl, [
			'category_id' => $cat_id,
			'table_name'  => $queue_tbl,
		] );

		return $queue_tbl;
	}

	/*--------------------------------------------------------------------
	* Cron entry – hooked to ddaaf_cron_event
	*-------------------------------------------------------------------*/
	public static function process_queue() {
		global $wpdb;

		/* ---- 1.  Per‑category tables ---- */
		$map_tbl = "{$wpdb->prefix}dd_queue_map";
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$map_tbl}'" ) ) {
			$rows = $wpdb->get_results( "SELECT table_name FROM {$map_tbl}" );
			foreach ( $rows as $r ) {
				self::process_table( $r->table_name );
			}
		}

		/* ---- 2.  Legacy single table (wp_dd_requests) ---- */
		$legacy = "{$wpdb->prefix}dd_requests";
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$legacy}'" ) ) {
			self::process_table( $legacy );
		}
	}

	/*--------------------------------------------------------------------
	* Process N pending rows from a given queue table
	*-------------------------------------------------------------------*/
	private static function process_table( $table_name ) {
		global $wpdb;

		$jobs = $wpdb->get_results(
			"SELECT * FROM {$table_name} WHERE status='pending' ORDER BY id ASC LIMIT 10"
		);
		foreach ( $jobs as $job ) {
			self::process_single( $table_name, $job );
		}
	}

	/*--------------------------------------------------------------------
	* Process one job
	*-------------------------------------------------------------------*/
	private static function process_single( $table_name, $job ) {
		global $wpdb;

		$api_base = rtrim( get_option( 'ddaaf_api_url', '' ), '/' );
		$token    = get_option( 'ddaaf_api_token', '' );

		if ( empty( $api_base ) ) {
			self::mark_error( $table_name, $job->id, 'API Base URL missing.' );
			return;
		}

		/* ---- Build request payload ---- */
		$endpoint = $api_base . '/generate_post';
		$payload  = [
			'url'           => $job->url,
			'category_id'   => (int) $job->category_id,
			'category_name' => get_cat_name( $job->category_id ),
		];

		$response = wp_remote_post(
			$endpoint,
			[
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			self::mark_error( $table_name, $job->id, $response->get_error_message() );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || empty( $body['ok'] ) ) {
			self::mark_error( $table_name, $job->id, $body['message'] ?? 'HTTP ' . $code );
			return;
		}

		/* ---- Map fields ---- */
		$post_id = self::publish_post(
			$body['title']              ?? '',
			$body['content_html']       ?? '',
			$body['meta_desc']          ?? '',
			(int) $job->category_id,
			$body['tags']               ?? [],
			$body['featured_image_url'] ?? ''
		);

		$wpdb->update(
			$table_name,
			[
				'status'      => 'success',
				'post_id'     => $post_id,
				'finished_at' => current_time( 'mysql' ),
			],
			[ 'id' => $job->id ]
		);
	}

	/*--------------------------------------------------------------------
	* Publish post helper
	*-------------------------------------------------------------------*/
	private static function publish_post( $title, $content_html, $excerpt, $cat_id, $tags, $image_url ) {

		$post_id = wp_insert_post(
			[
				'post_title'    => wp_strip_all_tags( $title ),
				'post_content'  => wp_kses_post( $content_html ),
				'post_excerpt'  => $excerpt,
				'post_status'   => 'publish',
				'post_category' => [ $cat_id ],
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// External thumb
		if ( $image_url ) {
			update_post_meta( $post_id, 'ddaaf_image_url', esc_url_raw( $image_url ) );
		}

		if ( $tags && get_option( 'ddaaf_use_tags', '1' ) === '1' ) {
			wp_set_post_tags( $post_id, $tags, false );
		}
	}

	/*--------------------------------------------------------------------
	* Mark error helper
	*-------------------------------------------------------------------*/
	private static function mark_error( $table, $row_id, $msg ) {
		global $wpdb;
		$wpdb->update(
			$table,
			[
				'status'      => 'error',
				'message'     => $msg,
				'finished_at' => current_time( 'mysql' ),
			],
			[ 'id' => $row_id ]
		);
	}
}
