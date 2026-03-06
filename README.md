# Commerce Intelligence Engine

Commerce Intelligence Engine is a WooCommerce plugin that builds explainable product recommendations from your own order history.

It mines co-purchase patterns offline, stores the results in custom tables, and serves recommendations on the storefront without doing heavy computation during page requests.

## What It Does

- Mines pairwise product associations from WooCommerce order data
- Scores recommendations using support, confidence, lift, margin, stock, and recency
- Runs full and incremental rebuilds in the background
- Displays recommendations on product pages, and optionally on cart and checkout
- Provides a shortcode for manual placement
- Exposes a REST endpoint for headless or custom frontend use
- Adds a WP-CLI command set for operations and debugging
- Includes admin diagnostics, rebuild logs, and a product-level intelligence metabox
- Supports manual pin/exclude overrides per product
- Falls back to WooCommerce cross-sells, category bestsellers, and global bestsellers when mined data is sparse

## How It Works

The plugin reads WooCommerce order analytics lookup tables and converts historical basket data into product-to-product associations.

Runtime requests do not trigger mining. Recommendations are precomputed during rebuild jobs and then served from plugin tables, with cache support and a deterministic fallback chain when needed.

## Feature Overview

### Recommendation Engine

- Pairwise association mining from completed order data
- Parent-level or variation-level matching modes
- Configurable thresholds for:
  - minimum co-occurrence
  - minimum support
  - minimum confidence
  - minimum lift
- Weighted scoring model with configurable weights
- Category exclusion support
- Query headroom to improve candidate selection quality

### Storefront Output

- Automatic rendering on single product pages
- Optional rendering on cart and checkout in classic WooCommerce flows
- Shortcode rendering for custom placements
- Reason text for mined recommendations such as confidence/lift-based explanations

### Admin and Operations

- WooCommerce admin settings screen
- Manual rebuild trigger
- Scheduled rebuilds: nightly, weekly, or manual
- Incremental nightly rebuilds when a valid baseline exists
- Rebuild logs and health snapshot data
- Dashboard widget with top associations and rebuild status
- Product edit metabox with per-product association insight and manual overrides

### Integrations

- HPOS compatibility declaration
- REST API endpoint for recommendation retrieval
- WP-CLI commands for rebuilds, status checks, cache flushes, and association inspection

## Requirements

- WordPress
- WooCommerce
- WooCommerce order analytics lookup tables populated
- PHP/MySQL environment suitable for WooCommerce plugins

The plugin depends on WooCommerce data sources such as `wc_order_stats` and `wc_order_product_lookup`. If WooCommerce Analytics is disabled, not initialized, or empty, rebuilds will complete with zero mined associations.

## Installation

1. In WordPress admin, go to `Plugins -> Add New Plugin -> Upload Plugin`
2. Upload the plugin ZIP
3. Click `Install Now` and then activate the plugin
4. Open `WooCommerce -> Commerce Intelligence`
5. Review the defaults and run an initial rebuild

## Usage

### Automatic Placement

By default, the plugin is configured to render recommendations on product pages. Cart and checkout placements can be enabled from the plugin settings.

### Shortcode

Use the shortcode below when you want to place recommendations manually:

```text
[cie_recommendations product_id="123" limit="4" context="product"]
```

Supported shortcode attributes:

- `product_id`: explicit product to render recommendations for; falls back to the current product when omitted
- `limit`: number of products to show
- `context`: one of `product`, `cart`, or `checkout`

### REST API

The REST endpoint is available when enabled in plugin settings:

```text
/wp-json/cie/v1/recommendations/<product_id>?limit=4&context=product
```

Response fields include:

- `product_id`
- `recommendations`
- `source`
- `generated_at`

Each recommendation item includes:

- `product_id`
- `score`
- `confidence`
- `lift`
- `reason`
- `source`

### WP-CLI

Available commands:

```bash
wp cie rebuild
wp cie rebuild --incremental
wp cie status
wp cie flush_cache
wp cie associations <product_id> --limit=10
```

## Data and Rebuild Model

- Full rebuilds recompute the association dataset from the configured lookback window
- Incremental rebuilds append newer order data for faster ongoing maintenance
- Weekly full rebuilds are recommended even when using incremental mode so older associations outside the lookback window can be pruned cleanly
- If mined recommendations are too sparse, the plugin falls back to cross-sells, category bestsellers, and then global bestsellers

## Limitations

- Cart and checkout placement targets classic WooCommerce hook-based rendering; block-based flows may require custom integration
- Recommendation quality depends on real order volume and clean product/order data
- Stores with very limited history may rely more heavily on fallback sources until enough baskets accumulate

## Repository Notes

- The plugin is distributed as a standard WordPress plugin
- Custom plugin tables are created on activation
- Plugin data can be removed on uninstall when the corresponding setting is enabled

## License

GPL-2.0-or-later. See [LICENSE.txt](./LICENSE.txt).
