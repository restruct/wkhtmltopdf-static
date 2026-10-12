# Troubleshooting wkhtmltopdf-static

What we measured about fonts, local files, page size, binaries that do not start, and Docker
containers, so a broken PDF can be traced to a known cause. Every claim below comes from a render
we ran or from a test in this repository; anything we did not run is marked **unverified**.

Measured on 2026-10-12 unless noted, with:

- the bundled macOS binary (`x64/mac/wkhtmltopdf`, 0.12.6.1, x86_64 under Rosetta 2 on Apple Silicon);
- the Docker image `ghcr.io/restruct/wkhtmltopdf:0.12.6` (arm64 and amd64 variants of the pinned
  index, loaded from the release backup; Docker 29.4.0 in OrbStack);
- the bundled Linux binary inside an `ubuntu:24.04` amd64 container with exactly the README apt line
  ("stock 24.04" below);
- the GitHub Actions `ubuntu-24.04` runner, through the committed `tests/Visual/baselines/linux`.

## Fonts

### Generic `serif` and `monospace` render as the default sans font on Linux

On Linux (bundled binary on stock 24.04, and the Docker image) wkhtmltopdf only finds a font by its
**real family name**. Everything else falls back to the default sans font:

| `font-family` | Docker image / stock 24.04 renders | bundled macOS binary renders |
|---|---|---|
| `serif` | DejaVu Sans | Times New Roman |
| `monospace` | DejaVu Sans | Courier New |
| `sans-serif` | DejaVu Sans | Helvetica |
| `'Times New Roman'`, `Times`, `Georgia`, `Courier`, `'Courier New'` | DejaVu Sans | Times New Roman (also for `Times`), Georgia, Courier New (also for `Courier`) |
| `cursive`, `fantasy` | DejaVu Sans | Apple Chancery, Papyrus |
| `'DejaVu Serif'` | DejaVu Serif | Times New Roman (not installed, so the default) |
| `'DejaVu Sans Mono'` | DejaVu Sans Mono | Times New Roman (not installed, so the default) |

Checked on the rendered page and with `pdffonts`: the Docker PDF embeds only DejaVuSans,
DejaVuSerif and DejaVuSansMono, the last two only for the lines that name them. Note the opposite
defaults: an unknown family falls back to a **sans** font on Linux and to a **serif** font (Times
New Roman) on the macOS binary.

This is not a missing font. In the same image `fc-match serif` returns `DejaVu Serif`,
`fc-match monospace` returns `DejaVu Sans Mono` and `fc-match Times` returns `DejaVu Serif`: the
fonts are installed and fontconfig knows the aliases, but the patched Qt in wkhtmltopdf does not use
fontconfig's generic or alias substitution. The image and stock 24.04 have the same six DejaVu
files (Sans, Sans Mono, Serif, regular and bold), and their renders of the visual fixture are
pixel-identical.

