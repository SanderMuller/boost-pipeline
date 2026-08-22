<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;

it("derives the step ids the spec's own contract examples show", function (string $command, string $expected): void {
    expect(Shell::run($command)->id())->toBe($expected);
})->with([
    ['vendor/bin/pint --test', 'pint'],
    ['vendor/bin/rector process --dry-run', 'rector'],
    ['composer phpstan', 'phpstan'],
    ['yarn typecheck', 'typecheck'],
    ['yarn lint-all', 'lint-all'],
    ['yarn test:js', 'test-js'],
]);

it('prefers an explicit id over the derived one', function (): void {
    expect(Shell::run('git diff --quiet -- composer.lock', id: 'deps-unchanged')->id())
        ->toBe('deps-unchanged');
});

it('never derives an empty id', function (): void {
    expect(Shell::run('   ')->id())->toBe('step')
        ->and(Shell::run('---')->id())->toBe('step');
});

it('slugifies a skill invocation into an id', function (): void {
    expect(Skill::run('/evaluate')->id())->toBe('evaluate')
        ->and(Skill::run('/eye-verification')->id())->toBe('eye-verification');
});

it('reports its kind, so the walk knows who resolves it', function (): void {
    expect(Shell::run('composer phpstan')->kind())->toBe(StepKind::Shell)
        ->and(Skill::run('/evaluate')->kind())->toBe(StepKind::Skill);
});

describe('verdicts', function (): void {
    it('advances the cursor only on passed and acknowledged', function (): void {
        expect(Verdict::Passed->advancesCursor())->toBeTrue()
            ->and(Verdict::Acknowledged->advancesCursor())->toBeTrue()
            ->and(Verdict::Failed->advancesCursor())->toBeFalse()
            ->and(Verdict::Error->advancesCursor())->toBeFalse();
    });

    it('counts only passed as verified, so an acknowledgement can never read as a pass', function (): void {
        expect(Verdict::Passed->isVerified())->toBeTrue()
            ->and(Verdict::Acknowledged->isVerified())->toBeFalse()
            ->and(Verdict::Failed->isVerified())->toBeFalse()
            ->and(Verdict::Error->isVerified())->toBeFalse();
    });

    it('separates "who produced the verdict" from "did it pass"', function (): void {
        // A failed step was still run by the server: server_run answers a
        // different question from isVerified(), and conflating them is the
        // defect this pair of assertions exists to prevent.
        expect(Result::failed('phpstan', '12 errors', exitCode: 1)->serverRun())->toBeTrue()
            ->and(Result::failed('phpstan', '12 errors', exitCode: 1)->verdict->isVerified())->toBeFalse()
            ->and(Result::error('pint', 'binary missing')->serverRun())->toBeTrue()
            ->and(Result::acknowledged('evaluate', 'done')->serverRun())->toBeFalse();
    });

    it('treats a tool that did not run as terminal, unlike a tool that found problems', function (): void {
        expect(Verdict::Error->isTerminalForRun())->toBeTrue()
            ->and(Verdict::Failed->isTerminalForRun())->toBeFalse();
    });

    it('keeps a zero files_inspected visible, because that is how a vacuous pass shows up', function (): void {
        $result = Result::passed('lint-all', 'nothing to lint', filesInspected: 0);

        expect($result->toArray())->toHaveKey('files_inspected')
            ->and($result->toArray()['files_inspected'])->toBe(0);
    });
});
