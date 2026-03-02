#!/bin/bash
#
# Build and optionally push the wkhtmltopdf Docker image.
#
# Usage:
#   ./build.sh          # Build only
#   ./build.sh --push   # Build + push to GitHub Container Registry
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
IMAGE_NAME="restruct/wkhtmltopdf"
IMAGE_TAG="0.12.6"
GHCR_IMAGE="ghcr.io/${IMAGE_NAME}:${IMAGE_TAG}"

echo "Building ${IMAGE_NAME}:${IMAGE_TAG}..."
docker build -t "${IMAGE_NAME}:${IMAGE_TAG}" "$SCRIPT_DIR"

echo "Tagging as ${GHCR_IMAGE}..."
docker tag "${IMAGE_NAME}:${IMAGE_TAG}" "$GHCR_IMAGE"

if [ "$1" = "--push" ]; then
    echo "Pushing to GitHub Container Registry..."
    docker push "$GHCR_IMAGE"
    echo "Done: ${GHCR_IMAGE}"
else
    echo "Build complete. Run with --push to push to ghcr.io"
fi
