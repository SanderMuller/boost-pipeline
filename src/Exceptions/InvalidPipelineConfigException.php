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

    public static function declaredNoPipelines(string $path): self
    {
        return new self("[{$path}] returned an empty array, so it declares no pipelines at all. Return a Pipeline, or a map of names to Pipelines.");
    }

    public static function pipelineNameNotAString(string $path, mixed $name): self
    {
        return new self("[{$path}] must return a map of names to Pipelines, but a key is ".get_debug_type($name).' ['.(is_scalar($name) ? (string) $name : '?').']. A plain list of pipelines gets integer keys — give each one a name.');
    }

    public static function mapValueNotAPipeline(string $path, string $name, string $given): self
    {
        return new self("[{$path}] declares pipeline [{$name}] as {$given}, not a Pipeline instance.");
    }

    public static function invalidPipelineName(string $name): self
    {
        return new self("Pipeline name [{$name}] is not usable. A name addresses a receipt file, so it must match /^[a-z0-9][a-z0-9-]*$/: lowercase letters, digits and dashes, starting with a letter or digit.");
    }

    /** @param list<string> $names */
    public static function noSolePipeline(array $names): self
    {
        return new self('This project declares '.count($names).' pipelines ['.implode('], [', $names).'], so there is no single one to resolve. Ask for one by name.');
    }

    /** @param list<string> $names */
    public static function unknownPipeline(string $asked, array $names): self
    {
        return new self("No pipeline named [{$asked}] is configured. This project declares [".implode('], [', $names).'].');
    }

    /** @param list<string> $names */
    public static function pipelineNotSelected(array $names): self
    {
        return new self('This project names its pipelines, so one has to be named here too. It declares ['.implode('], [', $names).']. Pass the pipeline you mean.');
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

    public static function emptyTag(string $id): self
    {
        return new self("Step [{$id}] declares an empty tag. A tag names a scope a run can select, so it has to be something a caller could ask for.");
    }

    public static function batchStepMustBeShell(string $id): self
    {
        return new self("Step [{$id}] cannot run in a parallel group: only shell steps can. A skill step handed over alongside others is the wall of context one-step-at-a-time exists to break up, so declare it on its own. A skill that fans out internally is still one step, so parallel work inside a skill costs nothing here.");
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
