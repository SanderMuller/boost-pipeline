<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Walk;

use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Phases\Phases;
use SanderMuller\BoostPipeline\Phases\Steps;

/**
 * The pipeline flattened once into the ordered list the cursor walks: each
 * phase's steps in order, with transition steps spliced in at the joins.
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

        [$walk, $placed] = self::buildWalk($registered, $steps);

        $notices = [
            ...self::noticesForUnplacedTransitions($steps, $registered, $placed),
            ...self::noticesForUnregisteredPhases($steps, $registered),
        ];

        self::assertUniqueStepIds($walk);

        return new self($walk, $notices);
    }

    /**
     * @param  list<class-string<Phase>>  $registered
     * @return array{0: list<WalkStep>, 1: array<int, true>}
     */
    private static function buildWalk(array $registered, Steps $steps): array
    {
        $walk = [];
        $placed = [];
        $transitions = $steps->transitions();

        foreach ($registered as $index => $phaseClass) {
            $phase = self::instantiate($phaseClass);

            foreach ($steps->forPhase($phaseClass) as $step) {
                $walk[] = new WalkStep($step, $phase->id(), $phase->name());
            }

            $nextClass = $registered[$index + 1] ?? null;

            if ($nextClass === null) {
                continue;
            }

            $next = self::instantiate($nextClass);

            foreach ($transitions as $key => $transition) {
                if ($transition['after'] !== $phaseClass || $transition['before'] !== $nextClass) {
                    continue;
                }

                $walk[] = new WalkStep(
                    $transition['step'],
                    $phase->id().'->'.$next->id(),
                    $phase->name().' → '.$next->name(),
                );

                $placed[$key] = true;
            }
        }

        return [$walk, $placed];
    }

    /**
     * Never drop a transition silently: registered-but-not-adjacent anchors
     * vanish just as quietly as missing ones, and that is a declared gate
     * disappearing without a trace.
     *
     * @param  list<class-string<Phase>>  $registered
     * @param  array<int, true>  $placed
     * @return list<string>
     */
    private static function noticesForUnplacedTransitions(Steps $steps, array $registered, array $placed): array
    {
        $notices = [];

        foreach ($steps->transitions() as $key => $transition) {
            if (isset($placed[$key])) {
                continue;
            }

            $notices[] = self::explainDroppedTransition($transition, $registered);
        }

        return $notices;
    }

    /**
     * Same rule, other shape: steps declared into a phase that is not registered
     * never reach the cursor, so a removed phase would take its gates down in
     * silence.
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

    /**
     * @param  array{after: class-string<Phase>, before: class-string<Phase>, step: Step}  $transition
     * @param  list<class-string<Phase>>  $registered
     */
    private static function explainDroppedTransition(array $transition, array $registered): string
    {
        $id = $transition['step']->id();
        $after = class_basename($transition['after']);
        $before = class_basename($transition['before']);

        $afterIndex = array_search($transition['after'], $registered, true);
        $beforeIndex = array_search($transition['before'], $registered, true);

        $reason = match (true) {
            $afterIndex === false && $beforeIndex === false => "neither [{$after}] nor [{$before}] is registered",
            $afterIndex === false => "[{$after}] is not registered",
            $beforeIndex === false => "[{$before}] is not registered",
            $beforeIndex < $afterIndex => "[{$before}] comes before [{$after}] in the resolved order, so there is no such join",
            default => sprintf(
                'they are registered but not adjacent — [%s] sits between them',
                implode('], [', array_map(
                    static fn (string $phase): string => class_basename($phase),
                    array_slice($registered, $afterIndex + 1, $beforeIndex - $afterIndex - 1),
                )),
            ),
        };

        return "Transition step [{$id}] dropped: anchored between [{$after}] and [{$before}], but {$reason}.";
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
