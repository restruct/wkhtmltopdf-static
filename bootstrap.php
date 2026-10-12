<?php

/**
 * Bootstrap for restruct/wkhtmltopdf-static
 *
 * OS-aware path resolver for wkhtmltopdf binary + Docker image detection.
 * Safe to include multiple times — guarded by defined() checks.
 *
 * Defines these constants:
 * - WKHTMLTOPDF_PATH: path to the direct binary (macOS dev, or native Linux install)
 * - WKHTMLTOPDF_DOCKER_IMAGE: Docker image name for containerized execution
 * - WKHTMLTOPDF_DOCKER_NETWORK: `docker run --network` value, empty = Docker's default (from 1.2.0)
 * - WKHTMLTOPDF_LOCAL_FILE_ACCESS: permissive | allowlist | deny (from 1.2.0, default permissive),
 *   see WkhtmltoPdfStatic::applyLocalFileAccessPolicy()
 *
 * Priority for WKHTMLTOPDF_PATH:
 * 1. Already defined constant (co-existence with docsys-tools)
 * 2. WKHTMLTOPDF_PATH environment variable
 * 3. macOS: bundled x64/mac/wkhtmltopdf
 * 4. Linux: system-installed /usr/local/bin/wkhtmltopdf → bundled x64/linux/wkhtmltopdf
 *
 * Each candidate path (env var included) must pass a probe: it is run once with --version and
 * must exit 0 (WkhtmltoPdfStatic::probeBinary(), cached per process). A binary that is executable
 * but cannot start, e.g. because a shared library is missing, falls through to the next candidate.
 *
 * Priority for WKHTMLTOPDF_DOCKER_IMAGE:
 * 1. Already defined constant
 * 2. WKHTMLTOPDF_DOCKER_IMAGE environment variable
 * 3. Default: 'ghcr.io/restruct/wkhtmltopdf:0.12.6@sha256:...' (pinned by digest from 1.2.0)
 *
 * WKHTMLTOPDF_DOCKER_NETWORK and WKHTMLTOPDF_LOCAL_FILE_ACCESS: already defined constant, then the
 * environment variable of the same name, then the default.
 */

if (!defined('WKHTMLTOPDF_PATH')) {
    $wkPath = null;

    # Probe that the candidate can actually start, not just that it has the exec bit (issue #6).
    # Falls back to is_executable() only if this file is included without the package autoloader.
    $wkUsable = static function (string $candidate): bool {
        return class_exists(\Restruct\WkhtmltoPdf\WkhtmltoPdfStatic::class)
            ? \Restruct\WkhtmltoPdf\WkhtmltoPdfStatic::probeBinary($candidate)
            : is_executable($candidate);
    };

    # Check environment variable first
    $envPath = getenv('WKHTMLTOPDF_PATH');
    if ($envPath !== false && $envPath !== '' && $wkUsable($envPath)) {
        $wkPath = $envPath;
    }

    if ($wkPath === null) {
        switch (PHP_OS_FAMILY) {
            case 'Darwin':
                # macOS: use bundled binary (Intel, runs on ARM via Rosetta 2)
                $candidate = __DIR__ . '/x64/mac/wkhtmltopdf';
                if ($wkUsable($candidate)) {
                    $wkPath = $candidate;
                }
                break;

            case 'Linux':
                # Linux: prefer system-installed, fall back to bundled
                $candidates = [
                    '/usr/local/bin/wkhtmltopdf', # System install (Debian/Ubuntu package)
                    __DIR__ . '/x64/linux/wkhtmltopdf', # Bundled Ubuntu 22.04+ binary (jammy build, OpenSSL 3; up to 1.0.3 it was a 20.04+ build needing OpenSSL 1.1)
                ];
                foreach ($candidates as $candidate) {
                    if ($wkUsable($candidate)) {
                        $wkPath = $candidate;
                        break;
                    }
                }
                break;
        }
    }

    if ($wkPath !== null) {
        define('WKHTMLTOPDF_PATH', $wkPath);
    }
}

if (!defined('WKHTMLTOPDF_DOCKER_IMAGE')) {
    $dockerImage = null;

    # Check environment variable
    $envImage = getenv('WKHTMLTOPDF_DOCKER_IMAGE');
    if ($envImage !== false && $envImage !== '') {
        $dockerImage = $envImage;
    }

    # Default Docker image (only set on Linux where Docker is typically available)
    if ($dockerImage === null && PHP_OS_FAMILY === 'Linux') {
        # Pinned by the digest of the multi-platform index (amd64 + arm64), so a re-pushed or
        # hijacked 0.12.6 tag cannot change what runs; Docker ignores the tag when a digest is given.
        # The tag stays for readability. How to verify the digest: README, "The Docker image".
        # $dockerImage = 'ghcr.io/restruct/wkhtmltopdf:0.12.6';
        $dockerImage = 'ghcr.io/restruct/wkhtmltopdf:0.12.6@sha256:3b95bf589c125dbcbbb0e33619d1241953d6da019d80272d85858816d01c8396';
    }

    if ($dockerImage !== null) {
        define('WKHTMLTOPDF_DOCKER_IMAGE', $dockerImage);
    }
}

if (!defined('WKHTMLTOPDF_DOCKER_NETWORK')) {
    # Empty = Docker's default network, so HTML can still load http(s) assets. 'none' cuts the
    # container off from the network entirely (only for HTML that needs nothing remote).
    $envNetwork = getenv('WKHTMLTOPDF_DOCKER_NETWORK');
    define('WKHTMLTOPDF_DOCKER_NETWORK', $envNetwork !== false ? $envNetwork : '');
}

if (!defined('WKHTMLTOPDF_LOCAL_FILE_ACCESS')) {
    # Default stays permissive in 1.x: existing projects pass enable-local-file-access to load
    # local images, and refusing it would break their renders. Validated in WkhtmltoPdfStatic.
    $envLocal = getenv('WKHTMLTOPDF_LOCAL_FILE_ACCESS');
    define('WKHTMLTOPDF_LOCAL_FILE_ACCESS', $envLocal !== false && $envLocal !== '' ? $envLocal : 'permissive');
}
