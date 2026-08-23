<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Contracts\ReceiptStore;

/**
 * Keeps the receipt as one JSON file.
 *
 * Under `storage/logs/` on purpose: that is already ignored by a Laravel app's
 * own nested .gitignore, so using the pipeline never leaves untracked files
 * behind. One file rather than a history — the question a reader asks is "does
 * the current tree have a pass", and a directory of past answers only makes that
 * harder.
 */
final readonly class JsonReceiptStore implements ReceiptStore
{
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
