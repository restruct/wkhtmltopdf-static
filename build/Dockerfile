# syntax=docker/dockerfile:1
# (the syntax line enables RUN --mount below on any BuildKit version)
FROM ubuntu:22.04

# TARGETARCH is set automatically by buildx (amd64 | arm64) — it happens to match the
# deb suffix of the wkhtmltopdf release assets exactly, so one Dockerfile builds both
# platforms: amd64 for the Forge servers, arm64 for local dev on Apple Silicon.
ARG TARGETARCH

# sha256 of the upstream .deb per architecture, computed on download (upstream publishes no
# checksums; the amd64 value matches the one in the README). Checked for a downloaded and for a
# local .deb alike, so a rebuild can only ever install these exact two files.
ARG WKHTMLTOX_SHA256_AMD64=4f723b2691ad8638a9df960e0421d346d7315083e3583a334f33362280ddba15
ARG WKHTMLTOX_SHA256_ARM64=2095f20256661ebf0983b9311168596c9d012666e21a94bc24f304db6ac69ec5

# Install wkhtmltopdf 0.12.6.1-3 with patched Qt (required for predictable PDF output)
# This is the final release — wkhtmltopdf project is archived
# See: https://github.com/wkhtmltopdf/packaging/releases/tag/0.12.6.1-3
#
# Rebuild without the upstream download: put wkhtmltox_0.12.6.1-3.jammy_<arch>.deb (e.g. from
# the backup assets on the GitHub release) in build/debs/ and it is used instead of the download.
# Ubuntu packages still come from apt. The directory is bind-mounted for this RUN only, so the
# .deb never ends up in an image layer.
RUN --mount=type=bind,source=debs,target=/tmp/debs \
    case "${TARGETARCH}" in \
        amd64) WKHTMLTOX_SHA256="${WKHTMLTOX_SHA256_AMD64}" ;; \
        arm64) WKHTMLTOX_SHA256="${WKHTMLTOX_SHA256_ARM64}" ;; \
        *) echo "Unsupported TARGETARCH: ${TARGETARCH}" >&2; exit 1 ;; \
    esac \
    && apt-get update && apt-get install -y --no-install-recommends \
    wget \
    ca-certificates \
    xfonts-75dpi \
    xfonts-base \
    fontconfig \
    libfontconfig1 \
    libfreetype6 \
    libjpeg-turbo8 \
    libx11-6 \
    libxcb1 \
    libxext6 \
    libxrender1 \
    libpng16-16 \
    libssl3 \
    libstdc++6 \
    zlib1g \
    && if [ -f /tmp/debs/wkhtmltox_0.12.6.1-3.jammy_${TARGETARCH}.deb ]; then \
        cp /tmp/debs/wkhtmltox_0.12.6.1-3.jammy_${TARGETARCH}.deb . ; \
    else \
        wget -q https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.jammy_${TARGETARCH}.deb ; \
    fi \
    && echo "${WKHTMLTOX_SHA256}  wkhtmltox_0.12.6.1-3.jammy_${TARGETARCH}.deb" | sha256sum -c - \
    && dpkg -i wkhtmltox_0.12.6.1-3.jammy_${TARGETARCH}.deb \
    && rm wkhtmltox_0.12.6.1-3.jammy_${TARGETARCH}.deb \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Verify installation
RUN wkhtmltopdf -V

ENTRYPOINT ["wkhtmltopdf"]
