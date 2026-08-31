<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Contracts\LiveProgressStore;

/**
 * Keeps the in-flight record as one JSON file per pipeline.
 *
 * The run id is payload here, never a path component: the filename is the pipeline
 * name, which the factory already validated. So a caller-supplied run id reaches no
 * path through this store, unlike a history filename.
 */
final readonly class JsonLiveProgressStore implements LiveProgressStore
{
    public function __construct(private string $path) {}

    public function write(LiveProgress $progress): bool
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, recursive: true) && ! is_dir($directory)) {
            // Losing the record must not turn a real verdict into an error.
            return false;
        }

        $json = json_encode($progress->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json !== false && @file_put_contents($this->path, $json.PHP_EOL) !== false;
    }

    public function read(): ?LiveProgress
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? LiveProgress::fromArray($data) : null;
    }

    /**
     * Read, compare, delete — and the three are not one atomic step.
     *
     * A writer can replace the file between the comparison and the unlink, and
     * this would then delete that newer record. The token removes the ordinary
     * case, where a server clears a record another server has held for a while;
     * it does not close the race. The package declines to coordinate concurrent
     * callers at all, and a briefly blank page is the worst this costs.
     */
    public function clear(string $runId, string $token): void
    {
        $current = $this->read();

        if (! $current instanceof LiveProgress || $current->runId !== $runId || $current->token !== $token) {
            return;
        }

        @unlink($this->path);
    }
}
