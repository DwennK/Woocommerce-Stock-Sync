# WooCommerce Stock Sync - Dwenn

Small WooCommerce admin plugin to sync stock and regular price from a supplier CSV.

It is built for a simple workflow:
- upload a CSV in wp-admin
- validate every supplier row and preview the exact old-to-new changes
- create a durable, resumable background job
- process products with Action Scheduler and live progress
- optionally pre-zero selected categories before the import

## What It Does

- Updates WooCommerce products and variations by SKU
- Syncs `stock_quantity`, stock status, and regular price
- Resolves SKUs in one chunked database lookup for better performance
- Processes updates in durable background batches to avoid long admin requests
- Stores normalized jobs, items, snapshots, and logs in dedicated database tables
- Supports preview-only mode and explicit confirmation before live writes
- Skips unchanged products and keeps rollback snapshots for completed imports
- Keeps a recent per-user job history in wp-admin
- Supports a fixed price adjustment with optional integer rounding
- Supports pre-zero stock by selected product categories before sync
- Auto-detects CSV delimiters: comma, semicolon, or tab
- Supports reusable supplier profiles for delimiter, column names, price adjustment, and pre-zero categories
- Exports the import report/log from the progress screen

## Current Version

- Plugin version: `2.0.0`

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
   - preview-only or live mode
   - chunk size
   - optional price adjustment
   - optional pre-zero categories
4. Review changed, unchanged, missing, and invalid rows in the preview.
5. Confirm the exact changes to start the durable job.
6. Watch progress or close the page and return later.
7. Roll back a completed import if the previous values must be restored.

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

Supplier profiles can override the exact header names used for SKU, stock, and price.

### CSV delimiter

The importer auto-detects common CSV delimiters:

- comma
- semicolon
- tab

You can also force a delimiter in the upload form or save it in a supplier profile.

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
- Duplicate SKUs are reported as validation errors, including case-only duplicates
- Ambiguous SKUs that match multiple WooCommerce records are blocked
- Missing SKUs are counted and skipped

## Options

### Preview-only mode

Builds the complete old-to-new diff without allowing changes to be applied.

### Price adjustment

Adds a fixed amount to each CSV price before saving.

Examples:

- CSV price `399.00` + adjustment `100` => saved price `499.00`
- optional rounding can round the final value to the nearest integer

### Supplier profiles

Profiles save the import settings for a supplier:

- CSV delimiter
- SKU, stock, and price column names
- price adjustment and rounding
- pre-zero enabled state and selected categories

Choose a saved profile before uploading a CSV to restore those settings.

### Pre-zero stock by category

Before the CSV sync starts, the plugin can set stock to `0` for products in selected categories.

This is useful when:

- your supplier removes SKUs from the file when they are no longer available
- you want old supplier-linked products to go out of stock before the new import runs

For variable products, the plugin also sets each variation stock to `0`.

## Technical Notes

- Uses one chunked SKU lookup query instead of querying product-by-product
- Uses Action Scheduler with a WordPress Cron fallback for durable background processing
- Uses dedicated job, item, and log tables instead of transient payloads
- Does not keep the uploaded CSV permanently on disk
- Includes nonce and capability checks on admin and AJAX actions
- Keeps per-item before snapshots so completed imports can be rolled back
- Uses a per-job lease to prevent overlapping batches

## Repository Layout

- `wc-stock-sync-dwenn/wc-stock-sync-dwenn.php`: plugin bootstrap
- `wc-stock-sync-dwenn/includes/class-plugin.php`: WordPress hooks and job orchestration
- `wc-stock-sync-dwenn/includes/class-admin-page.php`: wp-admin screen and AJAX UI
- `wc-stock-sync-dwenn/includes/class-csv-parser.php`: CSV headers, number parsing, and row normalization
- `wc-stock-sync-dwenn/includes/class-sku-resolver.php`: SKU-to-product lookup
- `wc-stock-sync-dwenn/includes/class-job-store.php`: persistent job, item, log, and lock storage
- `wc-stock-sync-dwenn/includes/class-stock-updater.php`: WooCommerce stock and price writes
- `CHANGELOG.md`: release history
- `dist/`: local build output, ignored by git

## Development

### Run Tests

Install development dependencies and run the PHPUnit suite from the repository root:

```bash
composer install
composer test
```

Run the full local quality gate:

```bash
composer ci
```

This runs PHP syntax checks, PHP_CodeSniffer with WordPress Coding Standards, PHPUnit, and the plugin/changelog/tag version consistency check.

### Create a WordPress-ready ZIP

For WordPress upload, package the plugin so the ZIP contains the plugin files under the correct slug.

Recommended ZIP name:

- `wc-stock-sync-dwenn.zip`

## Changelog

See [CHANGELOG.md](./CHANGELOG.md).