On the GitHub runner, which has many more fonts installed, all four sample lines of the visual
fixture (`sans-serif`, `Arial, Helvetica, sans-serif`, `serif`, `monospace`) render in one
Arial-like face instead of DejaVu Sans. Which font that is was not checked (**unverified**); it is
why the `linux` baselines differ from Docker renders on the text samples (see
[Docker versus the Linux baselines](#docker-versus-the-linux-baselines)).

**What to do:** put the installed family first and keep the generic as the last resort:

```css
font-family: 'DejaVu Serif', 'Times New Roman', serif;
font-family: 'DejaVu Sans Mono', 'Courier New', monospace;
```

or ship the font with the template (next section). Installing `fonts-liberation` or similar only
helps when the template names those families (`'Liberation Serif'`) explicitly (**unverified**:
inferred from the table above, not rendered).

### Webfonts (`@font-face`)

Test page: one line set in an `@font-face` family with a fallback, rendered 10 times per
combination on the macOS binary and in the arm64 image, 3 times in the amd64 image, and compared
with a render without the `@font-face` rule:

| Font source | macOS binary | Docker arm64 | Docker amd64 |
|---|---|---|---|
| TTF by absolute `file://` URL | 10/10 applied | 10/10 | 3/3 |
| TTF by relative URL | 10/10 | 10/10 | 3/3 |
| TTF, no `format()` hint | 10/10 | 10/10 | 3/3 |
| TTF as `data:` URI | 10/10 | 10/10 | 3/3 |
| WOFF by `file://` URL | 10/10 | 10/10 | 3/3 |
| WOFF as `data:` URI | 10/10 | 10/10 | 3/3 |
| WOFF2 by `file://` URL | **0/10** | **0/10** | **0/3** |
| WOFF2 as `data:` URI | **0/10** | **0/10** | **0/3** |

Each row was run with `--javascript-delay 0` and with `3000`; the results were the same.

- **WOFF2 never works.** It is a format support problem: the QtWebKit in wkhtmltopdf predates WOFF2.
  The render exits 0 and silently uses the fallback font. Use TTF or WOFF.
- **It is not timing.** A longer `--javascript-delay` changed nothing above, and did not help with
  the macOS problem below either. (`--window-status` was not tried: with the delay making no
  difference there was no load race to wait for.)
- **A blocked font file fails the whole render.** Without `--allow`/`--enable-local-file-access` a
  `file://` font URL makes wkhtmltopdf exit 1 with `ProtocolUnknownError` and write a blank one-page
  PDF. The same font as a `data:` URI renders under `--disable-local-file-access`, exit 0. See
  [Local file access](#local-file-access-and-file-urls).

#### The macOS binary renders webfonts nondeterministically

On the full visual fixture (two pages, a TTF webfont for the monospace text next to several host
fonts at several sizes), 20 renders of the same files on the macOS binary gave 3 to 4 different
page-1 images. The odd ones differ in single glyphs (some digits and letters drawn from another
font), or in all text of the webfont:

| Run | identical page-1 images out of 20 |
|---|---|
| DejaVu Sans Mono TTF, `--javascript-delay 0` | 15, 3, 1, 1 |
| same, `--javascript-delay 200` | 16, 2, 1, 1 |
| same, `--javascript-delay 3000` | 12, 3, 3, 2 |
| Comic Sans TTF instead (so not font-specific) | 14, 3, 3 |
| repository fixture, host fonts only, no `@font-face` | **20** |
| same webfont fixture in the Docker arm64 image | **20** |

Smaller pages show it less often: a one-paragraph page with the webfont at one size gave 20 of 20
identical, the same paragraph plus three host-font lines 19 of 20, and pushed onto page 2 18 of 20.
The trigger was not isolated further. This is why the visual fixture uses no webfont (README,
"The fixture"). For output that must be identical every time, render on Linux or in Docker; on the
macOS binary expect the occasional wrong glyph when a template uses `@font-face`.

### How to embed a font reliably

1. TTF or WOFF, never WOFF2 only. With several `src` entries, put TTF/WOFF first.
2. Either inline it as a `data:` URI (needs no local file access, works with
   `WKHTMLTOPDF_LOCAL_FILE_ACCESS=deny`), or keep it in a folder you pass to `allowPath()`.
3. Name a real family as the fallback, not only a generic (see above).

## Local file access and file:// URLs

Results of the same three renders (no local file, an image in an allowed folder, an image in a
folder that is not allowed) for each mode and strategy, through the PHP wrapper, with
`allowPath()` on the allowed folder and no `enableLocalFileAccess()`:

| Mode | Image in `allowPath()` folder | Image elsewhere | No local files |
|---|---|---|---|
| `permissive` (binary and Docker) | renders | exit 1 | renders |
| `allowlist` (binary and Docker) | renders | exit 1 | renders |
| `deny` (binary and Docker) | exit 1 (and `allowPath()` itself throws before anything runs) | exit 1 | renders |

"Renders" was checked on the rasterised page (the image's pixels are there), not only the exit
code. A blocked local file gives `Exit with code 1 due to network error: ProtocolUnknownError` and,
from the binary itself, a blank PDF at the output path. `getOutputFromHtml()` throws on it;
`generateFromHtml()` returns the result, so check `isSuccessful()` rather than whether the file exists.

### Under Docker

The container sees only the temp dir (read-write) and, from 1.2.0, each `allowPath()` folder,
bind-mounted **read-only at the same path**, so a `file://` URL resolves the same as on the host.
Without that mount wkhtmltopdf allows the folder but the files are missing in the container: exit 1,
`ProtocolUnknownError` (checked with a raw `docker run` with and without the mount).

Consequences:

- `enableLocalFileAccess()` under Docker still reaches only the temp dir: nothing else is mounted.
  Use `allowPath()` for anything outside it.
- An `allowPath()` value is checked before the container starts and refused with an
  `InvalidArgumentException` when it is: not absolute; contains `.`/`..` segments, a `:` or `,`
  (Docker's mount syntax) or a NUL byte; not an existing directory (allow a single file's folder
  instead); `/` or any top-level directory (`/var`, `/home`, `/srv`, ...); a whole home directory
  (`/home/<user>`, `/Users/<user>`); in or below `/proc`, `/sys`, `/dev`, `/etc`, `/root`, `/boot`,
  `/run`, `/var/run`, `/private/etc`, `/private/var/run`, `/System`, `/Library`. The path as given
  and its realpath are both checked, so a symlink cannot point a harmless name at `/etc`. Folders
  inside the temp dir are not mounted again; a symlink inside the temp dir that points out of it is
  refused (allow the target itself). These rules apply only to the Docker strategy; the binary gets
  `--allow` unchanged. See `WkhtmltoPdfStatic::dockerMountPath()`.
- A symlinked folder (for example a `current` deploy link) is mounted from its realpath at the path
  as given, so `file://` URLs written with the link path work.
- Mount the narrowest folder that holds the assets (a `pdf-assets/` folder, not the web root): it is
  read-only, but everything in it becomes readable to the HTML.
- **Output outside the temp dir fails under Docker.** `generateFromHtml($html, '/srv/out/x.pdf')`
  exits 1 with `Unable to write to destination`: that folder is not in the container. Render with
  `getOutputFromHtml()` and write the bytes yourself, or give an output path in `sys_get_temp_dir()`
  and move the file. The binary strategy has no such limit.
- **macOS temp dir, fixed in 1.2.0:** `sys_get_temp_dir()` is `/var/folders/...`, but `tempnam()`
  returns the resolved `/private/var/folders/...`. With only the first mounted, wkhtmltopdf did not
  find its input in the container and treated it as a URL: `Host private not found`. Both spellings
  are mounted now. Only relevant when the Docker strategy is used on a Mac.

## Page size and smart shrinking

Measured with the visual check on the bundled macOS binary (README, "What it showed"):

- Smart shrinking is on by default and scales the content to roughly 80 %: 9.3 % of page 1's pixels
  change while page count and page size stay the same. Only a pixel comparison sees it. Pass
  `disableSmartShrinking()` for mm-exact layouts.
- `--zoom 1.02`, and even `1.005`, push 297 mm page boxes over the page edge: 4 pages instead of 2.
- `--dpi 300` changes the layout (7.2 % of page 1 differs); its effect on Linux is not measured
  (**unverified**).
- Mixed margin units (`--margin-top 0px --margin-left 1mm`) exit 1: "Currently all margin units must
  be the same!".
- Border widths are floored to whole CSS px: a 0.2 mm (0.76 px) border is not drawn at all.
- `page-break-after: avoid` after a heading is not honoured; `page-break-inside: avoid` on a block is.

## A binary that does not start (exit 127)

A binary that is executable but cannot load a shared library never runs: the dynamic loader exits
127 before wkhtmltopdf starts. The bundled Linux binary up to 1.0.3 linked OpenSSL 1.1 and failed
like this on Ubuntu 22.04 and 24.04 (#6). From 1.1.0:

- every candidate binary is probed once with `--version` and must exit 0, or the next candidate
  (then Docker) is used; `WkhtmltoPdfStatic::probeBinary($path)` runs the same check;
- `ldd <binary> | grep 'not found'` names the missing library; on a stock 24.04 server that is
  usually only `libxrender1` (README, "Runtime libraries").

If renders fail and `WkhtmltoPdfStatic::getStrategy()` returns `docker` on a server where you
expected the binary, the probe failed: run the binary with `--version` by hand.

## Timeouts and leftover containers

Verified with a page whose script busy-loops for 60 s, `--javascript-delay 0` and a 5 s timeout,
through the PHP wrapper and the Docker strategy:

- during the render the container `wkhtmltopdf-<16 hex>` runs; the call returns after 5.5 s with
  `Docker process timed out after 5s`; immediately after, `docker ps -a --filter name=wkhtmltopdf-`
  lists nothing.
- Control: killing only the `docker run` CLI process (what a timeout did before 1.2.0) leaves the
  container running.
- Not covered: a PHP process killed with SIGKILL (for example by FPM's
  `request_terminate_timeout`) runs no cleanup; that container lives until wkhtmltopdf exits. Find
  leftovers with `docker ps -a --filter name=wkhtmltopdf-` and remove them with `docker rm -f`.

### Output file ownership

`--user <uid>:<gid>` makes the PDF belong to the PHP user. On a Linux file system (a Docker volume)
a render as `--user 1234:5678` wrote a file owned by 1234:5678, a render without `--user` a file
owned by root, and in a sticky directory user 1234 could not delete the root-owned file
(`Operation not permitted`). On macOS bind mounts (OrbStack) every file shows up as the Mac user
whatever the container user is, so the problem never appears there and cannot be tested there.

## Docker versus the Linux baselines

The visual fixture rendered by the Docker image (arm64 and amd64) and by the bundled Linux binary on
stock 24.04 gives pixel-identical pages for all four cases: no pixel over the check's threshold
between any two of them. So the arm64 build renders this fixture the same as the amd64 one.

Against the committed `linux` baselines (GitHub runner), all three differ on the text samples only:
`fullbleed` page 1 3.79 %, page 2 0.22 %; `header-footer` page 1 0.15 %; `flow` 0 %. Frames,
rulers, boxes, the image, the table and the `'DejaVu Sans Mono'` block land on the same pixels; the
`sans-serif`/`Arial`/`serif`/`monospace` sample lines and the heading are DejaVu Sans in Docker and
the runner's Arial-like face in the baselines (see [Fonts](#generic-serif-and-monospace-render-as-the-default-sans-font-on-linux)).
A server with only the apt line's fonts matches Docker, not the runner.

## Docker image: verifying a restored backup

`docker load` of the release backup `wkhtmltopdf-0.12.6-oci.tar` on the classic image store
(overlay2) gives `ghcr.io/restruct/wkhtmltopdf:0.12.6-amd64` and `:0.12.6-arm64` with **no repo
digest** (`RepoDigests` is empty), so the pinned `@sha256:3b95bf58...` cannot be compared with the
loaded image directly. That digest is the digest of the multi-platform **index**; what a local image
does have is its image ID, which is the digest of its **config** blob. The chain to check:

1. `sha256sum` of the index blob in the tar (`blobs/sha256/3b95bf58...`) is `3b95bf58...`, and the
   registry serves the same digest for `0.12.6` (`docker-content-digest` header, README).
2. That index lists the platform manifests: amd64 `sha256:508200c7...`, arm64 `sha256:1a1c2b97...`.
3. Their `config.digest` is amd64 `sha256:961badf5...`, arm64 `sha256:7b2cfff3...`; the registry's
   arm64 manifest names the same config.
4. `docker image inspect ghcr.io/restruct/wkhtmltopdf:0.12.6-arm64 --format '{{.Id}}'` prints
   `sha256:7b2cfff3...` (amd64: `961badf5...`).

All four held for the backup on 2026-10-12. A tag already in a local image store can be a different
image: on the test machine `ghcr.io/restruct/wkhtmltopdf:0.12.6` was an older local build
(`d286e2f5...`), not the published arm64 image, which is another reason for the digest pin. Restore
steps: HOW-IT-WORKS.md, "Backups, restore and rebuild".
