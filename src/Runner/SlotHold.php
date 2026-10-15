<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

/**
 * One slot taken from {@see MachineSlots}, until it is released.
 *
 * Released on destruct as well, so a hold nobody released explicitly still frees
 * its slot when the step that took it is dropped.
 */
final readonly class SlotHold
{
    /** @param resource $handle */
    public function __construct(private mixed $handle) {}

    public function release(): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }

    public function __destruct()
    {
        $this->release();
    }
}
