# restruct/wkhtmltopdf-static

*Maintained by [Restruct](https://github.com/restruct). If this package saves you time, you can
[support ongoing maintenance](https://github.com/sponsors/restruct).*

Pre-built **wkhtmltopdf 0.12.6.1 (patched Qt)** for PHP projects — bundled static-ish binaries, a fluent PHP wrapper, and a multi-arch Docker fallback for platforms without a native binary.

> **Why this package exists:** the wkhtmltopdf project is **archived** — 0.12.6.1-3 is the final release, forever. Its binary depends on a patched Qt and a set of aging shared libraries that get harder to satisfy with every new OS release. This package preserves a known-good build in two forms: bundled binaries for platforms that can still run them natively, and a frozen Ubuntu 22.04 Docker image (`ghcr.io/restruct/wkhtmltopdf:0.12.6`, amd64+arm64) for everything else.
>
> New to Docker or fuzzy on how the pieces fit? Read **[HOW-IT-WORKS.md](HOW-IT-WORKS.md)** — a from-scratch explainer of the image/container/registry/multi-arch story behind this package.

## Installation

```bash
composer require restruct/wkhtmltopdf-static
```

Requires PHP ≥ 8.1 and `symfony/process` (^6 or ^7).

## Quick start

```php
use Restruct\WkhtmltoPdf\WkhtmltoPdf;

// HTML string → PDF file
$result = WkhtmltoPdf::create()
    ->margins('0px', '0px', '0px', '0px')
    ->enableLocalFileAccess()       // required when HTML references local CSS/images
    ->disableSmartShrinking()       // fixes 0.12.4→0.12.6 scaling differences
    ->generateFromHtml($html, '/path/to/output.pdf');

// HTML string → raw PDF bytes (throws RuntimeException on failure)
$pdfBytes = WkhtmltoPdf::create()->getOutputFromHtml($html);

// URL → PDF file
WkhtmltoPdf::create()->generateFromUrl('https://example.com/', '/path/to/output.pdf');
```

Every wkhtmltopdf CLI option is reachable — common ones have named methods (`pageSize()`, `orientation()`, `dpi()`, `headerHtml()`, `footerCenter()`, `javascriptDelay()`, `username()`/`password()` for HTTP auth, …), everything else via the generic setters:

```php
WkhtmltoPdf::create()
    ->setOption('grayscale', true)                  // boolean flag → --grayscale
    ->setOptions(['page-size' => 'A3', 'dpi' => 300])
    ->timeout(300)                                  // per-call timeout (default 120s)
    ->generateFromHtml($html, $out);
```

`generateFromHtml()`/`generateFromUrl()` return a `WkhtmltoPdfResult` (`->isSuccessful()`, `->output`, `->errorOutput`, `->exitCode`); the `getOutput*()` variants return raw bytes and throw on failure.

### Migrating from knplabs/knp-snappy

The API is a drop-in style replacement:

```php
// Before (Snappy)
$snappy = new \Knp\Snappy\Pdf($binaryPath);
$snappy->generateFromHtml($html, $file, $options, true);

// After
WkhtmltoPdf::create()->setOptions($options)->generateFromHtml($html, $file);
```

No binary path needed — resolution is automatic (see below).

Like Snappy, `generateFromHtml()` and `generateFromUrl()` create the output file's directory when it
does not exist yet, and throw a `RuntimeException` when it cannot be created (from 1.0.3). Temp files
(the HTML input and the `getOutput*()` PDF) are written to `sys_get_temp_dir()` and removed after
each call.

## How execution works: binary first, Docker fallback

`WkhtmltoPdfStatic::run()` picks a strategy per call:

1. **`binary`** — if a wkhtmltopdf binary is resolved, it runs as a plain OS process. Fastest; Docker not involved at all.
2. **`docker`** — otherwise, if a Docker image is configured **and** the Docker daemon responds, each call runs a throwaway container: `docker run --rm -v /tmp:/tmp <image> <args>`. The system temp dir is volume-mounted so input HTML and output PDF are visible to both host and container. ~100–300 ms startup overhead per call, no persistent state.
3. Neither available → `RuntimeException`.

```php
use Restruct\WkhtmltoPdf\WkhtmltoPdfStatic;

WkhtmltoPdfStatic::isAvailable();   // true if either strategy works
WkhtmltoPdfStatic::getStrategy();   // 'binary' | 'docker' | ''
WkhtmltoPdfStatic::version();       // "wkhtmltopdf 0.12.6.1 (with patched qt)"
```

### Configuration (constants / environment)

`bootstrap.php` (included automatically on first use, guarded by `defined()` checks) resolves two constants:

| Constant / env var | Resolution order |
|---|---|
| `WKHTMLTOPDF_PATH` | 1. pre-defined constant → 2. env var → 3. **macOS:** bundled `x64/mac` binary (runs on Apple Silicon via Rosetta 2) → 4. **Linux:** `/usr/local/bin/wkhtmltopdf` (system install), then bundled `x64/linux` binary |
| `WKHTMLTOPDF_DOCKER_IMAGE` | 1. pre-defined constant → 2. env var → 3. **Linux default:** `ghcr.io/restruct/wkhtmltopdf:0.12.6` |

Typical `.env` on a server:

```env
# Option A: native binary (fastest — preferred where it still runs)
WKHTMLTOPDF_PATH=/usr/local/bin/wkhtmltopdf

# Option B: Docker (for hosts where the native libs are unavailable/broken)
WKHTMLTOPDF_DOCKER_IMAGE=ghcr.io/restruct/wkhtmltopdf:0.12.6
```

If both resolve, the native binary wins.

A binary only counts as resolved if it can actually start (from 1.1.0). Each candidate path is run
once with `--version` (10 s timeout) and must exit 0; the result is cached for the rest of the PHP
process, so this costs one extra process start per path, not one per render. A binary that is
executable but cannot start, for example because a shared library is missing, is skipped: the
next candidate in the table above is tried, and if none starts the Docker strategy is used. Up to
1.0.3 only the exec bit was checked, so such a binary was chosen anyway and every render failed.
`WkhtmltoPdfStatic::probeBinary($path)` runs the same check by hand.

## Platform support

| Platform | Native binary | Docker image |
|---|---|---|
| macOS x64 | ✅ bundled (`x64/mac`) | — |
| macOS ARM (Apple Silicon) | ✅ bundled x64 binary via Rosetta 2 | ✅ native `linux/arm64` (in OrbStack/Docker Desktop) |
| Linux x64 | ✅ bundled (`x64/linux`, Ubuntu 22.04+, see [below](#the-bundled-linux-binary)) or system deb | ✅ `linux/amd64` |
| Linux ARM64 | ❌ upstream never shipped one | ✅ `linux/arm64` |

The Docker image is the answer for every platform without a workable native binary — and the long-term insurance for when OS upgrades break the native ones.

## The bundled Linux binary

`x64/linux/wkhtmltopdf` is the upstream wkhtmltopdf 0.12.6.1 (patched Qt) binary, taken unchanged
from the official Ubuntu 22.04 (jammy) amd64 package (from 1.1.0). It links OpenSSL 3, so it runs
on Ubuntu 22.04 and 24.04. It does **not** run on Ubuntu 20.04 or older, which ship OpenSSL 1.1
only. Up to 1.0.3 the bundled binary was an older build linked against OpenSSL 1.1, which in turn
could not start on 22.04 and later (#6).

| | |
|---|---|
| Source | https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.jammy_amd64.deb |
| `.deb` sha256 | `4f723b2691ad8638a9df960e0421d346d7315083e3583a334f33362280ddba15` |
| Binary sha256 | `750c92708349321a502b92e5e6c16b25499318ec24d1175cc8a81b6aefa321b2` (`usr/local/bin/wkhtmltopdf` in the `.deb`) |
| Version | `wkhtmltopdf 0.12.6.1 (with patched qt)` |

Upstream publishes no checksums for its release assets (neither on the release page nor on
wkhtmltopdf.org), so the `.deb` sha256 above is the one we computed on download. It matches the
value several unrelated public projects pin for the same file, and the binary matches the MD5 in the
`.deb`'s own `md5sums` list. To check the bundled binary yourself:

```bash
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.jammy_amd64.deb
sha256sum wkhtmltox_0.12.6.1-3.jammy_amd64.deb
dpkg-deb -x wkhtmltox_0.12.6.1-3.jammy_amd64.deb deb && sha256sum deb/usr/local/bin/wkhtmltopdf
```

### Runtime libraries (Ubuntu 24.04)

The binary links these shared libraries (its ELF `DT_NEEDED` list): `libjpeg.so.8`,
`libpng16.so.16`, `libXrender.so.1`, `libfontconfig.so.1`, `libfreetype.so.6`, `libX11.so.6`,
`libssl.so.3`, `libcrypto.so.3`, `libz.so.1`, plus the C/C++ runtime (`libc`, `libm`, `libstdc++`,
`libgcc_s`). Fontconfig also needs at least one font installed to render text.

On the official Ubuntu 24.04 server cloud image (package manifest of 2026-09-26) all of these are
already installed **except `libxrender1`**. A server provisioned with more packages (Laravel Forge,
for example) usually has that one too. To be sure on any 24.04 server, run this; apt skips what is
already installed:

```bash
sudo apt-get install -y --no-install-recommends libfontconfig1 libfreetype6 libjpeg-turbo8 libpng16-16t64 libssl3t64 libx11-6 libxrender1 zlib1g fonts-dejavu-core
```

On 22.04 two of the package names differ: use `libpng16-16` and `libssl3` instead of
`libpng16-16t64` and `libssl3t64`. Then check that nothing is missing:

```bash
ldd vendor/restruct/wkhtmltopdf-static/x64/linux/wkhtmltopdf | grep 'not found'   # prints nothing
vendor/restruct/wkhtmltopdf-static/x64/linux/wkhtmltopdf --version
```

The upstream `.deb` also depends on `xfonts-75dpi` and `xfonts-base` (X bitmap fonts). The binary
starts and renders without them; install them only if your HTML relies on those fonts. CI runs the
test suite against the bundled binary on Ubuntu 24.04 with exactly the apt line above.

## The Docker image

`ghcr.io/restruct/wkhtmltopdf:0.12.6` — public, multi-arch (`linux/amd64` + `linux/arm64`). Ubuntu 22.04 with the official final wkhtmltopdf deb and all runtime libraries frozen in. Built from [`build/Dockerfile`](build/Dockerfile):

```bash
build/build.sh          # build native-arch image locally (for testing)
build/build.sh --push   # build amd64+arm64 and push manifest to ghcr.io
                        # (requires: docker login ghcr.io with a write:packages token)
```

Server deployment (pulling the image, adding the PHP user to the docker group, a deploy-script line) is outlined in [HOW-IT-WORKS.md](HOW-IT-WORKS.md#deployment-on-a-newupgraded-server-the-insurance-policy-activated); keep host-specific notes in your own project docs.

## Native install on Ubuntu servers (verified on 22.04 jammy AND 24.04 noble)

Hard-won gotchas from getting this running on a production server:

- **Do NOT use Ubuntu's own `wkhtmltopdf` package** — it's built against *unpatched* system Qt (different rendering, missing features such as native headers/footers). Remove it if present; its output does not match the patched-Qt build used everywhere else.
- **Upstream ships no 24.04 (noble) deb** — the jammy deb installs and runs fine on 24.04 once the font dependencies are in place.
- **`xfonts-75dpi` / `xfonts-base` are not preinstalled** on typical server images, so a bare `dpkg -i` fails on unmet dependencies. Install them first (or rescue a half-installed state with `apt --fix-broken install`).
- The official deb installs to **`/usr/local/bin/wkhtmltopdf`** (the distro package used `/usr/bin`) — this matches `bootstrap.php`'s Linux candidate order.

```bash
# 1. Remove the distro package if present (unpatched Qt!)
sudo apt-get remove wkhtmltopdf && sudo apt autoremove

# 2. Dependencies (the xfonts packages are the ones usually missing)
sudo apt-get update
sudo apt-get install -y xfonts-75dpi xfonts-base libfontconfig1 libfreetype6 libjpeg-turbo8 libx11-6 libxext6 libxrender1

# 3. Official final-release deb (jammy build, works on 22.04 and 24.04)
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.jammy_amd64.deb
sudo dpkg -i wkhtmltox_0.12.6.1-3.jammy_amd64.deb
sudo apt-get install -f        # safety net: configures + pulls any remaining deps

# 4. Verify
which wkhtmltopdf              # → /usr/local/bin/wkhtmltopdf
wkhtmltopdf -V                 # → wkhtmltopdf 0.12.6.1 (with patched qt)
```

## Package contents

```
bootstrap.php        constant resolution (WKHTMLTOPDF_PATH / _DOCKER_IMAGE)
src/
  WkhtmltoPdf.php        fluent HTML→PDF API (Snappy replacement)
  WkhtmltoPdfStatic.php  strategy resolution + process execution
  WkhtmltoPdfResult.php  output/errorOutput/exitCode value object
x64/mac/, x64/linux/     bundled 0.12.6.1 patched-Qt binaries
build/                   Dockerfile + multi-arch build script
tests/                   PHPUnit suite (not in dist installs)
```

## Running the tests

```bash
composer install
vendor/bin/phpunit
```

The render tests need a working wkhtmltopdf (the bundled binary or the Docker image) and are
skipped without one.

## License

- Wrapper code (`bootstrap.php`, `src/`, `build/`): MIT (Copyright Restruct). Up to 1.0.1 the
  wrapper was LGPL-3.0-only; from 1.0.2 it is MIT.
- Bundled binaries (`x64/`) and the Docker image: wkhtmltopdf 0.12.6.1 (patched Qt), prebuilt by the
  upstream project, not by us, and not covered by the MIT licence. wkhtmltopdf is Copyright its
  authors and licensed under the GNU LGPL version 3 or later; the patched Qt 4.8.7 it contains is
  licensed by The Qt Company under the GNU LGPL version 2.1 or 3 with the Qt LGPL Exception 1.1.
  The licence texts ship in [`licenses/`](licenses/): `COPYING.LESSER` (LGPL-3.0), `COPYING`
  (GPL-3.0, which the LGPL-3.0 builds on) and Qt's own texts in `licenses/qt/`. Source:
  https://github.com/wkhtmltopdf/wkhtmltopdf and https://github.com/wkhtmltopdf/qt.

`composer.json` declares this as `(MIT AND LGPL-3.0-or-later)`. See [LICENSE](LICENSE) for the full terms.
