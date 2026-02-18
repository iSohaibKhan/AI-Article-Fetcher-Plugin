<?php
/**
 * REST Endpoints – Digital Doer AI Article Fetcher
 *
 * Core routes:
 *  1) GET  /dd-ai-article-fetcher/v1/categories
 *  2) POST /dd-ai-article-fetcher/v1/categories
 *  3) POST /dd-ai-article-fetcher/v1/import
 *  4-6) WP core posts endpoints
 *  7) POST /dd-ai-article-fetcher/v1/featured/{post_id}
 *
 * Auth: Bearer token via DDAAF_Auth::rest_permission()
 */

if (!defined('ABSPATH')) {
	exit;
}

class DDAAF_REST
{

	/*--------------------------------------------------------------
	 | Singleton
	 *-------------------------------------------------------------*/
	private static $instance = null;

	public static function instance()
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/*--------------------------------------------------------------
	 | Constructor
	 *-------------------------------------------------------------*/
	private function __construct()
	{
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	/*--------------------------------------------------------------
	 | Register REST routes
	 *-------------------------------------------------------------*/
	public function register_routes()
	{

		// List categories
		register_rest_route(
			'dd-ai-article-fetcher/v1',
			'/categories',
			[
				'methods' => WP_REST_Server::READABLE,
				'permission_callback' => ['DDAAF_Auth', 'rest_permission'],
				'callback' => [$this, 'list_categories'],
			]
		);

		// Create category
		register_rest_route(
			'dd-ai-article-fetcher/v1',
			'/categories',
			[
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => ['DDAAF_Auth', 'rest_permission'],
				'callback' => [$this, 'create_category'],
				'args' => [
					'name' => ['required' => true, 'type' => 'string'],
					'slug' => ['required' => false, 'type' => 'string'],
					'parent_id' => ['required' => false, 'type' => 'integer'],
				],
			]
		);

		// Import (create post)
		register_rest_route(
			'dd-ai-article-fetcher/v1',
			'/import',
			[
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => ['DDAAF_Auth', 'rest_permission'],
				'callback' => [$this, 'import_post'],
			]
		);

		// Set / change featured image
		register_rest_route(
			'dd-ai-article-fetcher/v1',
			'/featured/(?P<post_id>\d+)',
			[
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => ['DDAAF_Auth', 'rest_permission'],
				'callback' => [$this, 'set_featured_image'],
				'args' => [
					'post_id' => ['required' => true, 'type' => 'integer'],
					'external_url' => ['required' => false, 'type' => 'string'],
					'attachment_id' => ['required' => false, 'type' => 'integer'],
				],
			]
		);
	}

	/*==============================================================
	=  Helpers
	==============================================================*/

	private function ensure_taxonomy_funcs()
	{
		if (!function_exists('wp_insert_term')) {
			require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
		}
	}

	private function ensure_category($maybe_id, $name, $slug, $parent = 0)
	{

		$maybe_id = intval($maybe_id);
		if ($maybe_id > 0 && get_term($maybe_id, 'category')) {
			return $maybe_id;
		}

		$slug = sanitize_title($slug ?: $name);
		if ($slug && ($existing = get_category_by_slug($slug))) {
			return (int) $existing->term_id;
		}

		if (!$name) {
			return (int) get_option('default_category');
		}

		$this->ensure_taxonomy_funcs();
		$res = wp_insert_term($name, 'category', ['slug' => $slug, 'parent' => $parent]);

		return is_wp_error($res)
			? (int) get_option('default_category')
			: (int) $res['term_id'];
	}

	/*==============================================================
	=  Endpoints
	==============================================================*/

	/** GET /categories */
	public function list_categories()
	{

		$cats = get_categories(['hide_empty' => false]);
		$out = [];

		foreach ($cats as $c) {
			$out[] = ['id' => (int) $c->term_id, 'slug' => $c->slug, 'name' => $c->name];
		}
		return rest_ensure_response($out);
	}

	/** POST /categories */
	public function create_category(WP_REST_Request $req)
	{

		$name = sanitize_text_field($req['name']);
		$slug = sanitize_title($req['slug'] ?: $name);
		$parent = intval($req['parent_id']);

		/* Already exists → 200 */
		if ($existing = get_category_by_slug($slug)) {
			$resp = new WP_REST_Response([
				'status' => 200,                       // added
				'id' => (int) $existing->term_id,
				'slug' => $existing->slug,
				'name' => $existing->name,
			]);
			$resp->set_status(200);
			return $resp;
		}

		$this->ensure_taxonomy_funcs();
		$res = wp_insert_term($name, 'category', ['slug' => $slug, 'parent' => $parent]);

		if (is_wp_error($res)) {
			return new WP_Error(
				'ddaaf_cannot_create_category',
				$res->get_error_message(),
				['status' => 500]
			);
		}

		/* New category → 201 */
		$resp = new WP_REST_Response([
			'status' => 201,                       // added
			'id' => (int) $res['term_id'],
			'slug' => $slug,
			'name' => $name,
		]);
		$resp->set_status(201);
		return $resp;
	}

	/** POST /import */
	public function import_post(WP_REST_Request $req)
	{

		$body = $req->get_json_params();
		$title = sanitize_text_field($body['title'] ?? '');
		$content = $body['content_html'] ?? '';

		if (empty($title) || empty($content)) {
			return new WP_Error(
				'ddaaf_missing_fields',
				'`title` and `content_html` are required.',
				['status' => 400]
			);
		}

		$cat_id = $this->ensure_category(
			$body['category_id'] ?? 0,
			$body['category_name'] ?? '',
			$body['category_slug'] ?? '',
			$body['parent_id'] ?? 0
		);

		// Prepare post data
	$post_data = [
		'post_title' => wp_strip_all_tags($title),
		'post_content' => wp_kses_post($content),
		'post_excerpt' => sanitize_text_field($body['meta_desc'] ?? ''),
		'post_status' => 'publish',
		'post_category' => [$cat_id],
	];

	// Add custom slug if provided
	if (!empty($body['post_slug'])) {
		$post_data['post_name'] = sanitize_title($body['post_slug']);
	}

	$post_id = wp_insert_post($post_data, true);

		if (is_wp_error($post_id)) {
			return new WP_Error(
				'ddaaf_insert_failed',
				$post_id->get_error_message(),
				['status' => 500]
			);
		}

		if ($body['featured_image_url'] ?? '') {
			update_post_meta($post_id, 'ddaaf_image_url', esc_url_raw($body['featured_image_url']));
		}
		if (get_option('ddaaf_use_tags', '1') === '1' && !empty($body['tags'])) {
			wp_set_post_tags($post_id, array_map('sanitize_text_field', $body['tags']), false);
		}

		/* Success → 201 */
		$resp = new WP_REST_Response([
			'status' => 201,                      // added
			'ok' => true,
			'post_id' => (int) $post_id,
			'message' => 'Published',
		]);
		$resp->set_status(201);
		return $resp;
	}

	/** POST /featured/{post_id} */
	public function set_featured_image(WP_REST_Request $req)
	{

		$post_id = intval($req['post_id']);
		$external_url = esc_url_raw($req['external_url'] ?? '');
		$attachment_id = intval($req['attachment_id'] ?? 0);

		if (!$post_id || get_post_status($post_id) === false) {
			if (!$post_id || get_post_status($post_id) === false) {
				return new WP_Error(
					'ddaaf_invalid_post',
					'Invalid post_id.',
					['status' => 400]
				);
			}

			/* ----- attachment given ----- */
			if ($attachment_id > 0) {
				set_post_thumbnail($post_id, $attachment_id);
				delete_post_meta($post_id, 'ddaaf_image_url'); // clear external if any

				$resp = new WP_REST_Response([
					'status' => 200,
					'ok' => true,
					'message' => 'Featured image set (attachment).',
				]);
				$resp->set_status(200);
				return $resp;
			}

			/* ----- external URL given ----- */
			if ($external_url) {
				delete_post_thumbnail($post_id);                     // ensure theme shows external
				update_post_meta($post_id, 'ddaaf_image_url', $external_url);

				$resp = new WP_REST_Response([
					'status' => 200,
					'ok' => true,
					'message' => 'External featured image URL saved.',
				]);
				$resp->set_status(200);
				return $resp;
			}

			return new WP_Error(
				'ddaaf_no_image_data',
				'Provide external_url or attachment_id.',
				['status' => 400]
			);
		}
	} // End class
}