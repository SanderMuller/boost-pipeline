<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use RuntimeException;

/**
 * No slot can be held at all, as opposed to every slot being taken.
 *
 * Kept apart from a busy slot, because a step waits for a busy one and would
 * wait forever for one that cannot exist.
 */
final class SlotUnavailable extends RuntimeException
{
    public static function directory(string $directory): self
    {
        return new self("the slot directory {$directory} cannot be created. Set boost-pipeline.slots.directory to a directory every project on this machine can write to.");
    }

    public static function files(string $directory, string $name): self
    {
        return new self("no lock file for the slot [{$name}] can be opened in {$directory}. Check who owns that directory and its files.");
    }
}
