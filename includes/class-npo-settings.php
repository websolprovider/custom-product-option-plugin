<?php
/**
 * A small settings screen under Product Options > Settings. Kept
 * intentionally minimal for v1 — mainly serves as a home for global
 * preferences we add in future versions.
 *
 * @package NimblixProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPO_Settings {

	const OPTION_KEY  = 'npo_settings';
	const NONCE_ACTION = 'npo_save_settings';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . NPO_Field_Group_Post_Type::POST_TYPE,
			__( 'Nimblix Product Options Settings', 'nimblix-product-options' ),
			__( 'Settings', 'nimblix-product-options' ),
			'manage_woocommerce',
			'npo-settings',
			array( $this, 'render_page' )
		);
	}

	public function get_settings() {
		$defaults = array(
			'show_price_next_to_option' => 'yes',
		);
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public function render_page() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_POST['npo_settings_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['npo_settings_nonce'] ), self::NONCE_ACTION ) ) {
			$settings = array(
				'show_price_next_to_option' => ! empty( $_POST['show_price_next_to_option'] ) ? 'yes' : 'no',
			);
			update_option( self::OPTION_KEY, $settings );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'nimblix-product-options' ) . '</p></div>';
		}

		$settings = $this->get_settings();
		?>
		<div class="wrap npo-settings-wrap">
			<h1><?php esc_html_e( 'Nimblix Product Options — Settings', 'nimblix-product-options' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, 'npo_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show price next to options', 'nimblix-product-options' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="show_price_next_to_option" value="1" <?php checked( 'yes', $settings['show_price_next_to_option'] ); ?> />
								<?php esc_html_e( 'Display the extra fee (e.g. "+ $5.00") next to each option on the product page.', 'nimblix-product-options' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
