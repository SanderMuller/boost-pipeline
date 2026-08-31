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
     * @param  string|null  $configDigest  Which declaration this walk came from, or null when the
     *                                     caller did not supply one.
     * @param  list<array{id: string, phase: string}>  $dropped  steps dropped from THIS selection
     * @param  bool  $selectionCarriedNothing  a selection was given and no step carries it
     */
    private function __construct(
        public array $steps,
        public array $notices,
        public int $excluded = 0,
        public ?string $configDigest = null,
        /**
         * The steps this walk dropped that belong to its selection.
         *
         * `notices` describes the same event in prose, for an agent. This is the
         * same event as data, for a gate: it names the ids and it is already
         * filtered to the scope the walk was resolved for, which a sentence cannot
         * be. Empty for a walk that dropped nothing, and for one whose drops all
         * sit outside its scope.
         *
         * @var list<array{id: string, phase: string}>
         */
        public array $dropped = [],
        /**
         * Whether a selection was given that no step carries.
         *
         * Kept as data for the same reason `dropped` is: a reader that needs to
         * know whether this run can verify anything cannot get there from prose.
         *
         * It blocks verification on its own, separately from `dropped`, because it
         * drops NOTHING — the walk is every untagged step, which will pass. A
         * mistyped tag would otherwise leave a run reporting itself verified while
         * the scope the caller asked about was never checked.
         */
        public bool $selectionCarriedNothing = false,
    ) {}

    /**
     * @param  string|null  $selection  Walk only steps carrying this tag, plus every untagged
     *                                  step. Null walks everything.
     * @param  string|null  $configDigest  A digest of the WHOLE declaration this walk was resolved
     *                                     from, which only the `Pipeline` can compute — it owns the
     *                                     pipeline-level settings a walk never sees. Carried, never
     *                                     computed here: deriving it from `$walk` would fingerprint
     *                                     the selected scope instead of the declaration, and a scoped
     *                                     run would then record a digest no unscoped comparison could
     *                                     match. Null means the caller did not supply one, which a
     *                                     reader must treat as unknown rather than as clean.
     */
    public static function resolve(Phases $phases, Steps $steps, ?string $selection = null, ?string $configDigest = null): self
    {
        $registered = $phases->all();

        [$walk, $matchedSelection, $excluded] = self::buildWalk($registered, $steps, $selection);

        self::assertUniqueStepIds($walk);

        [$notices, $dropped] = self::dropsForUnregisteredPhases($steps, $registered, $selection);

        // A selection nothing carries is almost always a mistyped tag. Left
        // unreported, the untagged steps would pass and the run would call itself
        // verified while the scope the caller asked about was never checked.
        $selectionCarriedNothing = $selection !== null && ! $matchedSelection;

        if ($selectionCarriedNothing) {
            $notices[] = sprintf(
                'No step carries the tag [%s], so this run holds only the steps that carry no tag at all. Check the spelling: matching is case-sensitive. A value containing a space, or starting with a dash, is an unquoted shell variable far more often than it is a tag.',
                $selection,
            );
        }

        return new self($walk, $notices, $excluded, $configDigest, $dropped, $selectionCarriedNothing);
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
     * Returns the same prose it always did, plus the same event as data.
     *
     * Two shapes for two readers, one traversal so they cannot drift. An agent
     * needs a sentence; a gate needs step ids it can name and a scope it can
     * filter by — and a sentence cannot say which scope a dropped step belonged
     * to, which is why a scoped call could not refuse one.
     *
     * @param  list<class-string<Phase>>  $registered
     * @return array{0: list<string>, 1: list<array{id: string, phase: string}>}
     */
    private static function dropsForUnregisteredPhases(Steps $steps, array $registered, ?string $selection): array
    {
        $notices = [];
        $dropped = [];

        foreach ($steps->declaredPhases() as $phaseClass) {
            if (in_array($phaseClass, $registered, true)) {
                continue;
            }

            // UNFILTERED, unlike `$dropped` below, and deliberately so. A reader of
            // a frontend-scoped run therefore sees a backend step named here that
            // their scope never selected, which reads as an inconsistency and is
            // not one to fix by adding the filter.
            //
            // Two things break if this is filtered. Every step in an unregistered
            // phase can be out of scope, which would leave a notice naming no steps
            // at all — `Steps::declaredPhases()` already records why that is worse
            // than saying nothing. And suppressing the notice instead would flip
            // `Run::verifiedGiven()`, which returns false while any notice exists,
            // so a scoped run whose drops all sit elsewhere would start calling
            // itself verified. That is a loosening of a false-green guard and needs
            // deciding on its own, not arriving as a side effect of tidying prose.
            //
            // The asymmetry is honest as it stands: the notice reports what the
            // CONFIG got wrong, which is scope-independent, and `$dropped` reports
            // what THIS question has to refuse over.
            $ids = implode(', ', array_map(
                static fn (Step $step): string => '['.$step->id().']',
                $steps->forPhase($phaseClass),
            ));

            $notices[] = sprintf(
                'Step(s) %s dropped: declared into phase [%s], which is not registered.',
                $ids,
                class_basename($phaseClass),
            );

            foreach ($steps->forPhase($phaseClass) as $step) {
                // Filtered by the SAME predicate the walk itself uses. A step is
                // dropped whatever the selection, but whether it belongs to this
                // scope depends on its tags — and a second copy of that rule would
                // eventually disagree with the walk, which is a false failure in a
                // gate.
                if (! self::selected($step->tags(), $selection)) {
                    continue;
                }

                $dropped[] = ['id' => $step->id(), 'phase' => class_basename($phaseClass)];
            }
        }

        return [$notices, $dropped];
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
