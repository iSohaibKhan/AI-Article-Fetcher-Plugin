<?php
/**
 * Admin UI: Fetch form, History log, Settings screen
 *
 * File: includes/class-ddaaf-admin.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DDAAF_Admin {

	/*--------------------------------------------------------------------
	 * Singleton
	 *-------------------------------------------------------------------*/
	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/*--------------------------------------------------------------------
	 * Constructor
	 *-------------------------------------------------------------------*/
	private function __construct() {
		add_action( 'admin_menu',               [ $this, 'register_menus' ] );
		add_action( 'admin_post_ddaaf_fetch',   [ $this, 'handle_fetch_submit' ] );
		add_action( 'admin_post_ddaaf_run_now', [ $this, 'handle_run_now' ] );
	}

	/*--------------------------------------------------------------------
	 * Menus
	 *-------------------------------------------------------------------*/
	public function register_menus() {

		add_menu_page(
			'AI News Hub',
			'AI News Hub',
			'manage_options',
			'ddaaf',
			[ $this, 'page_fetch' ],
			'dashicons-rss',
			65
		);

		add_submenu_page(
			'ddaaf',
			'History',
			'History',
			'manage_options',
			'ddaaf_history',
			[ $this, 'page_history' ]
		);

		add_submenu_page(
			'ddaaf',
			'Settings',
			'Settings',
			'manage_options',
			'ddaaf_settings',
			[ $this, 'page_settings' ]
		);
	}

	/*--------------------------------------------------------------------
	 * Page: Fetch Article
	 *-------------------------------------------------------------------*/
	public function page_fetch() {
		?>
		<div class="wrap">
			<h1>Fetch Article</h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ddaaf_fetch' ); ?>
				<input type="hidden" name="action" value="ddaaf_fetch" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ddaaf_url">Source URL</label></th>
						<td><input type="url" id="ddaaf_url" name="ddaaf_url" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="ddaaf_cat">Category</label></th>
						<td>
							<?php
							wp_dropdown_categories( [
								'show_option_none' => '— Select —',
								'name'             => 'ddaaf_cat',
								'id'               => 'ddaaf_cat',
								'hide_empty'       => 0,
							] );
							?>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Queue Article for Fetch' ); ?>
			</form>

			<hr>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ddaaf_run_now' ); ?>
				<input type="hidden" name="action" value="ddaaf_run_now" />
				<?php submit_button( 'Run Queue Now', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	/*--------------------------------------------------------------------
	 * Handler: Queue submit  (per‑category table insert)
	 *-------------------------------------------------------------------*/
	public function handle_fetch_submit() {

		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ddaaf_fetch' ) ) {
			wp_die( 'Permission denied.' );
		}

		global $wpdb;

		$url = esc_url_raw( $_POST['ddaaf_url'] ?? '' );
		$cat = intval( $_POST['ddaaf_cat'] ?? 0 );

		if ( empty( $url ) ) {
			wp_die( 'URL is required.' );
		}

		// ♦ NEW: one table per category
		$tbl = DDAAF_Cron::get_queue_table( $cat );

		$wpdb->insert( $tbl, [
			'url'         => $url,
			'category_id' => $cat,
			'status'      => 'pending',
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=ddaaf_history' ) );
		exit;
	}

	/*--------------------------------------------------------------------
	 * Handler: Run queue now (manual kick)
	 *-------------------------------------------------------------------*/
	public function handle_run_now() {

		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ddaaf_run_now' ) ) {
			wp_die( 'Permission denied.' );
		}

		do_action( 'ddaaf_cron_event' ); // process immediately

		wp_safe_redirect( admin_url( 'admin.php?page=ddaaf_history' ) );
		exit;
	}

	/*--------------------------------------------------------------------
	 * Page: History  (shows rows from all per‑cat tables, filterable)
	 *-------------------------------------------------------------------*/
	public function page_history() {

		global $wpdb;

		$filter_cat = isset( $_GET['ddaaf_cat_filter'] ) ? intval( $_GET['ddaaf_cat_filter'] ) : 0;
		$rows       = [];

		// Gather rows
		if ( $filter_cat ) {
			// Single category table
			$table = DDAAF_Cron::get_queue_table( $filter_cat );
			$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100" );

		} else {
			// All mapped tables + legacy
			$map_tbl = "{$wpdb->prefix}ai_queue_map";
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$map_tbl}'" ) ) {
				$tables = $wpdb->get_col( "SELECT table_name FROM {$map_tbl}" );
				foreach ( $tables as $tbl ) {
					$rows = array_merge( $rows, $wpdb->get_results(
						"SELECT * FROM {$tbl} ORDER BY id DESC LIMIT 100"
					) );
				}
			}
			// Legacy single table support
			$legacy = "{$wpdb->prefix}ai_requests";
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$legacy}'" ) ) {
				$rows = array_merge( $rows, $wpdb->get_results(
					"SELECT * FROM {$legacy} ORDER BY id DESC LIMIT 100"
				) );
			}

			// Sort all rows by created_at DESC & keep max 100
			usort( $rows, function ( $a, $b ) {
				return strcmp( $b->created_at, $a->created_at );
			} );
			$rows = array_slice( $rows, 0, 100 );
		}

		?>
		<div class="wrap">
			<h1>Fetch History</h1>

			<form method="get" style="margin-bottom:12px;">
				<input type="hidden" name="page" value="ddaaf_history" />
				<select name="ddaaf_cat_filter">
					<option value="">All Categories</option>
					<?php foreach ( get_categories() as $c ) : ?>
						<option value="<?php echo $c->term_id; ?>" <?php selected( $filter_cat, $c->term_id ); ?>>
							<?php echo esc_html( $c->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( 'Filter', 'secondary', '', false, [ 'class' => 'ddaaf-btn-inline' ] ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>URL</th>
						<th>Category</th>
						<th>Status</th>
						<th>Message</th>
						<th>Post</th>
						<th>Created</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( $rows ) : foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->id ); ?></td>
						<td class="ai-history-url"><?php echo esc_html( $row->url ); ?></td>
						<td><?php echo esc_html( get_cat_name( $row->category_id ) ); ?></td>
						<td><?php echo esc_html( ucfirst( $row->status ) ); ?></td>
						<td><?php echo esc_html( $row->message ); ?></td>
						<td>
							<?php
							if ( ! empty( $row->post_id ) ) {
								printf(
									'<a href="%s" target="_blank">View</a>',
									esc_url( get_edit_post_link( $row->post_id ) )
								);
							}
							?>
						</td>
						<td><?php echo esc_html( $row->created_at ); ?></td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="7">No records.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/*--------------------------------------------------------------------
	 * Page: Settings
	 *-------------------------------------------------------------------*/
	public function page_settings() {

		if (
			isset( $_POST['ddaaf_save_settings'] )
			&& current_user_can( 'manage_options' )
			&& check_admin_referer( 'ddaaf_save_settings' )
		) {
			update_option( 'ddaaf_api_url',   esc_url_raw( $_POST['ddaaf_api_url'] ?? '' ) );
			update_option( 'ddaaf_api_token', sanitize_text_field( $_POST['ddaaf_api_token'] ?? '' ) );
			update_option( 'ddaaf_use_tags', isset( $_POST['ddaaf_use_tags'] ) ? '1' : '0' );
			echo '<div class="updated"><p>Settings saved.</p></div>';
		}

		$api_url   = get_option( 'ddaaf_api_url', '' );
		$api_token = get_option( 'ddaaf_api_token', '' );
		?>
		<div class="wrap">
			<h1>AI Service Settings</h1>

			<form method="post">
				<?php wp_nonce_field( 'ddaaf_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ddaaf_api_url">API Base URL (pull mode)</label></th>
						<td><input type="url" id="ddaaf_api_url" name="ddaaf_api_url" value="<?php echo esc_attr( $api_url ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ddaaf_api_token">Bearer Token</label></th>
						<td><input type="text" id="ddaaf_api_token" name="ddaaf_api_token" value="<?php echo esc_attr( $api_token ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row">Use Tags?</th>
						<td>
							<label>
								<input type="checkbox" name="ddaaf_use_tags" value="1"
								<?php checked( get_option( 'ddaaf_use_tags', '1' ), '1' ); ?> />
								Enable tag assignment for new posts
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings', 'primary', 'ddaaf_save_settings' ); ?>
			</form>
		</div>
		<?php
	}
}
