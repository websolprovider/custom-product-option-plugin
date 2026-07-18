<?php
/**
 * Adds the "Fields" metabox to the npo_field_group edit screen and
 * handles saving it.
 *
 * @package NimblixProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPO_Admin_Metabox {

	const NONCE_ACTION = 'npo_save_fields';
	const NONCE_NAME   = 'npo_fields_nonce';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metaboxes' ) );
		add_action( 'save_post_' . NPO_Field_Group_Post_Type::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'manage_' . NPO_Field_Group_Post_Type::POST_TYPE . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . NPO_Field_Group_Post_Type::POST_TYPE . '_posts_custom_column', array( $this, 'render_columns' ), 10, 2 );
	}

	public function register_metaboxes() {
		add_meta_box(
			'npo_fields_metabox',
			__( 'Fields', 'nimblix-product-options' ),
			array( $this, 'render_fields_metabox' ),
			NPO_Field_Group_Post_Type::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function enqueue_assets( $hook ) {
		global $post_type;

		$is_group_screen   = ( NPO_Field_Group_Post_Type::POST_TYPE === $post_type ) && in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		$is_product_screen = ( 'product' === $post_type ) && in_array( $hook, array( 'post.php', 'post-new.php' ), true );

		if ( ! $is_group_screen && ! $is_product_screen ) {
			return;
		}

		wp_enqueue_style(
			'npo-admin',
			NPO_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			npo_asset_version( 'assets/css/admin.css' )
		);

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_script(
			'npo-admin-fields-editor',
			NPO_PLUGIN_URL . 'assets/js/admin-fields-editor.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			npo_asset_version( 'assets/js/admin-fields-editor.js' ),
			true
		);

		wp_localize_script(
			'npo-admin-fields-editor',
			'NPO_Admin',
			array(
				'pricingTypes' => NPO_Pricing::get_pricing_types(),
				'i18n'         => array(
					'checkboxField'   => __( 'Checkboxes', 'nimblix-product-options' ),
					'radioField'      => __( 'Radio Buttons', 'nimblix-product-options' ),
					'newFieldLabel'   => __( 'New Field', 'nimblix-product-options' ),
					'removeField'     => __( 'Remove this field?', 'nimblix-product-options' ),
					'addOption'       => __( 'Add option', 'nimblix-product-options' ),
					'addRuleGroup'    => __( 'Add new rule group', 'nimblix-product-options' ),
					'and'             => __( 'And', 'nimblix-product-options' ),
					'or'              => __( 'Or', 'nimblix-product-options' ),
					'equal'           => __( 'Value is equal to', 'nimblix-product-options' ),
					'notEqual'        => __( 'Value is not equal to', 'nimblix-product-options' ),
				),
			)
		);
	}

	public function render_fields_metabox( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$fields = get_post_meta( $post->ID, '_npo_fields', true );
		$fields = is_array( $fields ) ? $fields : array();

		echo '<p class="description">' . esc_html__( 'Build the fields that should appear on the product page. Attach this group to products using the Assignment box.', 'nimblix-product-options' ) . '</p>';

		NPO_Field_Renderer::render_admin_editor( 'npo_fields_json', $fields );
	}

	public function save( $post_id, $post ) {

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['npo_fields_json'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['npo_fields_json'] ), true );
			$clean   = NPO_Field_Renderer::sanitize_fields( $decoded );
			update_post_meta( $post_id, '_npo_fields', $clean );
		}
	}

	public function add_columns( $columns ) {
		$columns['npo_field_count'] = __( 'Fields', 'nimblix-product-options' );
		$columns['npo_assignment']  = __( 'Assigned To', 'nimblix-product-options' );
		return $columns;
	}

	public function render_columns( $column, $post_id ) {
		if ( 'npo_field_count' === $column ) {
			$fields = get_post_meta( $post_id, '_npo_fields', true );
			echo esc_html( is_array( $fields ) ? count( $fields ) : 0 );
		}

		if ( 'npo_assignment' === $column ) {
			$conditions = get_post_meta( $post_id, '_npo_conditions', true );
			$groups     = ( is_array( $conditions ) && ! empty( $conditions['groups'] ) ) ? $conditions['groups'] : array();

			if ( $groups ) {
				$rule_count = 0;
				foreach ( $groups as $group ) {
					$rule_count += count( $group['rules'] ?? array() );
				}
				/* translators: %d: number of conditional rules */
				printf( esc_html( _n( '%d condition rule', '%d condition rules', $rule_count, 'nimblix-product-options' ) ), (int) $rule_count );
			} else {
				esc_html_e( 'All products', 'nimblix-product-options' );
			}
		}
	}
}