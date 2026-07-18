<?php
/**
 * Adds the "Conditions" metabox to the Field Group edit screen: a set of
 * AND/OR rule groups that determine which products the group appears on
 * (e.g. Product Is equal to "Soccer Skills"). No rules configured means
 * the group applies to every product.
 *
 * @package NimblixProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPO_Assignment {

	const NONCE_ACTION = 'npo_save_conditions';
	const NONCE_NAME   = 'npo_conditions_nonce';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'save_post_' . NPO_Field_Group_Post_Type::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_metabox() {
		add_meta_box(
			'npo_conditions_metabox',
			__( 'Conditions', 'nimblix-product-options' ),
			array( $this, 'render' ),
			NPO_Field_Group_Post_Type::POST_TYPE,
			'normal',
			'default'
		);
	}

	public function enqueue_assets( $hook ) {
		global $post_type;

		if ( NPO_Field_Group_Post_Type::POST_TYPE !== $post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'select2' );

		wp_enqueue_script(
			'npo-admin-conditions',
			NPO_PLUGIN_URL . 'assets/js/admin-conditions.js',
			array( 'jquery' ),
			npo_asset_version( 'assets/js/admin-conditions.js' ),
			true
		);

		wp_localize_script(
			'npo-admin-conditions',
			'NPO_Conditions',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'productSearchNonce' => wp_create_nonce( 'search-products' ),
				'i18n'             => array(
					'product'      => __( 'Product', 'nimblix-product-options' ),
					'equal'        => __( 'Is equal to', 'nimblix-product-options' ),
					'notEqual'     => __( 'Is not equal to', 'nimblix-product-options' ),
					'and'          => __( 'And', 'nimblix-product-options' ),
					'or'           => __( 'Or', 'nimblix-product-options' ),
					'addRuleGroup' => __( 'Add new rule group', 'nimblix-product-options' ),
					'searchProducts' => __( 'Search for a product&hellip;', 'nimblix-product-options' ),
				),
			)
		);
	}

	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$conditions = get_post_meta( $post->ID, '_npo_conditions', true );
		$conditions = is_array( $conditions ) && ! empty( $conditions['groups'] ) ? $conditions : array( 'groups' => array() );
		?>
		<p><strong><?php esc_html_e( 'When should this field group be displayed?', 'nimblix-product-options' ); ?></strong></p>
		<h4><?php esc_html_e( 'Rules', 'nimblix-product-options' ); ?></h4>
		<p class="description">
			<?php esc_html_e( 'Add a set of rules to determine when this field group should appear. Leave empty to show it on every product.', 'nimblix-product-options' ); ?>
		</p>

		<div class="npo-conditions-editor">
			<textarea name="npo_conditions_json" class="npo-conditions-json-store" style="display:none;"><?php echo esc_textarea( wp_json_encode( $conditions ) ); ?></textarea>

			<div class="npo-conditions-groups"></div>

			<p>
				<button type="button" class="button button-secondary npo-add-condition-group">
					<?php esc_html_e( '+ Add new rule group', 'nimblix-product-options' ); ?>
				</button>
			</p>
		</div>

		<script type="text/template" id="npo-existing-products-data">
			<?php
			$product_ids = array();
			foreach ( $conditions['groups'] as $group ) {
				foreach ( $group['rules'] as $rule ) {
					if ( ! empty( $rule['value'] ) ) {
						$product_ids[] = (int) $rule['value'];
					}
				}
			}
			$product_ids = array_unique( $product_ids );
			$labels      = array();
			foreach ( $product_ids as $pid ) {
				$p = wc_get_product( $pid );
				if ( $p ) {
					$labels[ $pid ] = $p->get_formatted_name();
				}
			}
			echo wp_json_encode( $labels );
			?>
		</script>
		<?php
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

		$conditions = array( 'groups' => array() );

		if ( isset( $_POST['npo_conditions_json'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['npo_conditions_json'] ), true );

			if ( ! empty( $decoded['groups'] ) && is_array( $decoded['groups'] ) ) {
				foreach ( $decoded['groups'] as $group ) {
					if ( empty( $group['rules'] ) || ! is_array( $group['rules'] ) ) {
						continue;
					}
					$clean_rules = array();
					foreach ( $group['rules'] as $rule ) {
						if ( empty( $rule['value'] ) ) {
							continue;
						}
						$clean_rules[] = array(
							'type'     => 'product',
							'operator' => ( isset( $rule['operator'] ) && 'not_equal' === $rule['operator'] ) ? 'not_equal' : 'equal',
							'value'    => absint( $rule['value'] ),
						);
					}
					if ( $clean_rules ) {
						$conditions['groups'][] = array( 'rules' => $clean_rules );
					}
				}
			}
		}

		update_post_meta( $post_id, '_npo_conditions', $conditions );
	}

	/**
	 * Get all published field-group post IDs that apply to a given product.
	 *
	 * @param int $product_id Product (or variation parent) ID.
	 *
	 * @return int[]
	 */
	public static function get_group_ids_for_product( $product_id ) {

		$cache_key = 'npo_groups_for_product_' . $product_id;
		$cached    = wp_cache_get( $cache_key, 'npo' );
		if ( false !== $cached ) {
			return $cached;
		}

		$all_groups = get_posts(
			array(
				'post_type'      => NPO_Field_Group_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$matching = array();

		foreach ( $all_groups as $group_id ) {

			$conditions = get_post_meta( $group_id, '_npo_conditions', true );
			$groups     = ( is_array( $conditions ) && ! empty( $conditions['groups'] ) ) ? $conditions['groups'] : array();

			if ( ! $groups || self::conditions_match( $groups, $product_id ) ) {
				$matching[] = $group_id;
			}
		}

		wp_cache_set( $cache_key, $matching, 'npo', HOUR_IN_SECONDS );

		return $matching;
	}

	/**
	 * Rule groups are OR'd together; rules within a group are AND'd.
	 *
	 * @param array $groups     Condition rule groups.
	 * @param int   $product_id Product ID being evaluated.
	 *
	 * @return bool
	 */
	private static function conditions_match( $groups, $product_id ) {

		foreach ( $groups as $group ) {

			if ( empty( $group['rules'] ) ) {
				continue;
			}

			$group_matches = true;

			foreach ( $group['rules'] as $rule ) {

				if ( 'product' !== ( $rule['type'] ?? 'product' ) ) {
					continue;
				}

				$is_match = (int) $product_id === (int) $rule['value'];
				$rule_ok  = 'not_equal' === $rule['operator'] ? ! $is_match : $is_match;

				if ( ! $rule_ok ) {
					$group_matches = false;
					break;
				}
			}

			if ( $group_matches ) {
				return true;
			}
		}

		return false;
	}
}