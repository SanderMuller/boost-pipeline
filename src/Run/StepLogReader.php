<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Runner\OutputSummariser;

/**
 * Reads one step's log, and only where that log is one this package wrote.
 *
 * A path is never derived from the ids in the request. It is looked up in the
 * run's own recorded `logs` map, so an id is a lookup key rather than a path
 * component — deriving one would also be wrong for a consumer that bound its own
 * runner and wrote logs elsewhere.
 *
 * The recorded path is still not trusted. A custom runner may record anything, so
 * the resolved path is checked against the log root before the file is opened.
 * `realpath()` does the resolving, which is what makes a symlink out of the root
 * fail the check rather than pass it.
 */
final readonly class StepLogReader
{
    public function __construct(
        private RunHistoryStoreFactory $history,
        private OutputSummariser $summariser,
        private string $root,
    ) {}

    /**
     * @return array{summary: string, output_lines: int, shown_lines: int, truncated: bool, clipped: bool}|null
     */
    public function read(string $pipeline, string $runId, string $stepId): ?array
    {
        $record = $this->history->for($pipeline)->read($runId);

        if (! $record instanceof HistoryRecord) {
            return null;
        }

        $path = $this->containedPath($record->logs[$stepId] ?? null);

        if ($path === null) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $this->summariser->summarise($contents);
    }

    /**
     * The recorded path, once it is proven to sit inside the log root.
     *
     * Null covers every way it can fail to: a step that wrote no log, a file that
     * has since been deleted, and a path that resolves outside the root — an
     * absolute path elsewhere, a `../` climb, or a symlink pointing away.
     */
    private function containedPath(?string $recorded): ?string
    {
        if ($recorded === null) {
            return null;
        }

        $root = realpath($this->root);
        $path = realpath($recorded);

        if ($root === false || $path === false || ! is_file($path)) {
            return null;
        }

        return str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            ? $path
            : null;
    }
}
