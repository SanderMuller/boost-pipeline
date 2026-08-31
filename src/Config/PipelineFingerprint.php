<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Config;

use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Phases\StepBatch;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;

/**
 * Digests a pipeline's whole declaration, so a run can record which one it used.
 *
 * The MCP server resolves the config once when its process starts. A step
 * declared or redefined after that is invisible to every run until the client
 * reconnects, and the server can then run a different definition of the same step
 * id and record it as a pass. Nothing else catches that: the receipt's verdicts
 * are keyed by step id, and the tree fingerprint matches because the run ran
 * against the tree that already held the new config.
 *
 * The WHOLE declaration, never a walk. A walk is filtered by the run's scope, so
 * fingerprinting one would give a scoped run a digest that no unscoped comparison
 * could match. Every scope of one pipeline therefore shares a digest.
 *
 * It describes a declaration, not an identity — the pipeline's name is not an
 * input. Two pipelines declaring the same steps share a digest, which is correct:
 * the question is what would run, not what it is called.
 *
 * DETERMINISM IS THE CONSUMER'S SIDE OF THE BARGAIN. The config file is arbitrary
 * PHP, so any value here can be computed at load time from the environment, the
 * clock, or a file outside the repository. Two honest processes then produce
 * different digests from unchanged files, and a gate reading them fails with
 * nothing wrong. Such a project turns the comparison off rather than living with
 * a gate that cannot pass; `pipeline:verify` names this cause in its refusal
 * rather than blaming the server.
 */
final readonly class PipelineFingerprint
{
    /** Long enough that a collision is not a practical concern, short enough to log. */
    private const int DIGEST_LENGTH = 16;

    public static function for(Pipeline $pipeline): string
    {
        return substr(hash('xxh3', serialize(self::canonical($pipeline))), 0, self::DIGEST_LENGTH);
    }

    /**
     * The declaration as nested arrays, in a fixed order.
     *
     * Order is part of the declaration rather than an artefact of how it is read:
     * which checks see a mutating step's output depends on it, so a reordered
     * pipeline is a different pipeline.
     *
     * @return array<string, mixed>
     */
    private static function canonical(Pipeline $pipeline): array
    {
        $steps = $pipeline->steps();
        $declared = [];

        foreach ($steps->declaredPhases() as $phaseClass) {
            $entries = [];

            foreach ($steps->entriesForPhase($phaseClass) as $entry) {
                // A batch resolves as one position, so its grouping is declaration
                // too: the same steps ungrouped would run one after another.
                $entries[] = $entry instanceof StepBatch
                    ? ['batch' => array_map(self::step(...), $entry->steps)]
                    : ['step' => self::step($entry)];
            }

            $declared[] = ['phase' => $phaseClass, 'entries' => $entries];
        }

        return [
            // Registered phases decide which declared steps reach a walk at all,
            // and in what order the phases run.
            'phases' => $pipeline->phases()->all(),
            'declared' => $declared,
            'timeout' => self::number($pipeline->timeoutSeconds()),
        ];
    }

    /**
     * A float as a fixed-precision string.
     *
     * `serialize()` renders a float according to `serialize_precision`, which is
     * an ini setting: the same 0.5 becomes `d:0.5;` under one value and
     * `d:0.5000000000000000...;` under another. The server process and the
     * process that compares digests need not share a php.ini, so hashing the raw
     * float would let a static config produce two digests and fail a gate with
     * nothing wrong. Whole numbers happen to survive; fractional timeouts do not.
     */
    private static function number(?float $value): ?string
    {
        return $value === null ? null : sprintf('%.6F', $value);
    }

    /**
     * A list of strings in a fixed order.
     *
     * For values whose declared order carries no meaning, so that reordering them
     * is not reported as a change to what the pipeline would do.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * One step, down to what it would actually do.
     *
     * `Shell::description()` is deliberately absent: it returns the command only
     * when no description was set, so reading it would let a custom description
     * hide a changed command — the exact case this exists to catch. The command is
     * read directly instead.
     *
     * `Skill` is the opposite. Its description IS the instruction handed to the
     * agent, which is declaration rather than label, and there is no accessor for
     * the instruction alone. Its `proof` matters just as much: that is a shell
     * command deciding whether the step passes, so a stale server running an old
     * one is the motivating hole on the step type where the server is the only
     * thing checking anything.
     *
     * @return array<string, mixed>
     */
    private static function step(Step $step): array
    {
        $canonical = [
            'id' => $step->id(),
            'kind' => $step->kind()->value,
            // Sorted, because tag order carries no meaning: selection tests
            // membership. Hashing the declared order would report a mismatch for
            // a reordering that changes nothing a run would do.
            'tags' => self::sorted($step->tags()),
            'mutates' => $step->mutates(),
        ];

        if ($step instanceof Shell) {
            $canonical['command'] = $step->command();
            $canonical['scope'] = $step->scopeCommand();
            $canonical['timeout'] = self::number($step->timeoutSeconds());
            // KEYS only. A value is as likely to be ambient as declared — a
            // consumer writing `->withEnv(['TOKEN' => getenv('TOKEN')])` bakes a
            // process-specific value into the declaration, and two shells would
            // then disagree about an identical config. A key added or removed is a
            // real change and stays.
            $canonical['env'] = self::sorted(array_keys($step->env()));
        }

        if ($step instanceof Skill) {
            $canonical['invocation'] = $step->invocation();
            $canonical['proof'] = $step->proof();
            $canonical['instruction'] = $step->description();
        }

        // A custom Step implementation contributes the contract surface above and
        // nothing more: command and invocation live on the concrete classes, not
        // on the interface. Widening the interface would break every implementor,
        // so the limit is documented rather than closed.
        return $canonical;
    }
}
