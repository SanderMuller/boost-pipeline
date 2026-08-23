<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Fingerprints the tree as the committed head plus everything not committed.
 *
 * The head alone is not enough — the interesting case is exactly the one where a
 * check passed and the agent then edited a file without committing. So the digest
 * also covers the contents of every path git reports as dirty or untracked.
 *
 * Ignored paths are excluded, which is what makes this usable at all: the run's
 * own logs live under `storage/logs/`, tool caches under `.cache/`, and neither
 * moves the fingerprint. A pipeline whose own writes expired its receipts would
 * report a false stale on every clean run.
 */
final readonly class GitTreeFingerprint implements TreeFingerprint
{
    /** Long enough that a collision is not a practical concern, short enough to log. */
    private const int DIGEST_LENGTH = 16;

    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(private string $workingDirectory) {}

    public function capture(): ?string
    {
        $head = $this->git(['rev-parse', 'HEAD']);

        // A repository with no commits yet still has a meaningful dirty set, so
        // only a missing git answers null.
        if ($head === null) {
            return null;
        }

        $status = $this->git(['status', '--porcelain', '--untracked-files=all']);

        if ($status === null) {
            return null;
        }

        $parts = [$head, $status];

        foreach ($this->dirtyPaths($status) as $path) {
            $absolute = rtrim($this->workingDirectory, '/').'/'.$path;

            // A deleted path contributes its absence: the status line above already
            // records it, so there is nothing to hash.
            if (! is_file($absolute)) {
                continue;
            }

            $hash = @hash_file('xxh3', $absolute);

            $parts[] = $path.':'.($hash === false ? 'unreadable' : $hash);
        }

        return substr(hash('sha256', implode("\n", $parts)), 0, self::DIGEST_LENGTH);
    }

    /**
     * @return list<string>
     */
    private function dirtyPaths(string $status): array
    {
        $paths = [];

        $lines = preg_split('/\R/', trim($status));

        foreach ($lines === false ? [] : $lines as $line) {
            if (strlen($line) <= 3) {
                continue;
            }

            // Porcelain v1 is 'XY <path>', and a rename is 'R  old -> new'. The
            // destination is the side that has contents to hash.
            $path = substr($line, 3);
            $arrow = strpos($path, ' -> ');

            if ($arrow !== false) {
                $path = substr($path, $arrow + 4);
            }

            $paths[] = trim($path, '"');
        }

        return $paths;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): ?string
    {
        $process = new Process(['git', ...$arguments], $this->workingDirectory);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}
