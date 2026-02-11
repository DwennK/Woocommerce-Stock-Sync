# WooCommerce Stock Sync – Dwenn

A lightweight WordPress plugin that lets you **upload a CSV in wp-admin** to sync **stock + regular price** for WooCommerce products **and variations**.

Built for speed and sanity:
- ✅ **One DB lookup** to resolve SKUs (chunked IN query)
- ✅ **AJAX chunk processing** with **progress bar**
- ✅ **No CSV left on disk** (parsed from upload tmp file, job stored in transient)
- ✅ Optional **“Pre-zero stock by category”** (useful when SKUs disappear from supplier CSV)
- ✅ **Dry run mode** to simulate before applying changes

---

## Screenshot / What you get
- A new WP-admin menu: **Stock Sync**  
- Upload CSV → job is created → auto-run or resume later  
- Live stats: processed / updated / missing / errors + log output

---

## CSV format

### Required columns (case-sensitive)
- `Sku`
- `Available`
- `Price`

Example:

```csv
Sku,Available,Price
ABC-123,4,199.90
VAR-RED-S,0,59.00
