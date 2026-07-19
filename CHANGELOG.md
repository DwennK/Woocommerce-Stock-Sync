# Changelog
All notable changes to this project will be documented in this file.

The format is inspired by "Keep a Changelog".
Versions use SemVer: MAJOR.MINOR.PATCH.

---

## [2.0.0] - 2026-07-19
### Added
- Added strict row validation with line, field, raw value, duplicate SKU, and ambiguous WooCommerce SKU diagnostics.
- Added a real old-to-new preview for stock and regular prices before any write occurs.
- Added persistent job, item, and log tables with resumable Action Scheduler processing and WordPress Cron fallback.
- Added per-job execution locks, watchdog recovery, cancellation states, recent history, and asynchronous rollback.
- Added detailed preview counters for changed, unchanged, missing, invalid, applied, and failed rows.

### Changed
- Product writes now occur only when WooCommerce values actually differ from the target values.
- CSV imports now require explicit confirmation after preview instead of starting automatically.
- Pre-zero operations are previewed and retain complete product/variation snapshots for rollback.
- Rollback preserves products that were changed again after the import and reports them as conflicts.
- Replaced transient job payloads and repeated full-job serialization with normalized persistent records.
- Bumped plugin header version to `2.0.0` and declared WordPress, PHP, and WooCommerce requirements.

### Removed
- Removed silent coercion of empty or invalid stock and price values to zero.

## [1.4.1] - 2026-06-22
### Security
- Bound AJAX job execution and cancellation to the admin user that created the job.
- Switched remaining JavaScript data output to `wp_json_encode()`.

### Changed
- Bumped plugin header version to `1.4.1`.

---

## [1.4.0] - 2026-06-22
### Added
- Added reusable supplier profiles for delimiter, column names, price adjustment, rounding, and pre-zero categories.
- Added CSV delimiter auto-detection for comma, semicolon, and tab-delimited supplier files.
- Added explicit CSV column mapping fields for SKU, stock, and price headers.
- Added import report export from the progress screen.
- Added separate pre-zero progress counters.
- Added PHPUnit coverage for CSV parsing, delimiter detection, custom columns, duplicate SKUs, and price adjustment edge cases.
- Added a quality CI workflow with PHP syntax checks, PHP_CodeSniffer with WordPress Coding Standards, PHPUnit, and version consistency checks.

### Changed
- Split the main plugin file into focused classes for admin UI, CSV parsing, SKU resolution, job storage, stock updates, and orchestration.
- Finished jobs now remain available until expiry or cancellation so reports can be exported after completion.
- Bumped plugin header version to `1.4.0`.

### Fixed
- Passed the explicit `fgetcsv()` escape argument to avoid PHP deprecation warnings on newer PHP versions.
- Improved request sanitization for AJAX job IDs and uploaded CSV metadata.

---

## [1.3.2] - 2026-03-23
### Fixed
- Hardened numeric parsing for CSV price and stock values so common formats like `1,299.00`, `1.299,00`, spaces, and apostrophes do not get misread.
- Improved admin-side AJAX error handling so network/server failures show a clear notice instead of silently stalling.
- Switched CSV row parsing to `fgetcsv()` to better support valid quoted CSV rows.

### Changed
- Bumped plugin header version to `1.3.2`.

---

## [1.3.0] - 2026-02-11
### Added
- AJAX chunk processing with progress UI (progress bar + live counters).
- Resumable transient-based jobs (auto-expiring, auto-cleanup).
- Optional pre-zero stock by category before sync.
- Price adjustment (+ optional rounding) with save-as-default.

### Changed
- Improved SKU resolution performance (single lookup, chunked IN()).

### Fixed
- Safer defaults and cleanup on cancel/finish.

---

## [1.3.1] - 2026-02-11
### Added
- Small UI: disable pre-zero category checkboxes until pre-zero is enabled (client-side).

### Changed
- Audit: verified nonce usage for form (`wcssd_create_job`) and AJAX (`wcssd_ajax`) endpoints and ensured handlers call `check_admin_referer`/`check_ajax_referer`.
- Bumped plugin header version to `1.3.1`.


## [1.2.0] - YYYY-MM-DD
### Added
- (Describe changes…)

---

## [1.1.0] - YYYY-MM-DD
### Added
- (Describe changes…)
