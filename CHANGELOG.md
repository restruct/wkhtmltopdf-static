# Changelog

## 1.2.0 (2026-10-12)

### Fixed

- Docker strategy: an output path outside the temp dir no longer fails with "Unable to write to
  destination". The container renders into a temp file in the temp dir, which is then moved to the
  requested path (rename, falling back to copy and delete across file systems). The render's own
  exit code and error output are kept; a successful render whose file cannot be moved reports exit
  1; the temp file is removed on failure and timeout. Relative output paths in raw `run()` calls are
  passed unchanged. `WkhtmltoPdfStatic::dockerOutputRedirect()` and `moveFile()`.
- Docker strategy on macOS: the temp dir is mounted under both its spellings (`/var/folders/...`
  and the resolved `/private/var/folders/...` that `tempnam()` returns). With only the first,
  wkhtmltopdf did not find its input in the container and failed with "Host private not found".

### Security

- Docker strategy: each container now runs as the PHP process's own user (`--user uid:gid`, from
  the posix extension or `id`), with `--init` and a unique `--name`. When a render times out,
  throws, or PHP shuts down mid-render, the container is removed with `docker rm -f`; before, a
  timeout only stopped the `docker` CLI and the container kept running, and its root-owned output
  could not be deleted from a sticky `/tmp` (#3).
- The default Docker image is pinned by the digest of its multi-platform index:
  `ghcr.io/restruct/wkhtmltopdf:0.12.6@sha256:3b95bf589c125dbcbbb0e33619d1241953d6da019d80272d85858816d01c8396`.
  A changed `0.12.6` tag can no longer change what runs. The README shows how to verify it.
- New `WKHTMLTOPDF_LOCAL_FILE_ACCESS` (constant or env): `permissive` (default, unchanged
  behaviour), `allowlist` (refuses `--enable-local-file-access`, accepts `--allow`) or `deny`
  (refuses both). The strict values also pass `--disable-local-file-access`. Refused options throw
  an `InvalidArgumentException` before anything runs; an unknown value throws.
- README: new "Security" section (render only HTML you generate, never arbitrary user URLs, a
  frozen 2020-era engine without security fixes; `docker` group membership is root-equivalent,
  rootless Docker).

### Added

- `WkhtmltoPdf::allowPath($path)`: repeatable `--allow <path>`, the narrow alternative to
  `enableLocalFileAccess()`. Under the Docker strategy each allowed folder is also bind-mounted
  read-only into the container at the same path, so `file://` URLs into it resolve as on the host;
  before, the container only had the temp dir. The path is checked first and refused with an
  `InvalidArgumentException` when it is not an existing absolute directory, or is `/`, a top-level
  directory, a whole home directory or in a system tree such as `/etc` (path and realpath both).
  `WkhtmltoPdfStatic::dockerAllowMounts()` and `dockerMountPath()`; `buildDockerCommand()` takes
  the read-only mounts as a new last argument.
- `docs/troubleshooting.md`: measured behaviour of fonts (generic families resolve to the default
  sans font on Linux, WOFF2 is never applied, webfonts render nondeterministically on the macOS
  binary and `--javascript-delay` does not change that), local file access per mode and under
  Docker, page size, binaries that do not start, timeouts and leftover containers, and how to
  verify a restored image backup. Linked from the README.
- `WKHTMLTOPDF_DOCKER_NETWORK` (constant or env): passed as `docker run --network`, e.g. `none`.
  Empty (default) keeps Docker's default network.
- `WkhtmltoPdfStatic::buildDockerCommand()`, `applyLocalFileAccessPolicy()`,
  `getLocalFileAccessMode()` and `getDockerNetwork()`.
- `build/Dockerfile` installs a `.deb` from `build/debs/` when present instead of downloading it,
  and checks the sha256 of the `.deb` either way. HOW-IT-WORKS describes the backup assets on the
  GitHub release (both upstream `.deb` files and the image as an OCI archive) and how to restore or
  rebuild from them.
- A visual check (`tests/Visual/`, PHPUnit group `visual`, plus the CLI
  `tests/Visual/visual-check.php`). It renders a fixed mm-exact fixture and compares page count and
  page sizes (pdfinfo) exactly and every page's pixels (pdftopng at 100 dpi, GD) against committed
  per-platform baselines, so a change of wkhtmltopdf version, strategy or host fonts that changes
  the output fails with a diff image. The CLI also compares two strategies or binaries directly
  (e.g. `compare bundled docker`). Must-fail controls (smart shrinking on, `--zoom 1.02`, a 4 px
  margin, Letter paper) are part of the group. See the README section "Visual check".
- `restruct/xpdf-static` as a dev dependency, for the visual check.
- CI runs the visual group on Ubuntu 24.04 in its own step and uploads renders, diff images and
  proposed baselines when it fails. Baselines are committed for macOS (bundled binary) and Linux
  (bundled binary on Ubuntu 24.04, taken from the first CI run).

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
