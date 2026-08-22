<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

/**
 * Writes a step's full output to disk so the MCP response can stay small.
 */
final readonly class LogWriter
{
    public function __construct(private string $directory) {}

    public function write(string $runId, string $stepId, string $contents): ?string
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, recursive: true) && ! is_dir($this->directory)) {
            // Losing the log must not turn a real verdict into an error.
            return null;
        }

        $path = sprintf('%s/%s-%s.log', rtrim($this->directory, '/'), $runId, $stepId);

        return file_put_contents($path, $contents) === false ? null : $path;
    }
}
