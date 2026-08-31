<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

/**
 * Writes a step's full output to disk so the MCP response can stay small.
 */
final readonly class LogWriter
{
    public function __construct(private string $directory) {}

    /**
     * Where a log would have gone.
     *
     * Named in the message when a write fails, because "no log could be written"
     * without a path leaves the reader nothing to fix — and the write fails on a
     * read-only mount or a bad owner after a deploy, which is a path problem.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    public function write(string $runId, string $stepId, string $contents): ?string
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, recursive: true) && ! is_dir($this->directory)) {
            // Losing the log must not turn a real verdict into an error.
            return null;
        }

        $path = sprintf(
            '%s/%s-%s.log',
            rtrim($this->directory, '/'),
            SafeFilename::for($runId),
            SafeFilename::for($stepId),
        );

        return @file_put_contents($path, $contents) === false ? null : $path;
    }
}
