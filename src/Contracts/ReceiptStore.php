<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

use SanderMuller\BoostPipeline\Run\Receipt;

/**
 * Where a run's outcome is kept so something outside the session can read it.
 *
 * Read the warning in {@see Receipt} before treating one as proof: a file in the
 * working copy is writable by whatever can run a shell step, so a receipt records
 * what a run found — it does not establish that a run happened.
 */
interface ReceiptStore
{
    public function write(Receipt $receipt): void;

    public function read(): ?Receipt;
}
