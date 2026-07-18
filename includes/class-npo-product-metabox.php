<?php
/**
 * Adds a "Product Options" metabox to the individual product edit screen,
 * letting a store owner add fields unique to just that product (in
 * addition to whatever global field groups are assigned to it).
 *
 * @package NimblixProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPO_Product_Metabox {

	const NONCE_ACTION = 'npo_save_local_fields';
	const NONCE_NAME   = 'npo_local_fields_nonce';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'save_post_product', array( $this, 'save' ), 10, 2 );
	}

	public function register_metabox() {
		add_meta_box(
			'npo_local_fields_metabox',
			__( 'Product Options (Nimblix)', 'nimblix-product-options' ),
			array( $this, 'render' ),
			'product',
			'normal',
			'default'
		);
	}

	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$fields = get_post_meta( $post->ID, '_npo_local_fields', true );
		$fields = is_array( $fields ) ? $fields : array();

		$assigned_group_ids = NPO_Assignment::get_group_ids_for_product( $post->ID );
		?>
		<p class="description">
			<?php esc_html_e( 'Fields added here appear only on this product, in addition to any global option groups assigned to it.', 'nimblix-product-options' ); ?>
		</p>

		<?php if ( $assigned_group_ids ) : ?>
			<p class="npo-assigned-groups-note">
				<strong><?php esc_html_e( 'Global option groups applied to this product:', 'nimblix-product-options' ); ?></strong>
				<?php
				$titles = array_map(
					function ( $id ) {
						return get_the_title( $id );
					},
					$assigned_group_ids
				);
				echo esc_html( implode( ', ', $titles ) );
				?>
				&mdash;
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . NPO_Field_Group_Post_Type::POST_TYPE ) ); ?>">
					<?php esc_html_e( 'Manage option groups', 'nimblix-product-options' ); ?>
				</a>
			</p>
		<?php endif; ?>

		<?php NPO_Field_Renderer::render_admin_editor( 'npo_local_fields_json', $fields ); ?>
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

		if ( isset( $_POST['npo_local_fields_json'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['npo_local_fields_json'] ), true );
			$clean   = NPO_Field_Renderer::sanitize_fields( $decoded );
			update_post_meta( $post_id, '_npo_local_fields', $clean );
		}
	}
}
