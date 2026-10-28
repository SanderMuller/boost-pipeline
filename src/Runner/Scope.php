<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

/**
 * What a step's inspecting() command reported.
 *
 * A line that is `*`, apart from surrounding whitespace, makes the scope whole: the step inspects
 * everything, so no count is given, and a step declared with
 * `exclusive(onlyWhenWhole: true)` takes its slot.
 *
 * @internal
 */
final readonly class Scope
{
    public const string WHOLE_MARKER = '*';

    /**
     * @param  int|null  $count  the number of lines that name what is inspected, null when the scope is whole
     * @param  string  $output  the scope command's stdout as printed, handed to the step
     */
    public function __construct(
        public ?int $count,
        public bool $whole,
        public string $output,
    ) {}

    public static function fromOutput(string $output): self
    {
        $split = preg_split('/\R/', trim($output));
        $lines = array_values(array_filter(
            array_map(trim(...), $split === false ? [] : $split),
            static fn (string $line): bool => $line !== '',
        ));

        $whole = in_array(self::WHOLE_MARKER, $lines, true);

        return new self($whole ? null : count($lines), $whole, $output);
    }

    /**
     * The output in a file the step reads from BOOST_PIPELINE_SCOPE_FILE, or
     * false when none can be written. The caller deletes it.
     */
    public function toFile(): string|false
    {
        $path = @tempnam(sys_get_temp_dir(), 'bp-scope-');

        if ($path === false) {
            return false;
        }

        if (@file_put_contents($path, $this->output) === false) {
            @unlink($path);

            return false;
        }

        return $path;
    }
}
