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

    public static function timeoutNotPositive(float $seconds): self
    {
        return new self("A step timeout must be greater than zero, got {$seconds}. Symfony's process runner treats zero as no limit at all, so it would remove the ceiling rather than tighten it — and a step that never returns holds the tool call open until the client gives up.");
    }
}
