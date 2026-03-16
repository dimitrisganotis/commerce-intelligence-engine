=== Commerce Intelligence Engine ===
Contributors: dimitrisganotis
Donate link: https://www.dganotis.dev
Tags: woocommerce, recommendations, product recommendations, cross-sell, analytics
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Explainable WooCommerce product recommendations generated from your store's own order history.

== Description ==

Commerce Intelligence Engine builds on-premise WooCommerce recommendations from real basket data.

It mines pairwise co-purchase associations from WooCommerce order analytics tables, stores the results in plugin tables, and serves recommendations without doing heavy runtime computation on storefront requests.

= Key features =

* Pairwise association mining from WooCommerce orders
* Explainable scoring using support, confidence, lift, margin, stock, and recency
* Full and incremental rebuild modes
* Automatic storefront placement on product pages
* Optional cart and checkout rendering for classic WooCommerce flows
* Shortcode support for manual placement
* REST API endpoint for headless or custom integrations
* WP-CLI commands for rebuilds, status checks, cache flushes, and association inspection
* Product-level intelligence metabox with pin and exclude overrides
* Dashboard widget and rebuild diagnostics
* Fallback chain using WooCommerce cross-sells, category bestsellers, and global bestsellers
* HPOS compatibility declaration

= Operational notes =

Commerce Intelligence Engine reads WooCommerce lookup tables such as:

* `wc_order_stats`
* `wc_order_product_lookup`

If WooCommerce Analytics is disabled, not initialized, or empty, rebuilds will complete with zero mined associations.

Incremental rebuilds are optimized for speed and append newly observed order data. To prune associations that fall outside the configured lookback window, run a full rebuild regularly. Weekly full rebuilds are a sensible default.

= Placement options =

You can use the plugin in three main ways:

* automatic rendering on product pages
* optional rendering on cart and checkout
* manual rendering with shortcode

Shortcode example:

`[cie_recommendations product_id="123" limit="4" context="product"]`

= REST API =

When enabled in plugin settings, the plugin exposes a read endpoint:

`/wp-json/cie/v1/recommendations/<product_id>?limit=4&context=product`

Supported contexts:

* `product`
* `cart`
* `checkout`

== Installation ==

1. In WordPress admin, go to `Plugins -> Add New Plugin -> Upload Plugin`
2. Upload the plugin ZIP
3. Click `Install Now` and activate the plugin
4. Make sure WooCommerce is installed and active
5. Go to `WooCommerce -> Commerce Intelligence`
6. Review the default settings and run an initial rebuild

== Frequently Asked Questions ==

= Does this require WooCommerce? =

Yes. The plugin deactivates itself if WooCommerce is not active.

= Does it send store data to an external service? =

No. Recommendation mining and serving are designed to run locally inside WordPress/WooCommerce.

= Where do the recommendations come from? =

Primarily from mined co-purchase associations in your WooCommerce order history. If mined data is too sparse, the plugin can fall back to WooCommerce cross-sells, category bestsellers, and global bestsellers.

= Does it work immediately after activation? =

The plugin activates immediately, but recommendation quality depends on available WooCommerce order data. You should run an initial rebuild after activation.

= What happens on stores with limited order history? =

Stores with sparse order data may rely more heavily on fallback recommendation sources until enough baskets accumulate.

= Does it support cart and checkout blocks? =

The built-in placement targets classic WooCommerce hook-based rendering. Block-based storefronts may require custom integration or manual placement.

= Is there a CLI for operations? =

Yes. Available commands include:

* `wp cie rebuild`
* `wp cie rebuild --incremental`
* `wp cie status`
* `wp cie flush_cache`
* `wp cie associations <product_id> --limit=10`

== Screenshots ==

1. Commerce Intelligence settings screen with rebuild controls and engine configuration.
2. Product intelligence metabox showing mined associations, scores, and manual pin or exclude overrides.
3. Storefront recommendation block rendered on a product page.
4. Dashboard widget showing rebuild health and top associations.

== Changelog ==

= 1.0.0 =

* Initial public release.
* Pairwise recommendation mining from WooCommerce order data.
* Full and incremental rebuild workflows.
* Product page rendering, shortcode support, and optional cart and checkout rendering.
* REST API endpoint and WP-CLI commands.
* Admin settings, rebuild diagnostics, dashboard widget, and product intelligence metabox.
* Manual pin and exclude overrides.
* Fallback recommendation sources for sparse-data scenarios.

== Upgrade Notice ==

= 1.0.0 =

Initial public release of Commerce Intelligence Engine.
