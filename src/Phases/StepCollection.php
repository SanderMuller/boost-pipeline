<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases;

use Closure;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;

/**
 * The steps inside one phase. Chainable so a phase reads as a list.
 */
final class StepCollection
{
    /** @var list<Step|StepBatch> */
    private array $entries = [];

    public function append(Step $step): self
    {
        $this->entries[] = $step;

        return $this;
    }

    public function prepend(Step $step): self
    {
        array_unshift($this->entries, $step);

        return $this;
    }

    /**
     * Steps that run at the same time, in one position of the walk.
     *
     * Reads like a route group, and the group is the unit that resolves: one
     * `next_step` call runs all of them and returns every verdict.
     *
     *     $steps->in(StaticAnalysis::class)->parallel(function (StepCollection $steps): void {
     *         $steps->append(Shell::run('composer phpstan'));
     *         $steps->append(Shell::run('node_modules/.bin/tsc --noEmit'));
     *     });
     *
     * @param  Closure(self): void  $group
     */
    public function parallel(Closure $group): self
    {
        $collected = new self;
        $group($collected);

        foreach ($collected->entries as $entry) {
            if ($entry instanceof StepBatch) {
                throw InvalidPipelineConfigException::batchCannotNest();
            }
        }

        $this->entries[] = new StepBatch($collected->all());

        return $this;
    }

    /**
     * Every step in declaration order, with batches flattened.
     *
     * For counting and naming steps. Use {@see self::entries()} where the
     * grouping matters, which is when building the walk.
     *
     * @return list<Step>
     */
    public function all(): array
    {
        $steps = [];

        foreach ($this->entries as $entry) {
            if ($entry instanceof StepBatch) {
                array_push($steps, ...$entry->steps);

                continue;
            }

            $steps[] = $entry;
        }

        return $steps;
    }

    /** @return list<Step|StepBatch> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
