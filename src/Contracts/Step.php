<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

use SanderMuller\BoostPipeline\Enums\StepKind;

interface Step
{
    public function id(): string;

    public function description(): string;

    public function kind(): StepKind;

    /**
     * Whether this step is expected to change the working tree.
     *
     * Declared, never inferred from timing. A read-only step whose run coincides
     * with the tree changing is a finding — either the step lied or something
     * edited files mid-run — and both mean the verdict is not proven for the code
     * that now exists. Timing cannot tell those apart from a formatter doing its
     * job, so the config says which it is.
     */
    public function mutates(): bool;

    /**
     * The scopes this step belongs to, empty when it belongs to all of them.
     *
     * A run may select one scope and walk only the steps that carry it, plus
     * every untagged step. Untagged means "always", never "never", so adding the
     * first tag to one step cannot silently drop the steps that carry none.
     *
     * @return list<string>
     */
    public function tags(): array;
}
