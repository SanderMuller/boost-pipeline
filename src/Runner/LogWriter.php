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

        $path = sprintf(
            '%s/%s-%s.log',
            rtrim($this->directory, '/'),
            $this->filenameSafe($runId),
            $this->filenameSafe($stepId),
        );

        return @file_put_contents($path, $contents) === false ? null : $path;
    }

    /**
     * A step id is whatever the pipeline config passed to `Shell::run(id: ...)`,
     * and only derived ids are slugged — so an explicit one arrives verbatim and
     * would otherwise put separators, or `..`, straight into the path.
     */
    private function filenameSafe(string $component): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $component);

        if ($safe === $component) {
            return $safe;
        }

        // 'a/b' and 'a b' both reduce to 'a-b', and the walk checks id uniqueness
        // on the raw ids, so two steps that only differ in stripped characters would
        // write to one file. Suffix the ones that were actually rewritten.
        return $safe.'-'.substr(hash('xxh3', $component), 0, 6);
    }
}
