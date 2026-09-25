# How this all works — the Docker story behind wkhtmltopdf-static

*A from-scratch explainer written July 2026, for re-reading years later when the details have faded. Assumes no Docker knowledge.*

## The problem being solved

wkhtmltopdf is **dead software we still depend on**. The project is archived; 0.12.6.1-3 (patched Qt) is the final release, forever. The binary isn't self-contained — it needs a pile of old shared libraries (patched Qt, X11 libs, font libs, old libjpeg/libssl versions) that every new Ubuntu/macOS release ships differently or drops entirely. Nobody will ever rebuild wkhtmltopdf for future OS releases.

So this package answers one question: **how do we keep a working wkhtmltopdf runnable on machines of the future?** Two-part answer:

1. **Bundled native binaries** (`x64/mac`, `x64/linux`) — fastest, used while host OSes still run them.
2. **A Docker image** — a *time capsule* containing Ubuntu 22.04 (the last OS wkhtmltopdf officially supports) with wkhtmltopdf and every library it needs frozen inside. This runs identically on any host with Docker, forever, regardless of what the host OS ships.

## Docker in 60 seconds: image vs container

- An **image** is a frozen snapshot of a mini-Linux filesystem — think "a zip file of an entire OS userland with software pre-installed." It's inert; nothing runs.
- A **container** is a running *process* that uses that snapshot as its private filesystem. It is **not** a VM — just a normal Linux process wearing an OS-shaped costume. Starts in milliseconds, vanishes when done.

Analogy: image = class, container = instance.

## Three ways Docker gets used around here (don't confuse them)

| Pattern | What Docker does | Example |
|---|---|---|
| **Runtime time capsule** | The container IS the runtime environment; a container runs per invocation | **this package** — wkhtmltopdf runs inside the container |
| **Build machine, extract binary** | Docker compiles a *static* binary in a disposable container; the binary is extracted and ships in a composer package; Docker not needed at runtime | `restruct/dot-static` (Graphviz), the `dot-static-build` image |
| **Dev environment** | Whole dev stack (webserver, DB) in containers | DDEV images in OrbStack |

**Nothing in this package is compiled.** wkhtmltopdf is a prebuilt binary from upstream's final release. The compile-and-extract pattern doesn't work here because wkhtmltopdf can't realistically be statically compiled (the patched Qt build is enormous and fragile) — hence the runtime-container approach. cpdf and xpdf, by contrast, ARE truly static binaries with zero library dependencies, so they ship as plain files with no Docker involvement.

## What happens when PHP generates a PDF

`WkhtmltoPdfStatic::run()` decides per call — **native binary first, Docker second**:

```
PDF requested
 ├─ WKHTMLTOPDF_PATH set and executable?
 │    → YES: run it as a plain OS process. Docker is not involved AT ALL.
 └─ NO: WKHTMLTOPDF_DOCKER_IMAGE set and docker daemon responding?
      → YES: docker run --rm -v /tmp:/tmp ghcr.io/restruct/wkhtmltopdf:0.12.6 in.html out.pdf
             1. Fresh container spins up from the image (~100–300 ms overhead)
             2. wkhtmltopdf inside reads /tmp/in.html — the -v flag maps the host's
                temp dir into the container so both see the same files
             3. Writes /tmp/out.pdf, process exits
             4. --rm deletes the container instantly; nothing persists between runs
      → NO: RuntimeException "wkhtmltopdf is not available"
```

So when the Docker path is active: **one short-lived throwaway container per conversion**. No daemon of ours, no long-running service, no state. The image sits on disk; containers blink in and out of existence per PDF.

### What each machine actually does (state as of July 2026)

