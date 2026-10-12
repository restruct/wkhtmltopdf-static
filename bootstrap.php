<?php

/**
 * Bootstrap for restruct/wkhtmltopdf-static
 *
 * OS-aware path resolver for wkhtmltopdf binary + Docker image detection.
 * Safe to include multiple times — guarded by defined() checks.
 *
 * Defines two constants:
 * - WKHTMLTOPDF_PATH: path to the direct binary (macOS dev, or native Linux install)
 * - WKHTMLTOPDF_DOCKER_IMAGE: Docker image name for containerized execution
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
 * 3. Default: 'ghcr.io/restruct/wkhtmltopdf:0.12.6'
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
        $dockerImage = 'ghcr.io/restruct/wkhtmltopdf:0.12.6';
    }

    if ($dockerImage !== null) {
        define('WKHTMLTOPDF_DOCKER_IMAGE', $dockerImage);
    }
}
