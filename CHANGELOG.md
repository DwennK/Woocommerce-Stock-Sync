# Changelog
All notable changes to this project will be documented in this file.

The format is inspired by "Keep a Changelog".
Versions use SemVer: MAJOR.MINOR.PATCH.

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