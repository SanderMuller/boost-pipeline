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
 * Not covered: a submodule's own state. Git reports the submodule as one dirty
 * directory, so a change to which commit it points at does not alter the digest.
 * Neither consumer uses submodules, and reading them costs another git call on
 * every step — stated here rather than silently assumed.
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
        // Status is the test for "is this a repository at all", not rev-parse:
        // before the first commit `rev-parse HEAD` fails while the dirty set is
        // still perfectly meaningful. Treating that as unfingerprintable turned
        // expiry off for a whole new repository.
        $status = $this->git(['status', '--porcelain=v1', '-z', '--untracked-files=all']);

        if ($status === null) {
            return null;
        }

        $parts = [$this->git(['rev-parse', 'HEAD']) ?? 'unborn', $status];

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
     * Paths from `--porcelain=v1 -z`.
     *
     * The NUL form is not a nicety: without `-z`, git quotes any path holding a
     * space, quote, backslash or non-ASCII byte and C-escapes the contents. A
     * naive parse then names a file that does not exist, its contents never reach
     * the digest, and every later edit to it is invisible — while its status line
     * stays identical. The one case that must not silently stop being watched.
     *
     * @return list<string>
     */
    private function dirtyPaths(string $status): array
    {
        $records = explode("\0", $status);
        $paths = [];
        $counter = count($records);

        for ($i = 0; $i < $counter; $i++) {
            $record = $records[$i];

            if (strlen($record) <= 3) {
                continue;
            }

            $code = substr($record, 0, 2);
            $paths[] = substr($record, 3);

            // A rename or copy is followed by its origin as a separate record.
            if (str_contains($code, 'R') || str_contains($code, 'C')) {
                $i++;
            }
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
