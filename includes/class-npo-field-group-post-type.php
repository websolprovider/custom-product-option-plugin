<?php
/**
 * Registers the "npo_field_group" custom post type used to store
 * re-usable global field groups (a set of fields that can be attached
 * to one or many products).
 *
 * @package NimblixProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPO_Field_Group_Post_Type {

	const POST_TYPE = 'npo_field_group';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Register the post type. Static so it can also be called directly
	 * from the activation hook (before 'init' has necessarily fired).
	 */
	public static function register_post_type() {

		$labels = array(
			'name'               => __( 'Product Option Groups', 'nimblix-product-options' ),
			'singular_name'      => __( 'Product Option Group', 'nimblix-product-options' ),
			'add_new'            => __( 'Add New', 'nimblix-product-options' ),
			'add_new_item'       => __( 'Add New Option Group', 'nimblix-product-options' ),
			'edit_item'          => __( 'Edit Option Group', 'nimblix-product-options' ),
			'new_item'           => __( 'New Option Group', 'nimblix-product-options' ),
			'view_item'          => __( 'View Option Group', 'nimblix-product-options' ),
			'search_items'       => __( 'Search Option Groups', 'nimblix-product-options' ),
			'not_found'          => __( 'No option groups found', 'nimblix-product-options' ),
			'not_found_in_trash' => __( 'No option groups found in Trash', 'nimblix-product-options' ),
			'all_items'          => __( 'Option Groups', 'nimblix-product-options' ),
			'menu_name'          => __( 'Product Options', 'nimblix-product-options' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'woocommerce',
			'show_in_admin_bar'   => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'supports'            => array( 'title' ),
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}
}
