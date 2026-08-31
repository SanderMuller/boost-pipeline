<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Contracts\ReceiptStore;

/**
 * Keeps the receipt as one JSON file.
 *
 * Under `storage/logs/` on purpose: that is already ignored by a Laravel app's
 * own nested .gitignore, so using the pipeline never leaves untracked files
 * behind.
 *
 * One file, and it answers one question: does the current tree have a pass. Past
 * runs live beside it under `history/`, written by {@see JsonRunHistoryStore} —
 * a separate store rather than a widened one, so this file keeps the single
 * meaning `pipeline:verify` reads it for.
 */
final readonly class JsonReceiptStore implements ReceiptStore
{
    /**
     * Where a receipt lived before pipelines had names, relative to storage.
     *
     * Never read. It is recorded only so a project that upgrades can be told its
     * receipt moved, rather than being told nothing has ever been verified — the
     * same answer for two very different situations, and the one that reads as a
     * broken gate.
     */
    public const string LEGACY_PATH = 'logs/pipeline/receipt.json';

    public function __construct(private string $path) {}

    public function write(Receipt $receipt): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, recursive: true) && ! is_dir($directory)) {
            // Losing the receipt must not turn a real verdict into an error.
            return;
        }

        $json = json_encode($receipt->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json !== false) {
            @file_put_contents($this->path, $json.PHP_EOL);
        }
    }

    public function read(): ?Receipt
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        // Unreadable is treated as absent rather than as an error: the answer to
        // "is there a pass for this tree" is no either way, and a consumer acting
        // on that is correct.
        return is_array($data) ? Receipt::fromArray($data) : null;
    }
}
