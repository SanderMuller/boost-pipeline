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

    public static function unknownPhase(string $phase): self
    {
        return new self("Phase [{$phase}] is not registered in this pipeline.");
    }

    public static function unknownAnchor(string $anchor): self
    {
        return new self("Cannot position a phase after [{$anchor}]: no such phase is registered.");
    }

    public static function selfAnchor(string $phase): self
    {
        return new self("Cannot position phase [{$phase}] after itself.");
    }
}
