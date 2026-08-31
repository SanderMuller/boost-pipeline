<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Contracts\RunHistoryStore;
use SanderMuller\BoostPipeline\Runner\SafeFilename;

/**
 * Keeps each run as one JSON file under `history/<pipeline>/`.
 *
 * Keyed by run, not by resolution. A run resolves many times, so appending per
 * resolution would store the same run over and over with a growing verdict map.
 * Overwriting the run's own file keeps one entry per run and makes the newest
 * write the whole truth about it.
 *
 * Under `storage/logs/` for the same reason the receipt is: a Laravel app already
 * ignores that directory, so using the pipeline leaves no untracked files behind.
 * Nothing here survives a push, and that is deliberate.
 */
final readonly class JsonRunHistoryStore implements RunHistoryStore
{
    /**
     * How many runs a pipeline keeps.
     *
     * Nothing in the package pruned anything before this, and the step logs are
     * still unbounded. Twenty answers "what did the last few walks do" and stops
     * a directory growing for the life of a checkout.
     */
    public const int KEEP = 20;

    public function __construct(private string $directory, private int $keep = self::KEEP) {}

    public function write(HistoryRecord $record): void
    {
        $path = $this->pathFor($record->receipt->runId);

        if ($path === null || ! $this->ensureDirectory()) {
            // Losing the record must not turn a real verdict into an error.
            return;
        }

        $json = json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        $this->writeAtomically($path, $json.PHP_EOL);
        $this->prune();
    }

    public function read(string $runId): ?HistoryRecord
    {
        $path = $this->pathFor($runId);

        return $path === null ? null : $this->recordAt($path);
    }

    /**
     * @return list<HistoryRecord>
     */
    public function all(?int $limit = null): array
    {
        $records = [];

        foreach ($this->filesNewestFirst() as $path) {
            if ($limit !== null && count($records) >= $limit) {
                break;
            }

            $record = $this->recordAt($path);

            if ($record instanceof HistoryRecord) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * The file a run id addresses, or null when it does not address one inside
     * this directory.
     *
     * A run id is caller-supplied — `Run::start()` takes one and only falls back
     * to a generated value — so it is encoded the way a log filename is, and the
     * result is then checked rather than trusted. Encoding alone is the rule;
     * the containment check is what makes a change to that rule non-fatal.
     */
    private function pathFor(string $runId): ?string
    {
        // Never empty, `.` or `..`: the encoding always appends a digest, so the
        // result cannot be a directory sentinel however odd the run id.
        $safe = SafeFilename::for($runId);
        $directory = rtrim($this->directory, '/');
        $path = $directory.'/'.$safe.'.json';

        return str_starts_with($path, $directory.'/') && ! str_contains($safe, '/')
            ? $path
            : null;
    }

    private function recordAt(string $path): ?HistoryRecord
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        // Unreadable is treated as absent, the same posture the receipt takes: a
        // history file that will not parse is not a partially usable record.
        return is_array($data) ? HistoryRecord::fromArray($data) : null;
    }

    /**
     * @return list<string>
     */
    private function filesNewestFirst(): array
    {
        $directory = rtrim($this->directory, '/');
        $entries = @scandir($directory);

        if ($entries === false) {
            return [];
        }

        // scandir rather than glob: an encoded run id can begin with a dot —
        // `../escape` becomes `..-escape-<hash>` — and glob's `*` skips those, so
        // such a run would be listed by nothing and pruned by nothing.
        $paths = [];

        foreach ($entries as $entry) {
            if (str_ends_with($entry, '.json') && is_file($directory.'/'.$entry)) {
                $paths[] = $directory.'/'.$entry;
            }
        }

        // Modification time, with the filename as the tiebreak: two writes inside
        // one second are ordinary here, and an unstable sort would reorder the
        // list between two polls of a page that has not changed.
        usort($paths, static function (string $a, string $b): int {
            $byTime = self::modifiedAt($b) <=> self::modifiedAt($a);

            return $byTime === 0 ? strcmp($b, $a) : $byTime;
        });

        return $paths;
    }

    /** An unreadable mtime sorts oldest, which is also how it prunes first. */
    private static function modifiedAt(string $path): int
    {
        $modified = @filemtime($path);

        return $modified === false ? 0 : $modified;
    }

    /**
     * Keep the newest retained runs, and delete everything else.
     *
     * Two rules, and both are needed. Only a file that holds a run counts toward
     * the cap — otherwise a handful of newer unreadable files would evict every
     * real record. And a file that holds no run is deleted rather than kept —
     * otherwise repeated partial writes would grow the directory forever, which is
     * the bound this store exists to promise. It is this store's own directory, so
     * an unreadable `*.json` in it is its own failed write, not someone else's file.
     *
     * It parses only once the directory is over the cap, so an ordinary write pays
     * a stat per file and nothing more.
     */
    private function prune(): void
    {
        $this->removeAbandonedTemporaries();

        $paths = $this->filesNewestFirst();

        if (count($paths) <= $this->keep) {
            return;
        }

        $kept = 0;

        foreach ($paths as $path) {
            if (! $this->recordAt($path) instanceof HistoryRecord) {
                @unlink($path);

                continue;
            }

            $kept++;

            if ($kept > $this->keep) {
                @unlink($path);
            }
        }
    }

    /**
     * Delete temporary files no rename claimed.
     *
     * A process dying between the write and the rename leaves one behind, and it
     * ends in `.tmp` rather than `.json` — so retention would never see it and the
     * directory would grow without bound, which is the same failure the malformed
     * files caused. Only files older than a minute, so a write in flight in
     * another process is left alone.
     */
    private function removeAbandonedTemporaries(): void
    {
        $directory = rtrim($this->directory, '/');
        $entries = @scandir($directory);
        $cutoff = time() - 60;

        foreach ($entries === false ? [] : $entries as $entry) {
            $path = $directory.'/'.$entry;

            if (! str_ends_with($entry, '.tmp') || ! is_file($path)) {
                continue;
            }

            $modified = @filemtime($path);

            if ($modified !== false && $modified < $cutoff) {
                @unlink($path);
            }
        }
    }

    /**
     * Write through a temporary file and rename over the target.
     *
     * `rename()` within one directory is atomic, so a reader never sees half a
     * record and a crash mid-write leaves the previous one intact. Writing in
     * place is what produces the malformed files retention then has to clean up.
     */
    private function writeAtomically(string $path, string $contents): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $contents) === false) {
            return;
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function ensureDirectory(): bool
    {
        return is_dir($this->directory)
            || @mkdir($this->directory, recursive: true)
            || is_dir($this->directory);
    }
}
