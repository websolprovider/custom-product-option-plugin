<?php
/**
 * Plugin Name:       Nimblix Product Options for WooCommerce
 * Plugin URI:        https://malikirfanulhaq.vercel.app/
 * Description:       Add custom option fields (checkboxes, radio buttons) to your WooCommerce products with conditional logic and flexible pricing rules.
 * Version:           1.0.2
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            NimblixSolutions
 * Author URI:        https://malikirfanulhaq.vercel.app/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nimblix-product-options
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 *
 * @package NimblixProductOptions
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin constants.
 */
define( 'NPO_VERSION', '1.0.2' );
define( 'NPO_PLUGIN_FILE', __FILE__ );
define( 'NPO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NPO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NPO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Version an asset by its file modification time instead of the static
 * plugin version. This means browsers only re-fetch a CSS/JS file after it
 * actually changes on disk, rather than caching a stale copy across every
 * edit during development (or serving a fresh copy on every single request,
 * which a raw time() based version would do).
 *
 * @param string $relative_path Path relative to the plugin root, e.g. 'assets/css/admin.css'.
 *
 * @return string
 */
function npo_asset_version( $relative_path ) {
	$file = NPO_PLUGIN_DIR . ltrim( $relative_path, '/' );
	return file_exists( $file ) ? (string) filemtime( $file ) : NPO_VERSION;
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
 * and the Cart & Checkout blocks, before WooCommerce finishes loading.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				NPO_PLUGIN_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				NPO_PLUGIN_FILE,
				true
			);
		}
	}
);

/**
 * Bail early (with an admin notice) if WooCommerce is not active.
 * We check on 'plugins_loaded' so WooCommerce has had a chance to load first.
 */
function npo_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

function npo_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'Nimblix Product Options for WooCommerce requires WooCommerce to be installed and active.',
				'nimblix-product-options'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Main bootstrap, runs once WooCommerce (and all other plugins) are loaded.
 */
function npo_init_plugin() {

	if ( ! npo_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'npo_missing_woocommerce_notice' );
		return;
	}

	require_once NPO_PLUGIN_DIR . 'includes/class-npo-field-group-post-type.php';
	require_once NPO_PLUGIN_DIR . 'includes/class-npo-formula-evaluator.php';
	require_once NPO_PLUGIN_DIR . 'includes/class-npo-pricing.php';
	require_once NPO_PLUGIN_DIR . 'includes/class-npo-field-renderer.php';
	require_once NPO_PLUGIN_DIR . 'includes/class-npo-admin-metabox.php';
	require_once NPO_PLUGIN_DIR . 'includes/class-npo-product-metabox.php';
	require_once NPO_PLUGIN_DIR . 'includes/class-npo-assignment.php';
	require_once NPO_PLUGIN_DIR . 'includes/class-npo-frontend.php';
	require_once NPO_PLUGIN_DIR . 'includes/class-npo-cart.php';
	require_once NPO_PLUGIN_DIR . 'includes/class-npo-order.php';
	require_once NPO_PLUGIN_DIR . 'includes/class-npo-settings.php';

	// Boot each module. Each class wires up its own hooks in its constructor.
	NPO_Field_Group_Post_Type::instance();
	NPO_Admin_Metabox::instance();
	NPO_Product_Metabox::instance();
	NPO_Assignment::instance();
	NPO_Frontend::instance();
	NPO_Cart::instance();
	NPO_Order::instance();
	NPO_Settings::instance();
}
add_action( 'plugins_loaded', 'npo_init_plugin' );

/**
 * Load translations.
 */
function npo_load_textdomain() {
	load_plugin_textdomain( 'nimblix-product-options', false, dirname( NPO_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'init', 'npo_load_textdomain' );

/**
 * Activation hook — nothing destructive, just flush rewrite rules so the
 * "npo_field_group" post type's admin screens work immediately.
 */
function npo_activate_plugin() {
	if ( ! class_exists( 'NPO_Field_Group_Post_Type' ) ) {
		require_once NPO_PLUGIN_DIR . 'includes/class-npo-field-group-post-type.php';
	}
	NPO_Field_Group_Post_Type::register_post_type();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'npo_activate_plugin' );

/**
 * Deactivation hook.
 */
function npo_deactivate_plugin() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'npo_deactivate_plugin' );