<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Console;

use Illuminate\Console\Command;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Run\PipelineOverview;

/**
 * @phpstan-import-type DeclarationRow from PipelineOverview
 *
 * What each pipeline would do, and which question it answers.
 *
 * The third of three, and the only one that reads no record. `pipeline:verify`
 * gates on the current receipt, `pipeline:history` reports past runs, and both
 * are silent about a pipeline that has never run — which is the pipeline someone
 * choosing between several most needs described.
 *
 * It exists so that guidance does not have to be written out by hand. A project
 * declaring several pipelines otherwise keeps a table in its agent instructions
 * restating what `.config/pipeline.php` already says, and those two drift: the
 * table is what an agent reads, and the config is what runs.
 */
final class ListCommand extends Command
{
    protected $signature = 'pipeline:list';

    protected $description = 'Report every declared pipeline, the question it answers, and the steps it would walk.';

    public function handle(PipelineLoader $loader, PipelineOverview $overview): int
    {
        // Without this the fallback empty pipeline reads as a configured one that
        // happens to declare nothing, and the reader goes looking for the mistake
        // in a file they never wrote.
        if (! $loader->exists()) {
            $this->components->info('No pipeline is declared. Create ['.PipelineLoader::CONFIG_PATH.'] to add one.');

            return self::SUCCESS;
        }

        $declarations = $overview->declarations();

        foreach ($declarations as $index => $declaration) {
            if ($index > 0) {
                $this->newLine();
            }

            $this->report($declaration);
        }

        // Exit 0 whatever it finds, exactly as `pipeline:history` does. This
        // reports; it never gates. A pipeline declaring nothing is an answer, and
        // a non-zero exit here would invite wiring a describe command into a hook
        // that wants a verdict.
        return self::SUCCESS;
    }

    /** @param DeclarationRow $declaration */
    private function report(array $declaration): void
    {
        $this->components->twoColumnDetail(
            '<options=bold>'.$declaration['pipeline'].'</>',
            // Named rather than blank. A pipeline with no purpose is a config that
            // never said which question it answers, and an empty column reads as a
            // rendering fault instead.
            $declaration['purpose'] ?? '<fg=gray>no purpose declared</>',
        );

        if ($declaration['scopes'] !== []) {
            // Named for the flag rather than for the concept. "scopes" invites
            // reading the list as a partition of the steps, which it is not: an
            // untagged step belongs to none of these and runs under all of them.
            $this->components->twoColumnDetail(
                '<fg=gray>--only accepts</>',
                implode(', ', $declaration['scopes']),
            );
        }

        $this->newLine();

        if ($declaration['positions'] === []) {
            // "Walks", not "declares". A pipeline whose every step sits in an
            // unregistered phase HAS declared steps — reported as dropped below —
            // and calling that "declares none" points at the wrong mistake.
            $this->components->warn('Walks no steps, so a run of it verifies nothing.');
        }

        foreach ($declaration['positions'] as $position) {
            foreach ($position['steps'] as $step) {
                $this->components->twoColumnDetail(
                    $step['id'].($position['parallel'] ? ' <fg=gray>parallel</>' : ''),
                    $this->describeStep($step['phase'], $step['tags'], $declaration['scopes'] !== []),
                );
            }
        }

        foreach ($declaration['dropped'] as $step) {
            // Louder than the rest of this listing on purpose: the step is
            // declared, it reads as part of the pipeline, and it will never run.
            $this->components->error(sprintf(
                'Step [%s] is declared into phase [%s], which is not registered, so it never runs.',
                $step['id'],
                $step['phase'],
            ));
        }
    }

    /**
     * @param  list<string>  $tags
     * @param  bool  $narrowable  whether any step in this pipeline carries a tag
     */
    private function describeStep(string $phase, array $tags, bool $narrowable): string
    {
        return implode(' <fg=gray>·</> ', array_filter([
            $phase,
            // An untagged step is selected in EVERY scope — `Walk::selected()` is
            // true for an empty tag list whatever the selection. Printing nothing
            // left that to inference, and the available inference is the opposite
            // one: no tag reads as no scope.
            //
            // Only where the pipeline has a scope to narrow to. Untagged
            // everywhere means there is no `--only` to qualify, and the note would
            // sit on every row describing a flag that selects nothing.
            match (true) {
                $tags !== [] => implode(', ', $tags),
                $narrowable => 'every scope',
                default => null,
            },
        ], static fn (?string $part): bool => $part !== null));
    }
}
