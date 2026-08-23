<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Exceptions;

use RuntimeException;

final class InvalidPipelineConfigException extends RuntimeException
{
    public static function didNotReturnPipeline(string $path, string $given): self
    {
        return new self("[{$path}] must return a Pipeline instance, got {$given}.");
    }

    public static function duplicateStepId(string $id): self
    {
        return new self("Duplicate step id [{$id}]. Step ids address log files and receipts, so they must be unique across the whole pipeline.");
    }

    public static function unknownAnchor(string $anchor): self
    {
        return new self("Cannot position a phase after [{$anchor}]: no such phase is registered.");
    }

    public static function selfAnchor(string $phase): self
    {
        return new self("Cannot position phase [{$phase}] after itself.");
    }

    public static function batchStepMustBeShell(string $id): self
    {
        return new self("Step [{$id}] cannot run in a parallel group: only shell steps can. A skill step handed over alongside others is the wall of context one-step-at-a-time exists to break up, so declare it on its own.");
    }

    public static function batchStepCannotMutate(string $id): self
    {
        return new self("Step [{$id}] declares ->mutating(), so it cannot run in a parallel group. Its siblings would run against a tree it is rewriting, with no ordering to attribute the change to, and every sibling verdict would describe code that no longer exists. Run it on its own, before the checks that must see its result.");
    }

    public static function batchCannotNest(): self
    {
        return new self('A parallel group cannot contain another parallel group. Nesting adds no concurrency — the steps already all run at once — and it hides which steps share a position.');
    }

    public static function timeoutNotPositive(float $seconds): self
    {
        return new self("A step timeout must be greater than zero, got {$seconds}. Symfony's process runner treats zero as no limit at all, so it would remove the ceiling rather than tighten it — and a step that never returns holds the tool call open until the client gives up.");
    }
}
