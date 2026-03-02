<?php

namespace Restruct\WkhtmltoPdf;

/**
 * Result of a wkhtmltopdf execution.
 */
class WkhtmltoPdfResult
{
    public function __construct(
        public readonly string $output,
        public readonly string $errorOutput,
        public readonly int $exitCode,
    ) {
    }

    /**
     * Whether the command succeeded (exit code 0).
     */
    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Get output as array of lines (empty lines removed).
     *
     * @return string[]
     */
    public function getLines(): array
    {
        return array_values(array_filter(
            explode("\n", $this->output),
            fn($line) => $line !== ''
        ));
    }
}
