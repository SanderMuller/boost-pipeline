<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Fingerprints the tree by its CONTENT, plus the commit it sat on.
 *
 * Content, not `rev-parse HEAD` plus `git status`. Those move when nothing a
 * check reads has changed — `git add` rewrites the status codes, and a commit
 * advances HEAD and empties the dirty set — so a suite that passed seconds
 * earlier had to be thrown away and run again for a transition that edited no
 * file. Verify, commit, gate is the correct order to work in, and the old digest
 * could not survive it.
 *
 * ONE ENTRY PER PATH, and the working tree wins. The entry is `path mode blob`:
 * git's own blob hash, which is why a clean path can use the index value that git
 * has already computed and a dirty one is hashed here — the two are the same
 * function of the same bytes, so staging a file changes neither. Appending the
 * working value beside the index value instead would make a path contribute
 * twice, and staging would collapse the two into one and move the digest.
 *
 * The mode is part of the entry. `chmod +x` changes what a step invoked as
 * `./script` does while changing no byte of it, and a digest of content alone
 * cannot see that.
 *
 * Not covered: a submodule's own state. Git reports the submodule as one dirty
 * directory, so a change to which commit it points at does not alter the digest.
 * Neither consumer uses submodules, and reading them costs another git call on
 * every step — stated here rather than silently assumed.
 *
 * Ignored paths are excluded, which is what makes this usable at all: the run's
 * own logs live under `storage/logs/`, tool caches under `.cache/`, and neither
 * moves the fingerprint. A pipeline whose own writes expired its receipts would
 * report a false stale on every clean run. `.env` is excluded for the same
 * reason and is a real gap — a check whose outcome depends on it is not a pure
 * function of the tree, and nothing here can tell.
 */
final readonly class GitTreeFingerprint implements TreeFingerprint
{
    /** Long enough that a collision is not a practical concern, short enough to log. */
    private const int DIGEST_LENGTH = 16;

    private const float TIMEOUT_SECONDS = 10.0;

    /** Paths per `hash-object` call, so a very large dirty set cannot overrun the argument limit. */
    private const int HASH_BATCH = 200;

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

        $dirty = $this->dirtyPaths($status);
        $entries = $this->indexEntries($dirty);

        // Null, not an empty set. Without the index there is no record of any
        // clean path, and hashing the dirty ones alone would produce a confident
        // digest of a fraction of the tree — which every reader would then compare
        // as though it described all of it.
        if ($entries === null) {
            return null;
        }

        $working = $this->workingEntries($dirty);

        if ($working === null) {
            return null;
        }

        foreach ($working as $path => $entry) {
            $entries[$path] = $entry;
        }

        $parts = [];

        foreach ($entries as $path => $entry) {
            $parts[] = $path.' '.$entry;
        }

        // The assembled lines are sorted, never the keys. A path of "123" becomes
        // an INT array key, because PHP coerces numeric-string keys, and sorting
        // those mixes two orderings. Sorting the strings keeps one order for one
        // set of paths, which is all the digest needs.
        sort($parts);

        $head = $this->git(['rev-parse', 'HEAD']);

        return TreeDigest::compose(
            substr(hash('sha256', implode("\n", $parts)), 0, self::DIGEST_LENGTH),
            $head === null ? null : trim($head),
            // Clean means git reports NOTHING, untracked included, because the
            // digest counts untracked files. A test that asked only whether the
            // tracked tree matched HEAD would call a tree with a new file clean,
            // and a gate leaning on that would ship without a file the run saw.
            trim($status) === '',
        );
    }

    /**
     * Every tracked path git has already hashed, minus the ones that moved.
     *
     * The index is free: git computed these blob hashes when the file was last
     * staged, so a clean tree costs one command and reads no file. A dirty path
     * is EXCLUDED rather than overwritten afterwards, because its index entry
     * describes the last staged bytes rather than the ones on disk.
     *
     * @param  list<string>  $dirty
     * @return array<string, string>|null null when the index could not be read at all
     */
    private function indexEntries(array $dirty): ?array
    {
        $tracked = $this->git(['ls-files', '-s', '-z']);

        if ($tracked === null) {
            return null;
        }

        $moved = array_flip($dirty);
        $entries = [];

        foreach (explode("\0", $tracked) as $record) {
            // `<mode> <blob> <stage>\t<path>`, and a path may hold anything at
            // all, so it is split off by the single tab rather than by spaces.
            if ($record === '' || ! str_contains($record, "\t")) {
                continue;
            }

            [$meta, $path] = explode("\t", $record, 2);
            $fields = explode(' ', $meta);

            if (count($fields) < 2 || isset($moved[$path])) {
                continue;
            }

            $entries[$path] = $fields[0].' '.$fields[1];
        }

        return $entries;
    }

    /**
     * Every dirty path as git would record it, or null when one cannot be read.
     *
     * GIT DOES THE HASHING, and that is not a style choice. `.gitattributes` can
     * put a filter between the working tree and the blob — `* text=auto` rewrites
     * CRLF to LF on the way in, and Git LFS replaces the content with a pointer —
     * so the bytes on disk are not always the bytes git hashed. A value computed
     * from the file would then never equal the index entry for the same file, and
     * staging it would move the digest permanently, silently, for that file. This
     * repository sets `* text=auto eol=lf`, so it is not hypothetical.
     *
     * One process for the whole set rather than one per file, chunked so a very
     * large dirty set cannot overrun the argument limit.
     *
     * The mode is read from the filesystem rather than the index, because an
     * unstaged `chmod +x` is a change the index has not seen and this entry is
     * the one that wins for a dirty path.
     *
     * @param  list<string>  $dirty
     * @return array<string, string>|null
     */
    private function workingEntries(array $dirty): ?array
    {
        $entries = [];
        $modes = [];

        foreach ($dirty as $path) {
            $absolute = rtrim($this->workingDirectory, '/').'/'.$path;

            if (is_link($absolute)) {
                $target = @readlink($absolute);

                // A symlink is a blob holding its target path, and no filter
                // applies to one, so there is nothing for git to do here.
                if ($target !== false) {
                    $entries[$path] = '120000 '.hash('sha1', 'blob '.strlen($target)."\0".$target);
                }

                continue;
            }

            // A deleted path contributes its absence: it was already dropped from
            // the index entries, and there is nothing to hash.
            if (is_file($absolute)) {
                $modes[$path] = is_executable($absolute) ? '100755' : '100644';
            }
        }

        $paths = array_keys($modes);
        $hashes = $this->blobsOf($paths);

        if ($hashes === null) {
            return null;
        }

        foreach ($modes as $path => $mode) {
            $entries[(string) $path] = $mode.' '.$hashes[$path];
        }

        return $entries;
    }

    /**
     * Git's blob hash for each path, with every attribute and filter applied.
     *
     * Null rather than a partial map. A digest assembled from some real hashes
     * and some guesses would be a confident answer about a tree nobody measured,
     * and every reader compares it as though it described the whole thing.
     *
     * @param  list<string>  $paths
     * @return array<string, string>|null
     */
    private function blobsOf(array $paths): ?array
    {
        $hashes = [];

        foreach (array_chunk($paths, self::HASH_BATCH) as $chunk) {
            $output = $this->git(['hash-object', '--', ...$chunk]);

            if ($output === null) {
                return null;
            }

            $lines = explode("\n", trim($output));

            // One line per path, in order. Anything else and the mapping is a
            // guess, so there is no digest to give.
            if (count($lines) !== count($chunk)) {
                return null;
            }

            foreach ($chunk as $index => $path) {
                $hashes[$path] = $lines[$index];
            }
        }

        return $hashes;
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
