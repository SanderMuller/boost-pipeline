<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

/**
 * Bounds a step's output for the MCP response.
 *
 * MCP output warns at 10,000 tokens and is capped at 25,000, so a failing static
 * analysis run cannot be returned whole. Truncation is deterministic — a fixed
 * line cap — never a model call.
 *
 * v1 counts LINES, not failures. Counting failures needs a per-tool parser, and
 * those are deferred; reporting a line count as a failure count would be a lie
 * that happens to look like the spec's example.
 *
 * Truncation keeps the HEAD and the TAIL, never just the head. Tools disagree
 * about where they put the part you need: PHPStan leads with findings, while a
 * test runner leads with progress noise and ends with the failure and its
 * timings. Keeping both ends makes a payload predictable regardless of which
 * shape a step produces.
 */
final readonly class OutputSummariser
{
    public const int MAX_LINES = 20;

    public const int MAX_LINE_LENGTH = 400;

    /** @return list<string> */
    private function split(string $output): array
    {
        $lines = preg_split('/\R/', trim($this->readable($output)));

        return $lines === false ? [] : $lines;
    }

    /**
     * Strip what a terminal would have consumed rather than displayed.
     *
     * A tool that draws — a progress bar, a dot per test — writes colour codes
     * and rewrites one line with carriage returns. Captured to a pipe, none of
     * that renders: a PHPUnit summary arrives as an escape-wrapped dot repeated
     * to the truncation limit, and Rector's is almost entirely redraw frames. The
     * summary is the only output the agent sees without opening the log, so left
     * as-is it spends the whole budget saying nothing.
     *
     * Only the last segment of a carriage-return sequence survives, which is what
     * the terminal would have left on screen.
     */
    private function readable(string $output): string
    {
        // Colour and cursor-movement escapes: '\e[' then parameters then a letter.
        $stripped = preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $output);
        $output = $stripped ?? $output;

        // Keep only what survived the last carriage return on each line, which is
        // what the terminal would have been left showing.
        $collapsed = preg_replace('/^.*\r(?!\n)/m', '', $output);

        return $collapsed ?? $output;
    }

    /**
     * @return array{summary: string, output_lines: int, shown_lines: int, truncated: bool}
     */
    public function summarise(string $output, int $maxLines = self::MAX_LINES): array
    {
        $lines = array_values(array_filter(
            $this->split($output),
            static fn (string $line): bool => trim($line) !== '',
        ));

        $shown = $this->headAndTail($lines, $maxLines);

        $clamped = array_map(
            static fn (string $line): string => mb_strlen($line) > self::MAX_LINE_LENGTH
                ? mb_substr($line, 0, self::MAX_LINE_LENGTH).'…'
                : $line,
            $shown,
        );

        $omitted = count($lines) - count($shown);

        if ($omitted > 0) {
            // Stated inline, because a consumer reading only `summary` would
            // otherwise see two ends spliced together as if they were adjacent.
            $head = (int) ceil($maxLines / 2);
            array_splice($clamped, $head, 0, [sprintf('… %d lines omitted …', $omitted)]);
        }

        return [
            'summary' => implode("\n", $clamped),
            'output_lines' => count($lines),
            'shown_lines' => count($shown),
            'truncated' => $omitted > 0,
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function headAndTail(array $lines, int $maxLines): array
    {
        if ($maxLines < 1) {
            return [];
        }

        if (count($lines) <= $maxLines) {
            return $lines;
        }

        $head = (int) ceil($maxLines / 2);

        return [
            ...array_slice($lines, 0, $head),
            ...array_slice($lines, -($maxLines - $head)),
        ];
    }
}
