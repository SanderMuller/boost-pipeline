<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use function count;
use function explode;

/**
 * The shape of a tree digest, and the only place two of them are compared.
 *
 * A digest answers more than one question, and the questions have different
 * answers. "Is this the same CODE" decides whether a verdict still holds, and
 * must survive a commit, because committing changes no file. "Is this the same
 * COMMIT" decides whether what a gate is about to ship is what was verified, and
 * must not. Both are captured in one read, because reading the tree twice lets
 * the two answers describe two different moments.
 *
 * FORMAT IS A PERSISTED CONTRACT. A digest is written into receipts and live
 * records and read back by a later process, possibly a later version. Without a
 * tag, a digest produced by a different algorithm is indistinguishable from a
 * digest of different code — so a reader would report "the tree moved" for a
 * change of algorithm, and every consumer's gate would fail with nothing wrong.
 * `PipelineFingerprint` learned this already; this is the same rule for the other
 * digest.
 *
 * The tag is NOT a version of this package. Bump it only when `capture()` changes
 * what it measures, which is the only change a reader cannot otherwise detect.
 */
final readonly class TreeDigest
{
    /**
     * Bumped from the untagged original, which keyed on `rev-parse HEAD` plus the
     * raw `git status` output. That digest moved for a commit and for staging,
     * neither of which changes a byte of code, so a run verified moments earlier
     * had to be thrown away and repeated. An untagged value therefore describes a
     * DIFFERENT measurement, not an older spelling of this one, and reads as
     * unknown rather than as a mismatch.
     */
    public const string FORMAT = 'v2';

    private function __construct(
        public string $content,
        public string $head,
        public bool $clean,
    ) {}

    /** @param string|null $head null for a repository with no commits yet */
    public static function compose(string $content, ?string $head, bool $clean): string
    {
        return self::FORMAT.':'.$content.':'.($head ?? 'unborn').':'.($clean ? 'c' : 'd');
    }

    /** Null when the value was not written by this build. */
    public static function parse(string $digest): ?self
    {
        $parts = explode(':', $digest);

        if (count($parts) !== 4 || $parts[0] !== self::FORMAT) {
            return null;
        }

        [, $content, $head, $flag] = $parts;

        if ($content === '' || $head === '' || ($flag !== 'c' && $flag !== 'd')) {
            return null;
        }

        return new self($content, $head, $flag === 'c');
    }

    /**
     * Whether two digests describe the same code, or cannot say.
     *
     * Null is the answer that matters. Two values this build cannot both read say
     * nothing about each other, and a caller must route null wherever it routes a
     * missing digest — never into the "moved" branch, which would report a change
     * that did not happen.
     *
     * Two values that are BOTH unreadable fall back to plain equality. A consumer
     * may bind its own `TreeFingerprint`, and its digests are opaque here but
     * perfectly comparable to each other; treating them as unknown would silently
     * disable staleness for everyone who replaced the shipped implementation.
     */
    public static function sameContent(?string $left, ?string $right): ?bool
    {
        if ($left === null || $right === null) {
            return null;
        }

        $first = self::parse($left);
        $second = self::parse($right);

        if (! $first instanceof self && ! $second instanceof self) {
            return $left === $right;
        }

        if (! $first instanceof self || ! $second instanceof self) {
            return null;
        }

        return $first->content === $second->content;
    }

    /**
     * Whether both digests were taken at the same commit, or cannot say.
     *
     * Separate from the content comparison because only a gate asks it. A run
     * asks whether its verdicts still describe the code; a gate additionally asks
     * whether the code it is about to ship is that code.
     */
    public static function sameCommit(?string $left, ?string $right): ?bool
    {
        if ($left === null || $right === null) {
            return null;
        }

        $first = self::parse($left);
        $second = self::parse($right);

        return $first instanceof self && $second instanceof self
            ? $first->head === $second->head
            : null;
    }

    /** Whether the tree held nothing uncommitted when this was taken, or cannot say. */
    public static function wasClean(?string $digest): ?bool
    {
        if ($digest === null) {
            return null;
        }

        return self::parse($digest)?->clean;
    }
}
