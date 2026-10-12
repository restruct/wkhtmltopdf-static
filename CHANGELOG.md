# Changelog

## 1.1.0 (2026-10-12)

### Changed

- The bundled Linux binary (`x64/linux/wkhtmltopdf`) is replaced with the upstream wkhtmltopdf
  0.12.6.1 (patched Qt) binary from the official Ubuntu 22.04 package
  (https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.jammy_amd64.deb,
  `.deb` sha256 `4f723b2691ad8638a9df960e0421d346d7315083e3583a334f33362280ddba15`, binary sha256
  `750c92708349321a502b92e5e6c16b25499318ec24d1175cc8a81b6aefa321b2`). The previous binary linked
  OpenSSL 1.1 and could not start on Ubuntu 22.04 or later; the new one links OpenSSL 3 and runs on
  22.04 and 24.04. **It no longer runs on Ubuntu 20.04 or older** (#6). The README lists the runtime
  libraries and the apt line for 24.04 (on a stock 24.04 server only `libxrender1` may be missing).
- CI now tests the bundled Linux binary on Ubuntu 24.04, installing only the README's runtime
  libraries, instead of installing the upstream `.deb`.

### Fixed

- A binary that is executable but cannot start (for example a missing shared library) is no longer
  chosen. Each candidate is run once with `--version` and must exit 0 (cached per PHP process); if
  it fails, the next candidate is tried and then the Docker strategy. Before, only the exec bit was
  checked, so such a binary was chosen and every render failed (#6).

### Added

- `WkhtmltoPdfStatic::probeBinary($path)` and `WkhtmltoPdfStatic::setProbeTimeout($seconds)`
  (default 10 s).

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
