# WooCommerce Stock Sync - Dwenn

Small WooCommerce admin plugin to sync stock and regular price from a supplier CSV.

It is built for a simple workflow:
- upload a CSV in wp-admin
- create a resumable sync job
- process products in AJAX chunks with live progress
- optionally pre-zero selected categories before the import

## What It Does

- Updates WooCommerce products and variations by SKU
- Syncs `stock_quantity`, stock status, and regular price
- Resolves SKUs in one chunked database lookup for better performance
- Processes updates in AJAX chunks to avoid long admin requests
- Stores the job in a transient, with cleanup on finish or cancel
- Supports dry run mode
- Supports a fixed price adjustment with optional integer rounding
- Supports pre-zero stock by selected product categories before sync

## Current Version

- Plugin version: `1.3.2`

## Installation

### WordPress admin

Use the ZIP whose root matches the plugin slug:

- `wc-stock-sync-dwenn.zip`

Then in WordPress:

1. Go to `Extensions > Ajouter`.
2. Click `Téléverser une extension`.
3. Upload `wc-stock-sync-dwenn.zip`.
4. Activate the plugin.

### Manual install

Copy the folder below into `wp-content/plugins/`:

- `wc-stock-sync-dwenn/`

Then activate it from the WordPress plugins screen.

## Admin Workflow

1. Open `Stock Sync` in wp-admin.
2. Upload a supplier CSV.
3. Choose your options:
   - dry run or live mode
   - chunk size
   - optional price adjustment
   - optional pre-zero categories
4. Start or resume the job.
5. Watch progress, updated count, missing SKUs, errors, and the live log.

## CSV Format

### Required columns used by the sync

- `Sku`
- `Available`
- `Price`

### Extra columns

Extra columns are allowed and ignored.

For example, this kind of supplier file is fine:

```csv
Make,Model,Size,Color,Condition,Carrier,Available,Price,Currency Code,Sku
Apple,iPhone 13,128GB,Blue,A,Unlocked,4,399.00,CHF,IP13-128-BLU
```

### Header matching

Header matching is flexible:

- column order does not matter
- common variants like `SKU` work
- `Available` also accepts common names like `Stock`, `Qty`, `Quantity`
- `Price` also accepts `Regular Price`

### Number parsing

Price and stock parsing is tolerant of common supplier formats, including:

- `1299`
- `1,299.00`
- `1.299,00`
- values with spaces or apostrophes as thousands separators

### Example minimal CSV

```csv
Sku,Available,Price
ABC-123,4,199.90
VAR-RED-S,0,59.00
```

## Matching Rules

- Only products with an existing WooCommerce SKU are updated
- Both simple products and variations are supported
- If the CSV contains duplicate SKUs, the last row wins
- Missing SKUs are counted and skipped

## Options

### Dry run

Simulates the sync without saving changes to WooCommerce products.

### Price adjustment

Adds a fixed amount to each CSV price before saving.

Examples:

- CSV price `399.00` + adjustment `100` => saved price `499.00`
- optional rounding can round the final value to the nearest integer

### Pre-zero stock by category

Before the CSV sync starts, the plugin can set stock to `0` for products in selected categories.

This is useful when:

- your supplier removes SKUs from the file when they are no longer available
- you want old supplier-linked products to go out of stock before the new import runs

For variable products, the plugin also sets each variation stock to `0`.

## Technical Notes

- Uses one chunked SKU lookup query instead of querying product-by-product
- Uses AJAX chunk processing to reduce timeout risk
- Uses transients for resumable jobs
- Does not keep the uploaded CSV permanently on disk
- Includes nonce and capability checks on admin and AJAX actions

## Repository Layout

- `wc-stock-sync-dwenn/wc-stock-sync-dwenn.php`: plugin file
- `CHANGELOG.md`: release history
- `dist/`: local build output, ignored by git

## Development

### Create a WordPress-ready ZIP

For WordPress upload, package the plugin so the ZIP contains the plugin files under the correct slug.

Recommended ZIP name:

- `wc-stock-sync-dwenn.zip`

## Changelog

See [CHANGELOG.md](./CHANGELOG.md).
