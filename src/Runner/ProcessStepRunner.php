<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Steps\Shell;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Executes a shell step in a subprocess and owns the verdict.
 *
 * The one invariant: a tool that did NOT run never yields a pass. "Ran and found
 * nothing" and "did not run" are different outcomes, so a missing binary, a
 * timeout and a thrown exception all produce Verdict::Error, never Failed and
 * never Passed.
 */
final readonly class ProcessStepRunner implements StepRunner
{
    /**
     * Under the MCP per-call wall clock, which the spec pins at 600000ms in
     * `.mcp.json`. A step allowed to outlive that would be killed by the client
     * mid-run, losing the verdict, so this default stays comfortably below it.
     */
    public const float DEFAULT_TIMEOUT_SECONDS = 540.0;

    private const float SCOPE_TIMEOUT_SECONDS = 60.0;

    /** Shell conventions: 127 = not found, 126 = found but not executable. */
    private const array UNRUNNABLE_EXIT_CODES = [126, 127];

    public function __construct(
        private string $workingDirectory,
        private LogWriter $logs,
        private OutputSummariser $summariser,
        private EnvironmentScrubber $environment,
        private string $runId,
        private float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    public function run(Step $step): Result
    {
        if ($step->kind() !== StepKind::Shell || ! $step instanceof Shell) {
            // A skill step is resolved by the agent acknowledging it, never here.
            return Result::error($step->id(), 'Only shell steps can be executed by the server.');
        }

        try {
            $step->before();
        } catch (Throwable $throwable) {
            return Result::error($step->id(), "Step setup failed: {$throwable->getMessage()}");
        }

        $result = $this->execute($step);

        try {
            $step->after($result);
        } catch (Throwable) {
            // Teardown failure must not rewrite a verdict the tool already earned.
        }

        return $result;
    }

    private function execute(Shell $step): Result
    {
        $scope = $this->resolveScope($step);

        if ($scope instanceof Result) {
            return $scope;
        }

        // The command ALWAYS runs. An empty scope annotates the verdict; it never
        // replaces it. Short-circuiting here would mean a typo'd scope glob, or a
        // scope command that silently matches nothing, permanently disables the
        // gate — a false green of exactly the kind this pipeline exists to stop.
        $process = $this->process($step->id(), $step->command(), $this->timeoutSeconds, $step->env());

        if ($process instanceof Result) {
            return $process;
        }

        $output = $this->combinedOutput($process);
        $logPath = $this->logs->write($this->runId, $step->id(), $output);
        $exitCode = $process->getExitCode() ?? 1;

        if (in_array($exitCode, self::UNRUNNABLE_EXIT_CODES, true)) {
            return Result::error(
                $step->id(),
                sprintf('Command did not run (exit %d): %s', $exitCode, $this->orElse(trim($output), 'no output')),
                logPath: $logPath,
            );
        }

        $summary = $this->summariser->summarise($output);

        if ($exitCode !== 0) {
            return Result::failed(
                $step->id(),
                $this->describeFailure($summary, $logPath),
                $exitCode,
                $logPath,
                $scope,
            );
        }

        return Result::passed(
            $step->id(),
            $this->describePass($summary, $scope),
            $scope,
            $logPath,
        );
    }

    /**
     * The number of files the step declared it would inspect, null when it
     * declared no scope, or an error Result when the scope cannot be computed.
     *
     * A declared-but-uncomputable scope is a broken config, not "unknown": if the
     * scope command cannot run, treating it as zero would hand back a pass.
     */
    private function resolveScope(Shell $step): int|null|Result
    {
        $command = $step->scopeCommand();

        if ($command === null) {
            return null;
        }

        $process = $this->process($step->id(), $command, self::SCOPE_TIMEOUT_SECONDS, $step->env());

        if ($process instanceof Result) {
            return Result::error($step->id(), "Could not determine declared scope: {$process->summary}");
        }

        if ($process->getExitCode() !== 0) {
            return Result::error($step->id(), sprintf(
                "Scope command exited %d, so the step's scope is unknown: %s",
                $process->getExitCode() ?? 1,
                $this->orElse(trim($this->combinedOutput($process)), 'no output'),
            ));
        }

        return count($this->nonEmptyLines($process->getOutput()));
    }

    /** @param array<string, string> $env */
    private function process(string $stepId, string $command, float $timeout, array $env = []): Process|Result
    {
        try {
            $process = Process::fromShellCommandline(
                $command,
                $this->workingDirectory,
                $this->environment->forStep($env),
                timeout: $timeout,
            );

            $process->run();

            return $process;
        } catch (ProcessTimedOutException) {
            return Result::error($stepId, "Timed out after {$timeout}s.");
        } catch (Throwable $exception) {
            return Result::error($stepId, "Could not run: {$exception->getMessage()}");
        }
    }

    private function combinedOutput(Process $process): string
    {
        return implode("\n", array_filter([
            rtrim($process->getOutput()),
            rtrim($process->getErrorOutput()),
        ], static fn (string $part): bool => $part !== ''));
    }

    /** @param array{summary: string, output_lines: int, shown_lines: int, truncated: bool} $summary */
    private function describePass(array $summary, ?int $scope): string
    {
        $text = $this->orElse($summary['summary'], 'Passed.');

        if ($scope !== 0) {
            return $text;
        }

        return "Inspected 0 files: this step is scoped to a set that is currently empty, so it passed without proving anything.\n\n".$text;
    }

    /** @param array{summary: string, output_lines: int, shown_lines: int, truncated: bool} $summary */
    private function describeFailure(array $summary, ?string $logPath): string
    {
        $text = $this->orElse($summary['summary'], 'Failed with no output.');

        if (! $summary['truncated']) {
            return $text;
        }

        $remaining = $summary['output_lines'] - $summary['shown_lines'];

        return $text."\n\n".sprintf(
            '… %d more line(s)%s',
            $remaining,
            $logPath === null ? '.' : " — full output at {$logPath}",
        );
    }

    /** @return list<string> */
    private function nonEmptyLines(string $output): array
    {
        return array_values(array_filter(
            $this->splitLines($output),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }

    /** @return list<string> */
    private function splitLines(string $output): array
    {
        $lines = preg_split('/\R/', trim($output));

        return $lines === false ? [] : $lines;
    }

    private function orElse(string $value, string $fallback): string
    {
        return $value === '' ? $fallback : $value;
    }
}
