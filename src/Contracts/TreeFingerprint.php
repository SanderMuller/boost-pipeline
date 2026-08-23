<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

/**
 * A digest of the working tree, used to decide whether a receipt still holds.
 *
 * A verdict is only ever about the code that was on disk when the step ran. Once
 * that code changes, "this run passed" degrades into "this passed at some earlier
 * moment", which is not something a gate can act on. Comparing digests is what
 * lets the server tell those apart.
 */
interface TreeFingerprint
{
    /**
     * A digest that changes when the working tree does, or null when the tree
     * cannot be inspected.
     *
     * null disables every comparison that depends on it rather than guessing:
     * a fabricated digest would either expire receipts that are still good or
     * hold on to ones that are not.
     */
    public function capture(): ?string;
}
