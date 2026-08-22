<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Runner\OutputSummariser;

it('returns short output whole and reports it as not truncated', function (): void {
    $summary = (new OutputSummariser)->summarise("one\ntwo\nthree");

    expect($summary['summary'])->toBe("one\ntwo\nthree")
        ->and($summary['output_lines'])->toBe(3)
        ->and($summary['shown_lines'])->toBe(3)
        ->and($summary['truncated'])->toBeFalse();
});

it('truncates deterministically at the line cap', function (): void {
    $summary = (new OutputSummariser)->summarise(implode("\n", range(1, 100)));

    expect($summary['shown_lines'])->toBe(OutputSummariser::MAX_LINES)
        ->and($summary['output_lines'])->toBe(100)
        ->and($summary['truncated'])->toBeTrue();
});

it('is deterministic: the same input yields the same summary', function (): void {
    $input = implode("\n", range(1, 60));
    $summariser = new OutputSummariser;

    expect($summariser->summarise($input))->toBe($summariser->summarise($input));
});

it('caps a single absurdly long line instead of blowing the budget', function (): void {
    $summary = (new OutputSummariser)->summarise(str_repeat('x', 5000));

    expect(mb_strlen($summary['summary']))->toBeLessThanOrEqual(OutputSummariser::MAX_LINE_LENGTH + 1)
        ->and($summary['summary'])->toEndWith('…');
});

it('drops blank lines so they do not consume the cap', function (): void {
    $summary = (new OutputSummariser)->summarise("a\n\n\n\nb");

    expect($summary['output_lines'])->toBe(2);
});
