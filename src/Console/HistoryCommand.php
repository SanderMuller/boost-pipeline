<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Console;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Command;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Run\JsonRunHistoryStore;
use SanderMuller\BoostPipeline\Run\PipelineOverview;
use Throwable;

/**
 * @phpstan-import-type LiveRow from PipelineOverview
 * @phpstan-import-type HistoryRow from PipelineOverview
 *
 * What the recent runs did, in a terminal.
 *
 * This reports; `pipeline:verify` gates. They read different records to answer
 * different questions — verify reads the current receipt and answers whether the
 * tree on disk is verified, this reads history and the in-flight record and
 * answers what happened. Keeping the exit codes apart is what stops this one
 * being wired into a hook it can never satisfy.
 */
final class HistoryCommand extends Command
{
    protected $signature = 'pipeline:history
        {--pipeline= : Which pipeline to report on. Required when the project declares more than one.}
        {--run= : Show one recorded run in detail rather than the list.}
        {--limit= : How many runs to list. Defaults to the number history keeps.}';

    protected $description = 'Report the recent pipeline runs, and the run in flight.';

    public function handle(Pipelines $pipelines, PipelineOverview $overview): int
    {
        $name = $this->pipelineName($pipelines);

        if ($name === null) {
            return self::FAILURE;
        }

        $limit = $this->limit();

        if ($limit === null) {
            return self::FAILURE;
        }

        $run = $this->option('run');

        return is_string($run) && trim($run) !== ''
            ? $this->showRun($overview, $name, $run)
            : $this->showList($overview, $name, $limit);
    }

    /**
     * Every recorded run, newest first, with the in-flight one above them.
     *
     * Exit 0 whatever it finds. An empty history, a stale run and a failed run
     * are all reports — the command answered. Only a question it cannot answer
     * exits non-zero.
     */
    private function showList(PipelineOverview $overview, string $name, int $limit): int
    {
        $pipeline = $overview->forPipeline($name, $limit);
        $live = $pipeline['live'];

        if ($live !== null) {
            $this->components->twoColumnDetail(
                sprintf('<fg=yellow>%s</> %s', $live['state'], implode(', ', $live['steps'])),
                $live['interrupted'] ? 'interrupted' : $this->waitedFor($live['started_at']),
            );
            $this->newLine();
        }

        $history = $pipeline['history'];

        if ($history === []) {
            $this->components->info('Nothing recorded for ['.$name.'] yet.');

            return self::SUCCESS;
        }

        foreach ($history as $record) {
            $this->components->twoColumnDetail(
                $record['run'].' <fg=gray>'.$record['state'].'</>',
                $this->summarise($record),
            );
        }

        return self::SUCCESS;
    }

    /**
     * One run, joined against the walk the config declares now.
     *
     * Nothing stores the step list a past run walked, so a step added since shows
     * as never run and one removed since shows as no longer declared. The command
     * says so rather than implying the record carries a walk.
     */
    private function showRun(PipelineOverview $overview, string $name, string $runId): int
    {
        $run = $overview->run($name, $runId);

        if ($run === null) {
            $this->components->error('No run named ['.$runId.'] is recorded for ['.$name.'].');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('<options=bold>'.$run['run'].'</>', $run['state']);
        $this->components->twoColumnDetail('recorded', $run['recorded_at']);
        $this->components->twoColumnDetail('verified', $run['all_verified'] ? 'yes' : 'no');

        if ($run['scope'] !== null) {
            $this->components->twoColumnDetail('scope', $run['scope']);
        }

        if ($run['tree_matches'] !== null) {
            $this->components->twoColumnDetail(
                'tree',
                $run['tree_matches'] ? 'matches the code on disk' : 'moved since the run',
            );
        }

        $this->newLine();

        foreach ($run['positions'] as $position) {
            foreach ($position['steps'] as $step) {
                $this->components->twoColumnDetail(
                    $step['id'].($position['parallel'] ? ' <fg=gray>parallel</>' : ''),
                    ($step['verdict'] ?? '<fg=gray>not run</>').($step['log'] === null ? '' : ' <fg=gray>'.$step['log'].'</>'),
                );
            }
        }

        foreach ($run['undeclared'] as $step) {
            $this->components->twoColumnDetail(
                $step['id'].' <fg=gray>no longer declared</>',
                $step['verdict'],
            );
        }

        $this->newLine();
        $this->components->info('Steps come from the config as it stands now, not from the record.');

        return self::SUCCESS;
    }

    /**
     * The pipeline asked about, or null once the reason has been reported.
     *
     * Never guesses when the project declares several: the wrong pipeline's
     * history is a plausible-looking answer to a question nobody asked.
     */
    private function pipelineName(Pipelines $pipelines): ?string
    {
        $asked = $this->option('pipeline');
        $asked = is_string($asked) && trim($asked) !== '' ? $asked : null;

        if ($asked === null) {
            $implied = $pipelines->soleName();

            if ($implied === null) {
                $this->components->error(sprintf(
                    'This project declares %d pipelines [%s], so there is no single history to report. Ask about one with --pipeline=%s.',
                    count($pipelines->names()),
                    implode('], [', $pipelines->names()),
                    $pipelines->names()[0] ?? '',
                ));

                return null;
            }

            return $implied;
        }

        if (! $pipelines->has($asked)) {
            $this->components->error(sprintf(
                'No pipeline named [%s] is configured. This project declares [%s].',
                $asked,
                implode('], [', $pipelines->names()),
            ));

            return null;
        }

        return $asked;
    }

    /**
     * Defaults to what history keeps, so the default can never promise more runs
     * than are retained.
     */
    private function limit(): ?int
    {
        $asked = $this->option('limit');

        // Not passed at all is the default. Passed as blank is a value the caller
        // meant something by, and it is not a number.
        if ($asked === null) {
            return JsonRunHistoryStore::KEEP;
        }

        if (! is_string($asked) || preg_match('/^[1-9][0-9]*$/', trim($asked)) !== 1) {
            $this->components->error('--limit must be a positive integer, got ['.$asked.'].');

            return null;
        }

        return (int) trim($asked);
    }

    /**
     * @param  HistoryRow  $record
     */
    private function summarise(array $record): string
    {
        $counts = [];

        foreach ($record['verdicts'] as $verdict => $count) {
            $counts[] = $count.' '.$verdict;
        }

        $tree = match ($record['tree_matches']) {
            true => 'tree matches',
            false => 'tree moved',
            default => null,
        };

        return implode(' <fg=gray>·</> ', array_filter([
            $counts === [] ? 'no verdicts' : implode(', ', $counts),
            $record['scope'] === null ? null : 'scope '.$record['scope'],
            $tree,
            $record['recorded_at'],
        ], static fn (?string $part): bool => $part !== null));
    }

    private function waitedFor(string $startedAt): string
    {
        try {
            $started = new DateTimeImmutable($startedAt)->getTimestamp();
        } catch (Throwable) {
            // The record is a file on disk, so the value can be unreadable.
            return 'started at an unreadable time';
        }

        $seconds = max(0, CarbonImmutable::now()->getTimestamp() - $started);

        return $seconds < 60
            ? sprintf('%ds', $seconds)
            : sprintf('%dm %ds', intdiv($seconds, 60), $seconds % 60);
    }
}
