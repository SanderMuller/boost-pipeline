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

it('keeps both ends when it truncates, because tools disagree about where the point is', function (): void {
    // PHPStan leads with findings; a test runner leads with progress noise and
    // ends with the failure. Head-only truncation loses the second one entirely.
    $output = implode("\n", array_map(static fn (int $n): string => "line {$n}", range(1, 100)));

    $result = new OutputSummariser()->summarise($output, maxLines: 10);

    expect($result['output_lines'])->toBe(100)
        ->and($result['shown_lines'])->toBe(10)
        ->and($result['truncated'])->toBeTrue()
        ->and($result['summary'])->toContain('line 1')
        ->and($result['summary'])->toContain('line 100')
        ->and($result['summary'])->toContain('… 90 lines omitted …')
        ->and($result['summary'])->not->toContain('line 50');
});

it('shows everything, with no elision marker, when the output fits', function (): void {
    $result = new OutputSummariser()->summarise("one\ntwo\nthree", maxLines: 10);

    expect($result['truncated'])->toBeFalse()
        ->and($result['shown_lines'])->toBe(3)
        ->and($result['summary'])->toBe("one\ntwo\nthree");
});

it('strips the escapes a terminal would have consumed, so a drawing tool says something', function (): void {
    // What a real run returned for the phpunit step: an escape-wrapped dot per
    // test, repeated to the truncation limit, with the verdict pushed out of view.
    $output = str_repeat("\033[90;1m.\033[39;22m", 40)."\n\033[31;1mFAILED\033[39;22m  153 failed, 8250 passed";

    $result = new OutputSummariser()->summarise($output, maxLines: 6);

    expect($result['summary'])->not->toContain("\033")
        ->and($result['summary'])->toContain('FAILED  153 failed, 8250 passed');
});

it('keeps only the last frame of a redrawn line', function (): void {
    // A progress bar rewrites one line with carriage returns. Captured to a pipe
    // every frame survives, and the useful last one is what gets truncated away.
    $result = new OutputSummariser()->summarise("processing\r 10/60 files\r 60/60 files\nDone: 0 changed", maxLines: 6);

    expect($result['summary'])->toBe("60/60 files\nDone: 0 changed");
});

it('leaves output that never drew anything untouched', function (): void {
    $result = new OutputSummariser()->summarise("Line 1\nLine 2", maxLines: 6);

    expect($result['summary'])->toBe("Line 1\nLine 2");
});

it('strips the escape forms beyond plain colour codes', function (): void {
    $summariser = new OutputSummariser;

    expect($summariser->summarise("\033[38:2:255:0:0mred\033[0m done", 4)['summary'])->toBe('red done')
        ->and($summariser->summarise("\033]8;;https://example.com\033\\link\033]8;;\033\\ done", 4)['summary'])->toBe('link done')
        ->and($summariser->summarise("\033[?25lhidden\033[?25h done", 4)['summary'])->toBe('hidden done');
});

it('bounds the work when a tool draws megabytes onto one line', function (): void {
    // No newlines, so nothing splits it. Every pass copies the string, and a
    // summary that shows 20 lines has no reason to scan the rest.
    $result = new OutputSummariser()->summarise(str_repeat('x', 5_000_000), 4);

    // Two segments, because the cap keeps both ends of the input.
    expect($result['shown_lines'])->toBe(2)
        ->and(strlen($result['summary']))->toBeLessThan(1000);
});

it('keeps the end of a huge output, where the verdict usually is', function (): void {
    // A head-only byte cap dropped everything past it, so a progress line
    // megabytes long buried the one line that mattered.
    $output = str_repeat('progress ', 400_000)."\nFAILED 3 tests";

    $result = new OutputSummariser()->summarise($output, 6);

    expect($result['summary'])->toContain('FAILED 3 tests');
});
