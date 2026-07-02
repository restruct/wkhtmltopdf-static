#!/bin/bash
#
# Build and optionally push the wkhtmltopdf Docker image (multi-arch).
#
# Usage:
#   ./build.sh          # Build native-arch image locally (loaded into docker, for testing)
#   ./build.sh --push   # Build linux/amd64 + linux/arm64 and push manifest to ghcr.io
#
# Multi-arch rationale: the same ghcr tag resolves to amd64 on the Forge servers
# (x86_64) and native arm64 on Apple Silicon dev machines — identical patched-Qt
# build everywhere, no Rosetta/qemu at runtime.
#
# Note: a multi-platform build cannot be --load'ed into the local docker image
# store (single-arch limitation of the classic store), so --push builds both
# platforms and pushes directly, while the default mode builds only the native
# platform and loads it for local smoke testing.
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
IMAGE_NAME="restruct/wkhtmltopdf"
IMAGE_TAG="0.12.6"
GHCR_IMAGE="ghcr.io/${IMAGE_NAME}:${IMAGE_TAG}"
PLATFORMS="linux/amd64,linux/arm64"

if [ "$1" = "--push" ]; then
    echo "Building ${GHCR_IMAGE} for ${PLATFORMS} and pushing..."
    # Multi-platform builds need the docker-container driver — the default "docker"
    # driver can only build the native platform. Create the builder once, reuse after.
    if ! docker buildx inspect multiarch >/dev/null 2>&1; then
        docker buildx create --name multiarch --driver docker-container
    fi
    # Requires: docker login ghcr.io (PAT with write:packages scope)
    docker buildx build \
        --builder multiarch \
        --platform "${PLATFORMS}" \
        -t "${GHCR_IMAGE}" \
        --push \
        "$SCRIPT_DIR"
    echo "Done: ${GHCR_IMAGE} (${PLATFORMS})"
else
    echo "Building ${IMAGE_NAME}:${IMAGE_TAG} for native platform (local test build)..."
    docker buildx build \
        -t "${IMAGE_NAME}:${IMAGE_TAG}" \
        -t "${GHCR_IMAGE}" \
        --load \
        "$SCRIPT_DIR"
    echo "Build complete. Run with --push to build ${PLATFORMS} and push to ghcr.io"
fi
