# Changelog

## 1.0.3 (2026-10-11)

### Fixed

- Every render leaked an empty temp file. `tempnam()` creates the file it names, and the wrapper
  used that name plus a suffix (`.html`, `.pdf`) but only deleted the suffixed file, so each
  `generateFromHtml()` left one `wkhtml_*` file in `sys_get_temp_dir()` and each `getOutputFromHtml()`
  left a `wkhtml_*` and a `wkpdf_*` file. Both are now removed after each call (#1).
- `generateFromHtml()` and `generateFromUrl()` now create a missing output directory, as knp-snappy
  did, instead of letting wkhtmltopdf fail with "Unable to write to destination". A directory that
  cannot be created throws a `RuntimeException` before anything is rendered (#2).

### Added

- A PHPUnit test suite (`composer install && vendor/bin/phpunit`), covering both fixes.
