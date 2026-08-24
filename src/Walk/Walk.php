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
     * @param  int  $excluded  How many declared steps the selection left out.
     */
    private function __construct(
        public array $steps,
        public array $notices,
        public int $excluded = 0,
    ) {}

    /**
     * @param  string|null  $selection  Walk only steps carrying this tag, plus every untagged
     *                                  step. Null walks everything.
     */
    public static function resolve(Phases $phases, Steps $steps, ?string $selection = null): self
    {
        $registered = $phases->all();

        [$walk, $matchedSelection, $excluded] = self::buildWalk($registered, $steps, $selection);

        self::assertUniqueStepIds($walk);

        $notices = self::noticesForUnregisteredPhases($steps, $registered);

        // A selection nothing carries is almost always a mistyped tag. Left
        // unreported, the untagged steps would pass and the run would call itself
        // verified while the scope the caller asked about was never checked.
        if ($selection !== null && ! $matchedSelection) {
            $notices[] = sprintf(
                'No step carries the tag [%s], so this run holds only the steps that carry no tag at all. Check the spelling: matching is case-sensitive.',
                $selection,
            );
        }

        return new self($walk, $notices, $excluded);
    }

    /** @param list<string> $tags */
    private static function selected(array $tags, ?string $selection): bool
    {
        return $selection === null || $tags === [] || in_array($selection, $tags, true);
    }

    /**
     * @param  list<class-string<Phase>>  $registered
     * @return array{0: list<WalkStep>, 1: bool, 2: int} the walk, whether any step carried the
     *                                                   selection, and how many it left out
     */
    private static function buildWalk(array $registered, Steps $steps, ?string $selection): array
    {
        $walk = [];
        $batchId = 0;
        $matched = false;
        $excluded = 0;

        foreach ($registered as $phaseClass) {
            $phase = self::instantiate($phaseClass);

            foreach ($steps->entriesForPhase($phaseClass) as $entry) {
                if (! $entry instanceof StepBatch) {
                    $matched = $matched || self::carries($entry, $selection);

                    if (self::selected($entry->tags(), $selection)) {
                        $walk[] = new WalkStep($entry, $phase->id(), $phase->name());
                    } else {
                        $excluded++;
                    }

                    continue;
                }

                $survivors = [];

                foreach ($entry->steps as $step) {
                    $matched = $matched || self::carries($step, $selection);

                    if (self::selected($step->tags(), $selection)) {
                        $survivors[] = $step;
                    } else {
                        $excluded++;
                    }
                }

                if ($survivors === []) {
                    continue;
                }

                // One survivor is not a group. Leaving the id set would have
                // `isGrouped()` report a step that ran alone as sharing a
                // measurement with siblings that were never in the walk.
                if (count($survivors) === 1) {
                    $walk[] = new WalkStep($survivors[0], $phase->id(), $phase->name());

                    continue;
                }

                // A new id per batch even when a phase holds several, so two
                // adjacent batches never merge into one position.
                $batchId++;

                foreach ($survivors as $step) {
                    $walk[] = new WalkStep($step, $phase->id(), $phase->name(), $batchId);
                }
            }
        }

        return [$walk, $matched, $excluded];
    }

    private static function carries(Step $step, ?string $selection): bool
    {
        return $selection !== null && in_array($selection, $step->tags(), true);
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
