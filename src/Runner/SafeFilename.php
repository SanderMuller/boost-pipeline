<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

/**
 * Turns a run or step id into a filename component that cannot leave its
 * directory, and that no other id can produce.
 *
 * A step id is whatever the pipeline config passed to `Shell::run(id: ...)`, and a
 * run id is whatever a caller passed to `Run::start()` — only derived ids are
 * slugged, so an explicit one arrives verbatim and would otherwise put separators,
 * or `..`, straight into the path.
 *
 * **Every id carries the digest, not only a rewritten one.** Sanitising is lossy:
 * `lint/all` and `lint all` both reduce to `lint-all`, and the walk checks id
 * uniqueness on the raw values, so two distinct steps would write to one file.
 * Suffixing only the rewritten ones left a narrower version of the same bug — the
 * rewritten `a/b` became `a-b-<digest>`, which a caller could also supply as a
 * literal id, and the two collided. The digest is always the last seven
 * characters, so the sanitised part and the digest are always recoverable from the
 * result, and two ids share a filename only on a hash collision.
 *
 * Shared rather than duplicated: logs and run history both name files after these
 * ids, and two copies of this rule would drift into two different answers for the
 * same id.
 */
final class SafeFilename
{
    public static function for(string $component): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $component);

        return $safe.'-'.substr(hash('xxh3', $component), 0, 6);
    }
}
