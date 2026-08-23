<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Walk;

use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Phases\Phases;
use SanderMuller\BoostPipeline\Phases\StepBatch;
use SanderMuller\BoostPipeline\Phases\Steps;

/**
 * The pipeline flattened once into the ordered list the cursor walks: each
 * phase's steps, in phase order.
 */
final readonly class Walk
{
    /**
     * @param  list<WalkStep>  $steps
     * @param  list<string>  $notices
     */
    private function __construct(
        public array $steps,
        public array $notices,
    ) {}

    public static function resolve(Phases $phases, Steps $steps): self
    {
        $registered = $phases->all();

        $walk = self::buildWalk($registered, $steps);

        self::assertUniqueStepIds($walk);

        return new self($walk, self::noticesForUnregisteredPhases($steps, $registered));
    }

    /**
     * @param  list<class-string<Phase>>  $registered
     * @return list<WalkStep>
     */
    private static function buildWalk(array $registered, Steps $steps): array
    {
        $walk = [];
        $batchId = 0;

        foreach ($registered as $phaseClass) {
            $phase = self::instantiate($phaseClass);

            foreach ($steps->entriesForPhase($phaseClass) as $entry) {
                if (! $entry instanceof StepBatch) {
                    $walk[] = new WalkStep($entry, $phase->id(), $phase->name());

                    continue;
                }

                // A new id per batch even when a phase holds several, so two
                // adjacent batches never merge into one position.
                $batchId++;

                foreach ($entry->steps as $step) {
                    $walk[] = new WalkStep($step, $phase->id(), $phase->name(), $batchId);
                }
            }
        }

        return $walk;
    }

    /**
     * Never drop a declared step silently: a step declared into a phase that is
     * not registered never reaches the cursor, so a gate would go missing without
     * a word. The run reports the drop and refuses to call itself verified.
     *
     * @param  list<class-string<Phase>>  $registered
     * @return list<string>
     */
    private static function noticesForUnregisteredPhases(Steps $steps, array $registered): array
    {
        $notices = [];

        foreach ($steps->declaredPhases() as $phaseClass) {
            if (in_array($phaseClass, $registered, true)) {
                continue;
            }

            $ids = implode(', ', array_map(
                static fn (Step $step): string => '['.$step->id().']',
                $steps->forPhase($phaseClass),
            ));

            $notices[] = sprintf(
                'Step(s) %s dropped: declared into phase [%s], which is not registered.',
                $ids,
                class_basename($phaseClass),
            );
        }

        return $notices;
    }

    public function count(): int
    {
        return count($this->steps);
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    public function at(int $cursor): ?WalkStep
    {
        return $this->steps[$cursor] ?? null;
    }

    /** Whether the step shares its position with others, so it ran alongside them. */
    public function isGrouped(string $stepId): bool
    {
        foreach ($this->steps as $walkStep) {
            if ($walkStep->step->id() === $stepId) {
                return $walkStep->batchId !== null;
            }
        }

        return false;
    }

    /**
     * Every step sharing the position at $cursor, in declaration order.
     *
     * One element for an ordinary step. For a batch, all of its steps — and the
     * cursor only ever sits on the first of them, because a position resolves as
     * a unit.
     *
     * @return list<WalkStep>
     */
    public function positionAt(int $cursor): array
    {
        $first = $this->at($cursor);

        if (! $first instanceof WalkStep) {
            return [];
        }

        if ($first->batchId === null) {
            return [$first];
        }

        $position = [];
        $counter = count($this->steps);

        for ($index = $cursor; $index < $counter; $index++) {
            if ($this->steps[$index]->batchId !== $first->batchId) {
                break;
            }

            $position[] = $this->steps[$index];
        }

        return $position;
    }

    /** @param class-string<Phase> $phaseClass */
    private static function instantiate(string $phaseClass): Phase
    {
        return new $phaseClass;
    }

    /** @param list<WalkStep> $walk */
    private static function assertUniqueStepIds(array $walk): void
    {
        $seen = [];

        foreach ($walk as $walkStep) {
            $id = $walkStep->step->id();

            if (isset($seen[$id])) {
                throw InvalidPipelineConfigException::duplicateStepId($id);
            }

            $seen[$id] = true;
        }
    }
}