- **Production server** (`your-server`, x86_64, Ubuntu 24.04): has a **native** wkhtmltopdf 0.12.6.1 at `/usr/local/bin/wkhtmltopdf` — every PDF runs as a plain process. Docker is **not even installed** there. The image is the insurance policy for the day an OS upgrade breaks the native binary: install Docker, pull the image, done — the code path already exists.
- **Mac dev (Apple Silicon)**: the bootstrap finds the bundled `x64/mac` binary, which runs via **Rosetta 2** (Apple's transparent Intel→ARM translation). So local dev also uses the native-binary path by default. To force the Docker path locally (native arm64, byte-identical environment to what production-via-Docker would use): make `WKHTMLTOPDF_PATH` unresolvable and let the image default kick in.

## Multi-arch: one name, two builds

CPUs speak different instruction sets: Intel/AMD servers = `amd64`, Apple Silicon (and modern ARM servers) = `arm64`. A binary for one won't run on the other (without emulation). The image is therefore built **twice** from the same Dockerfile — `ARG TARGETARCH` makes the recipe download the matching `.deb` (`jammy_amd64` / `jammy_arm64`).

Both builds are pushed under **one name**. The registry keeps a **manifest index** — a menu card: "for this tag, here's the amd64 variant, here's the arm64 variant." `docker pull` reads the menu and automatically grabs the variant matching the machine's CPU:

```
ghcr.io/restruct/wkhtmltopdf:0.12.6      ← the only name anyone ever configures
  ├─ linux/amd64  ← what the Forge server gets
  └─ linux/arm64  ← what an Apple Silicon Mac gets
```

Same config line everywhere, native speed everywhere. That's the whole point of multi-arch.

## Registries, login, push

A **registry** is an app store for images. `ghcr.io` = GitHub Container Registry (images live next to the repos under the same org). This package's image is **public**: any machine can `docker pull` it without credentials — which is exactly what a fresh server needs, and legally fine because wkhtmltopdf is LGPL (redistribution allowed). *(Contrast: cpdf is AGPL — its binaries must NOT be published publicly, which is why `restruct/cpdf-static` is a private repo.)*

**Pulling** needs no auth. **Pushing** (publishing a new/updated image) needs proof you may publish under `restruct/`: a GitHub token with `write:packages` scope, wired up once per machine via:

```bash
gh auth refresh -h github.com -s write:packages,read:packages   # add scope if missing
gh auth token | docker login ghcr.io -u <github-user> --password-stdin
```

## OrbStack's role on the Mac

Containers are **Linux** processes — macOS can't run them natively. OrbStack runs a tiny invisible Linux VM and provides the `docker` CLI against it (a lighter/faster Docker Desktop). Everything "Docker on this Mac" actually executes inside OrbStack's VM. It also bridges the filesystem transparently (that's why `-v /tmp:/tmp` just works) and uses Rosetta to run amd64 containers fast on ARM. When `build.sh` builds the amd64 variant on an Apple Silicon Mac, that's OrbStack+Rosetta doing the cross-arch work.

## Rebuilding / re-pushing the image

```bash
build/build.sh          # native-arch build, loaded locally → docker run --rm restruct/wkhtmltopdf:0.12.6 -V
build/build.sh --push   # amd64+arm64 build, pushes manifest to ghcr.io (needs login, see above)
```

Verify what the registry serves:

```bash
docker buildx imagetools inspect ghcr.io/restruct/wkhtmltopdf:0.12.6   # should list amd64 + arm64
```

### Gotchas already hit (so you don't hit them again)

1. **`ca-certificates` missing** — with `--no-install-recommends`, `wget` gets no CA store and HTTPS downloads die with exit code 5. Fixed in the Dockerfile; remember it if adding download steps.
2. **"Multi-platform build is not supported for the docker driver"** — Docker's default builder only builds the native platform. Multi-arch needs a `docker-container` driver builder; `build.sh --push` now creates one (named `multiarch`) automatically.
3. **A multi-platform build can't `--load`** into the local image store — that's why `build.sh` has two modes: default = native-arch + load (testable locally), `--push` = both arches straight to the registry.
4. The `unknown/unknown` entries in the manifest are **build attestations** (provenance metadata buildx attaches) — harmless, not a broken platform.

## Deployment on a new/upgraded server (the insurance policy, activated)

If/when the native binary stops working on a server:

```bash
# as root: install docker (Ubuntu: apt-get install docker.io, or Forge's Docker option)
docker pull ghcr.io/restruct/wkhtmltopdf:0.12.6     # no auth needed, image is public
usermod -aG docker <php-user> && systemctl restart php8.x-fpm   # <php-user>: the user PHP-FPM runs as, e.g. forge on Laravel Forge
```

Then either set `WKHTMLTOPDF_DOCKER_IMAGE=ghcr.io/restruct/wkhtmltopdf:0.12.6` in `.env`, or on Linux just remove/let the native path fail — the bootstrap defaults the image name automatically. Add `docker pull ... || true` to the deploy script to keep it fresh. Keep a host-specific walkthrough (e.g. for Laravel Forge) in your own project docs.
