=== Nimblix Product Options for WooCommerce ===
Contributors: nimblixsolutions
Tags: woocommerce, product options, product add-ons, conditional logic, custom fields
Requires at least: 6.3
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 9.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add checkbox and radio button option fields to your WooCommerce products, with flexible pricing rules and conditional logic.

== Description ==

Nimblix Product Options for WooCommerce lets you add extra option fields to your product pages so customers can customize what they're ordering before adding it to the cart.

**Features**

* Checkbox fields (select multiple) and radio button fields (select one)
* Three pricing modes per option: no change, flat fee (one-time), percentage of the product price, or a quantity-based flat fee that scales with the quantity ordered
* Minimum / maximum choices allowed on checkbox fields
* "Quantity based" fields that repeat once per unit of quantity ordered, so each unit can get its own selection
* Conditional logic: show or hide a field based on the value of another field, with AND / OR rule groups
* Reusable **global field groups** that can be attached to all products or specific products
* **Per-product fields** for options unique to a single product
* Selections and their price impact flow through to the cart, checkout, and order — visible to both the customer and the store admin
* Built for WooCommerce **High-Performance Order Storage (HPOS)** and the block-based Cart & Checkout

= Getting started =

1. Go to **WooCommerce → Product Options** to create a global field group, or
2. Open any product and use the **Product Options (Nimblix)** box to add fields just for that product.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/nimblix-product-options-for-woocommerce` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Make sure WooCommerce is installed and active.
4. Go to **WooCommerce → Product Options** to get started.

== Frequently Asked Questions ==

= Does this work with HPOS (High-Performance Order Storage)? =

Yes. Compatibility is declared explicitly and all order data is read/written through WooCommerce's CRUD methods rather than direct post meta calls.

= Can I attach the same set of fields to many products at once? =

Yes — create a "Product Option Group" and set its Assignment to either "All products" or a specific list of products.

= Can fields be different per product? =

Yes — each product has its own "Product Options (Nimblix)" box for fields that only apply to that product, in addition to any global groups assigned to it.

== Changelog ==

= 1.0.0 =
* Initial release: checkbox and radio fields, flat/percentage/quantity-based pricing, min/max choices, quantity-based field repetition, conditional logic, global groups + per-product fields, HPOS compatibility.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
