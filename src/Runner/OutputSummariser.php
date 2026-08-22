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
 */
final readonly class OutputSummariser
{
    public const int MAX_LINES = 20;

    public const int MAX_LINE_LENGTH = 400;

    /**
     * @return array{summary: string, output_lines: int, shown_lines: int, truncated: bool}
     */
    public function summarise(string $output, int $maxLines = self::MAX_LINES): array
    {
        $lines = array_values(array_filter(
            preg_split('/\R/', trim($output)) ?: [],
            static fn (string $line): bool => trim($line) !== '',
        ));

        $shown = array_slice($lines, 0, $maxLines);

        $shown = array_map(
            static fn (string $line): string => mb_strlen($line) > self::MAX_LINE_LENGTH
                ? mb_substr($line, 0, self::MAX_LINE_LENGTH).'…'
                : $line,
            $shown,
        );

        return [
            'summary' => implode("\n", $shown),
            'output_lines' => count($lines),
            'shown_lines' => count($shown),
            'truncated' => count($lines) > count($shown),
        ];
    }
}
