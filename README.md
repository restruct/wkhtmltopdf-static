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
    ->allowPath('/path/to/assets')  // when HTML references local CSS/images, see "Security"
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
2. **`docker`**: otherwise, if a Docker image is configured **and** the Docker daemon responds, each call runs a throwaway container: `docker run --rm --init --name wkhtmltopdf-<random> --user <uid>:<gid> -v /tmp:/tmp <image> <args>`. The system temp dir is volume-mounted so input HTML and output PDF are visible to both host and container, and from 1.2.0 each `allowPath()` folder is mounted read-only at the same path. An output path outside the temp dir is rendered into the temp dir and moved there afterwards (from 1.2.0; see [Troubleshooting](docs/troubleshooting.md#under-docker)). ~100 to 300 ms startup overhead per call, no persistent state. See [Docker hardening](#docker-hardening) for what the extra flags do (from 1.2.0).
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
| `WKHTMLTOPDF_DOCKER_IMAGE` | 1. pre-defined constant → 2. env var → 3. **Linux default:** `ghcr.io/restruct/wkhtmltopdf:0.12.6@sha256:3b95bf589c125dbcbbb0e33619d1241953d6da019d80272d85858816d01c8396` (pinned by digest from 1.2.0, see [The Docker image](#the-docker-image)) |
| `WKHTMLTOPDF_DOCKER_NETWORK` | 1. pre-defined constant → 2. env var → 3. empty: Docker's default network (from 1.2.0) |
| `WKHTMLTOPDF_LOCAL_FILE_ACCESS` | 1. pre-defined constant → 2. env var → 3. `permissive` (from 1.2.0, see [Local file access](#local-file-access)) |

Typical `.env` on a server:

```env
# Option A: native binary (fastest — preferred where it still runs)
WKHTMLTOPDF_PATH=/usr/local/bin/wkhtmltopdf

# Option B: Docker (for hosts where the native libs are unavailable/broken)
# (pinned by digest from 1.2.0; the plain tag still works but follows whatever the tag points at)
# WKHTMLTOPDF_DOCKER_IMAGE=ghcr.io/restruct/wkhtmltopdf:0.12.6
WKHTMLTOPDF_DOCKER_IMAGE=ghcr.io/restruct/wkhtmltopdf:0.12.6@sha256:3b95bf589c125dbcbbb0e33619d1241953d6da019d80272d85858816d01c8396
```

If both resolve, the native binary wins.

A binary only counts as resolved if it can actually start (from 1.1.0). Each candidate path is run
once with `--version` (10 s timeout) and must exit 0; the result is cached for the rest of the PHP
process, so this costs one extra process start per path, not one per render. A binary that is
executable but cannot start, for example because a shared library is missing, is skipped: the
next candidate in the table above is tried, and if none starts the Docker strategy is used. Up to
1.0.3 only the exec bit was checked, so such a binary was chosen anyway and every render failed.
`WkhtmltoPdfStatic::probeBinary($path)` runs the same check by hand.

## Security

wkhtmltopdf is a browser. It runs JavaScript, follows URLs and reads files, with a QtWebKit engine
that was frozen when the project was archived in 2020 and gets no security fixes. Treat it like
that:

- **Render only HTML you generate yourself.** Escape every user-supplied value that goes into it,
  as you would for a web page; injected markup can load URLs and local files.
- **Never render arbitrary user-supplied URLs** with `generateFromUrl()`/`getOutputFromUrl()`. The
  server would fetch whatever the URL points at, including internal addresses (SSRF) and, with
  local file access on, local files.
- Call `disableJavascript()` when your HTML needs no JavaScript.

### Local file access

wkhtmltopdf 0.12.6 blocks a page from loading other local files by default, and a blocked file
makes the render exit 1 (checked with the bundled binary). The input HTML is written to a temp
file, so this also covers relative URLs in it. You have two ways to allow local files:

- `allowPath($dir)` (from 1.2.0) passes `--allow <dir>`: only files in that file or directory can
  be loaded. Repeatable, one call per path. Prefer this.
- `enableLocalFileAccess()` passes `--enable-local-file-access`: the HTML can read **any** file the
  PHP user can read, through any `file://` URL that ends up in it.

`WKHTMLTOPDF_LOCAL_FILE_ACCESS` lets a project rule out the wide option (from 1.2.0):

| Value | `--enable-local-file-access` | `--allow` | `--disable-local-file-access` added |
|---|---|---|---|
| `permissive` (default) | passed | passed | no |
| `allowlist` | refused | passed | yes |
| `deny` | refused | refused | yes |

Refused means `WkhtmltoPdfStatic::run()`, and with it every render method, throws an
`InvalidArgumentException` before anything is started. An unknown value throws a
`RuntimeException`, so a typo never means `permissive`. The added `--disable-local-file-access`
matters for a binary other than the bundled one: wkhtmltopdf 0.12.5 and older allow local file
access by default. The default stays `permissive` in 1.x so existing renders that rely on
`enable-local-file-access` keep working; consider setting `allowlist` and moving to `allowPath()`.

```env
WKHTMLTOPDF_LOCAL_FILE_ACCESS=allowlist
```

Under the Docker strategy each `allowPath()` folder is bind-mounted read-only into the container at
the same path (from 1.2.0), so `file://` URLs into it work as with the binary. The folder must then
be an existing directory, and `/`, top-level directories, whole home directories and system trees
such as `/etc` are refused; `enableLocalFileAccess()` reaches only the temp dir there. HTML that
inlines its assets (`data:` URIs) needs none of this and can run with `deny`. Details, and what each
mode did in our renders: [docs/troubleshooting.md](docs/troubleshooting.md#local-file-access-and-file-urls).

### Docker hardening

From 1.2.0 the Docker strategy runs each container with:

- `--user <uid>:<gid>` of the PHP process (from the posix extension, else `id -u`/`id -g`). The
  image has no `USER`, so before 1.2.0 the container ran as root and the PDF it wrote into the
  shared temp dir was root-owned, which the PHP user could not delete from a sticky `/tmp` (#3).
- `--init`, so signals reach wkhtmltopdf and stray child processes are reaped.
- `--name wkhtmltopdf-<random>`. When a render times out, throws, or PHP shuts down mid-render
  (including on a fatal error such as `max_execution_time`), the container is removed with
  `docker rm -f <name>`. Before 1.2.0 a timeout only stopped the `docker` CLI and the container
  kept running (#3). A PHP process killed with SIGKILL runs no cleanup at all; that container
  lives until wkhtmltopdf exits.
- `-v <dir>:<dir>:ro` for each `allowPath()` folder, checked first (see
  [Local file access](#local-file-access)). Before 1.2.0 only the temp dir was mounted, so those
  folders were allowed but missing in the container.
- `--network <name>` only when `WKHTMLTOPDF_DOCKER_NETWORK` is set. The default network stays, so
  HTML can load http(s) assets. `WKHTMLTOPDF_DOCKER_NETWORK=none` cuts the container off from the
  network, for HTML that loads nothing remote.

**Membership of the `docker` group is root-equivalent.** Whoever can talk to the Docker daemon can
start a container that mounts the host's `/` and change anything on it. Adding the PHP user to
that group therefore gives PHP (and anything that gets code execution in PHP) root on the host.
Prefer the native binary where it runs. Where Docker is needed, consider
[rootless Docker](https://docs.docker.com/engine/security/rootless/), whose daemon runs as an
unprivileged user, so the socket grants that user's rights instead of root.

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

`ghcr.io/restruct/wkhtmltopdf:0.12.6`: public, multi-arch (`linux/amd64` + `linux/arm64`). Ubuntu 22.04 with the official final wkhtmltopdf deb and all runtime libraries frozen in.

From 1.2.0 the default reference is pinned to the digest of the multi-platform index:

```
ghcr.io/restruct/wkhtmltopdf:0.12.6@sha256:3b95bf589c125dbcbbb0e33619d1241953d6da019d80272d85858816d01c8396
```

With a digest, Docker pulls exactly that index and ignores the tag, so re-pushing (or hijacking)
the `0.12.6` tag cannot change what runs. Each platform's manifest is itself content-addressed
from the index, so one digest covers amd64 and arm64. To check what the registry serves:

```bash
docker buildx imagetools inspect ghcr.io/restruct/wkhtmltopdf:0.12.6 | grep '^Digest'
# or without Docker:
TOKEN=$(curl -s "https://ghcr.io/token?scope=repository:restruct/wkhtmltopdf:pull" | sed 's/.*"token":"\([^"]*\)".*/\1/')
curl -sI -H "Authorization: Bearer $TOKEN" -H "Accept: application/vnd.oci.image.index.v1+json" \
  https://ghcr.io/v2/restruct/wkhtmltopdf/manifests/0.12.6 | grep -i docker-content-digest
```

Both must print the digest above. If you set `WKHTMLTOPDF_DOCKER_IMAGE` yourself, pin it the same
way. Backups of the image and the `.deb` files it was built from, and how to restore or rebuild
from them, are described in [HOW-IT-WORKS.md](HOW-IT-WORKS.md#backups-restore-and-rebuild).

Built from [`build/Dockerfile`](build/Dockerfile):

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
bootstrap.php        constant resolution (WKHTMLTOPDF_PATH / _DOCKER_IMAGE / _DOCKER_NETWORK / _LOCAL_FILE_ACCESS)
src/
  WkhtmltoPdf.php        fluent HTML→PDF API (Snappy replacement)
  WkhtmltoPdfStatic.php  strategy resolution + process execution
  WkhtmltoPdfResult.php  output/errorOutput/exitCode value object
x64/mac/, x64/linux/     bundled 0.12.6.1 patched-Qt binaries
build/                   Dockerfile + multi-arch build script
tests/                   PHPUnit suite and the visual check (not in dist installs)
```

## Running the tests

```bash
composer install
vendor/bin/phpunit
```

The render tests need a working wkhtmltopdf (the bundled binary or the Docker image) and are
skipped without one.

## Visual check

A change of wkhtmltopdf version, of strategy (bundled binary, native install, Docker) or of the
fonts on a host can change the size and placement of everything on a page without any error. The
visual check renders a fixed fixture and compares the result, so such a change shows up as a red
test with a diff image instead of in a client's documents. It lives in `tests/Visual/` (dev only,
not in dist installs) and needs `restruct/xpdf-static` (require-dev, for `pdfinfo` and
`pdftopng`) and ext-gd.

What it compares, per render:

- **Geometry**: page count and the size of every page in points (`pdfinfo`), exactly.
- **Pixels**: every page rasterised at 100 dpi (`pdftopng`) and compared with GD. A pixel differs
  when one RGB channel differs by more than 64; a page fails when more than 0.1 % of its pixels
  differ (about 970 px on A4, a few glyphs). On failure it writes the PDF, the page PNGs and a diff
  image (differing pixels red over a washed-out expected page) to `tests/Visual/out/` (or
  `$WKHTMLTOPDF_VISUAL_OUT`).

### The fixture

`tests/Visual/fixture/` holds plain HTML and CSS with everything sized in mm: a frame exactly on
the paper edge and one 10 mm in, rulers with a tick per mm, a 50 x 50 mm box, a generated PNG
(`make-image.php`, no SVG) shown at 50 x 25 mm, a monospace block with known line wraps (five
10-digit groups per line), host-font samples in several sizes, a table with mm column widths,
forced and avoided page breaks, and an HTML header and footer. The cases use the option sets that
real document pipelines need:

| Case | Options | Pages |
|---|---|---|
| `fullbleed` | margins `0px`, `--enable-local-file-access`, `--disable-smart-shrinking`; two exact 210 x 297 mm page boxes, the second with its text pushed 210 mm down by padding | 2 x A4 |
| `lowquality` | `fullbleed` plus `--lowquality` (knp-snappy passes it by default, this wrapper does not) | 2 x A4 |
| `flow` | margins 16 mm top, 20 mm bottom, 0 sides; flowing content with `page-break-before/after/inside`, an element pulled 16 mm up into the margin | 3 x A4 |
| `header-footer` | margins 25 / 20 mm, `--header-html`, `--footer-html` (page X / Y), spacing 5 mm | 2 x A4 |

Fonts are host fonts only: `'DejaVu Sans Mono', monospace` for the fixed text (DejaVu comes with
the apt line above; macOS falls back to its own monospace font), and `sans-serif`,
`Arial, Helvetica, sans-serif` and `serif` for the samples, as most templates use. Line heights
are fixed in mm wherever text flows, so the page count does not depend on the fonts; the pixels
do, which is how a host font change is caught. An `@font-face` webfont was tried and dropped: the
bundled macOS binary applied it in only 30 of 40 renders of the same files (loaded by file URL,
as a `data:` URI, and with `--javascript-delay 1000`) and used a system font in the others, which
no fixed baseline can absorb. Keep that in mind for real templates too. Later measurements
(webfont formats, `javascript-delay`, Docker): [docs/troubleshooting.md](docs/troubleshooting.md#fonts).

### Baselines per platform

Fonts and the macOS and Linux builds of wkhtmltopdf render text differently, so the baseline PNGs
are per platform: `tests/Visual/baselines/darwin/` (bundled macOS binary) and
`tests/Visual/baselines/linux/` (bundled Linux binary on Ubuntu 24.04 with the apt line above).
The platform is where the rendering happens, so the Docker strategy compares against `linux` even
on a Mac. Between the two, every mm-placed element (frames, rulers, box, image, table) lands on the
same pixel; only the text differs. On the CI runner the generic `serif` and `monospace` families
render in a sans-serif font, so name a real font family when a template needs one: on Linux
wkhtmltopdf resolves only real family names, not generic families or aliases (see
[docs/troubleshooting.md](docs/troubleshooting.md#fonts)). Docker on Apple Silicon runs the arm64
image, a different build; for this fixture its pages are pixel-identical to the amd64 image and to
the bundled binary on a stock Ubuntu 24.04, and all three differ from the `linux` baselines only in
the text samples, because the GitHub runner has more fonts installed (measured 2026-10-12).

### Running it

```bash
vendor/bin/phpunit --group visual                                   # strategy "auto"
WKHTMLTOPDF_VISUAL_STRATEGY=docker vendor/bin/phpunit --group visual
```

The tests are skipped when ext-gd, xpdf or the renderer is missing (CI treats a skip as a
failure). Strategies: `auto` (whatever this package resolves, through the wrapper), `bundled`,
`native` (`/usr/local/bin/wkhtmltopdf`), `docker` or `docker:<image>`, or a path to any
wkhtmltopdf binary. A platform without baselines fails rather than passes, and proposes its render
as baselines under `out/proposed-baselines/`.

The group also holds must-fail controls: the `fullbleed` case rendered with smart shrinking back
on, `--zoom 1.02`, a 4 px left margin and `--page-size Letter` must each be reported as different.
If one of those ever passes, the check has gone blind to that kind of change.

The same check without PHPUnit, plus a direct comparison of two strategies or binaries (no
baselines involved), for use before switching a host:

```bash
php tests/Visual/visual-check.php check --strategy=bundled
php tests/Visual/visual-check.php compare bundled docker
php tests/Visual/visual-check.php compare bundled /usr/local/bin/wkhtmltopdf --case=fullbleed
php tests/Visual/visual-check.php compare bundled bundled --b-opt=zoom=1.02    # shows red
php tests/Visual/visual-check.php baseline --strategy=bundled                 # rewrite baselines
```

Exit code 0 is a pass, 1 differences, 2 a usage error or missing prerequisite. Look at new
baseline PNGs before committing them: a baseline is a claim that this output is right.

### What it showed on the bundled macOS binary (0.12.6)

Measured with `compare bundled bundled --b-opt=...` against the `fullbleed` case:

- Smart shrinking on (the default) scales the page content to roughly 80 %: 9.3 % of page 1's
  pixels differ, while page count and page size stay the same. Only the pixel comparison sees it.
- `--zoom 1.02` and even `--zoom 1.005` push the 297 mm page boxes over the page edge: 4 pages
  instead of 2.
- `--dpi 300` changes the layout (7.2 % of page 1 differs). It is not a must-fail control because
  its effect on Linux is not measured yet.
- `--lowquality` gives pixel-identical pages for this fixture at 100 dpi.
- wkhtmltopdf refuses mixed margin units (`--margin-top 0px --margin-left 1mm` exits 1 with
  "Currently all margin units must be the same!").
- QtWebKit floors border widths to whole CSS px: a 0.2 mm border (0.76 px) is not drawn at all.
- `page-break-after: avoid` on the line after a heading is not honoured; `page-break-inside:
  avoid` on a block is.

## Troubleshooting

[docs/troubleshooting.md](docs/troubleshooting.md) collects what we measured: fonts (generic
families on Linux, WOFF2, webfonts on the macOS binary), local file access per mode and under
Docker, page size and smart shrinking, binaries that do not start (exit 127), timeouts and leftover
containers, and how to verify a restored image backup.

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
