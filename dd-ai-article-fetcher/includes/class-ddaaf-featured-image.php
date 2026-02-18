<?php
/**
 * External Featured Image (FIFU-style) helper
 *
 * File: includes/class-ddaaf-featured-image.php
 *
 * Stores image URL in post meta key `ddaaf_image_url` and, when no native
 * WP thumbnail is set, injects an <img> tag on front‑end and shows a tiny
 * preview in the Posts list table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DDAAF_Featured_Image {

	/** @var self|null */
	private static $instance = null;

	/** Meta key for external image URL */
	const META_KEY = 'ddaaf_image_url';

	/**
	 * Singleton
	 */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor – attach filters
	 */
	private function __construct() {

		// Front-end / editor: inject external thumb if no _thumbnail_id
		add_filter( 'post_thumbnail_html', [ $this, 'inject_external_thumb' ], 10, 5 );

		// Admin list column preview
		add_filter( 'manage_posts_columns',        [ $this, 'add_admin_thumb_column' ] );
		add_action( 'manage_posts_custom_column',  [ $this, 'render_admin_thumb_column' ], 10, 2 );
	}

	/**
	 * Provide <img> tag when featured image ID is missing but URL meta exists
	 *
	 * @param string $html
	 * @param int    $post_id
	 * @param int    $thumb_id
	 * @param string $size
	 * @param array  $attr
	 *
	 * @return string
	 */
	public function inject_external_thumb( $html, $post_id, $thumb_id, $size, $attr ) {

		// If WordPress has already generated HTML, keep it
		if ( ! empty( $html ) ) {
			return $html;
		}

		$url = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! $url ) {
			return $html;
		}

		$alt = isset( $attr['alt'] ) ? $attr['alt'] : get_the_title( $post_id );

		return sprintf(
			'<img src="%s" alt="%s" class="ddaaf-external-thumb wp-post-image" />',
			esc_url( $url ),
			esc_attr( $alt )
		);
	}

	/**
	 * Add custom column in posts list
	 */
	public function add_admin_thumb_column( $cols ) {
		// Insert after checkbox
		$new = [];
		$inserted = false;
		foreach ( $cols as $key => $label ) {
			$new[ $key ] = $label;
			if ( ! $inserted && 'cb' === $key ) {
				$new['ddaaf_thumb'] = 'Ext Thumb';
				$inserted = true;
			}
		}
		if ( ! $inserted ) {
			$new['ddaaf_thumb'] = 'Ext Thumb';
		}
		return $new;
	}

	/**
	 * Render thumbnail in admin column
	 */
	public function render_admin_thumb_column( $column, $post_id ) {

		if ( 'ddaaf_thumb' !== $column ) {
			return;
		}

		// Prefer native thumb if exists
		if ( has_post_thumbnail( $post_id ) ) {
			echo get_the_post_thumbnail( $post_id, [ 45, 45 ] );
			return;
		}

		$url = get_post_meta( $post_id, self::META_KEY, true );
		if ( $url ) {
			echo '<img src="' . esc_url( $url ) . '" style="height:45px;width:auto;border:1px solid #ddd;padding:1px;" />';
		} else {
			echo '—';
		}
	}
}
