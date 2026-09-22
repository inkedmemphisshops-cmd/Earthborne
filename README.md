# Earthborne Commerce Automation

Private operational code for Earthborne Jewelry's WooCommerce catalog.

## What this repository contains

`earthborne-automation/` is a WordPress/WooCommerce plugin scaffold for:

- scheduled Stuller price and availability refreshes;
- exact SKU matching (including variation SKUs);
- configurable retail markup (default `2.0`);
- automatic out-of-stock handling;
- detailed per-product sync timestamps and status notes;
- guarded catalog population from a previewed list of exact Stuller SKUs;
- idempotent order submission so the same order cannot be sent twice;
- dry-run mode and an explicit fulfillment enable switch;
- WooCommerce order notes and structured logs.

## Safety state

Automatic fulfillment is **off by default**. The plugin will not submit an order until all of the following are true:

1. WooCommerce is active.
2. Stuller credentials and the account-specific endpoint paths are saved.
3. The API adapter is configured to match the real Stuller payloads.
4. Dry-run mode is disabled.
5. Automatic fulfillment is explicitly enabled.

## Install

1. Download this repository as a ZIP.
2. Zip the `earthborne-automation` directory by itself.
3. In WordPress, go to **Plugins → Add New → Upload Plugin**.
4. Activate **Earthborne Commerce Automation**.
5. Open **WooCommerce → Earthborne Automation**.

## Product requirements

- Every synced product or variation must have the exact Stuller SKU in WooCommerce's SKU field.
- Products without a SKU are skipped and logged.
- `_earthborne_managed` may be set to `yes` to limit syncs to managed products.

## Populate a catalog batch

1. Open **WooCommerce → Earthborne Automation**.
2. Paste up to 50 exact Stuller SKUs and choose **Preview catalog batch**.
3. Review the cost, 2× retail price, stock, and decision for every row.
4. Type `POPULATE` and choose **Populate this batch**.

Only rows marked **Ready** are created or updated. The importer excludes out-of-stock items, missing-price items, pearls, lab-grown stones, and detected single-size rings. It assigns **Ready Made Jewelry**, transfers up to eight images, saves exact SKUs, and marks products for later inventory refresh. Population never runs on a schedule.

## Before production

The plugin maps the documented Stuller v2 Product, Virtual Product, Gem, and Submit Order response shapes through `includes/class-earthborne-stuller-mapper.php`. Before production, validate the mapping against authenticated responses from Earthborne's account in a staging store with dry-run enabled. Account-specific differences can be adjusted with the existing WordPress filters.

## Deliberately excluded

Credentials are never stored in this repository. The plugin uses WordPress options and password fields; production secrets should preferably be injected through server configuration.
