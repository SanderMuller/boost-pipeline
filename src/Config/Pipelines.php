<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Config;

use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;

/**
 * Every pipeline a project declares, by name.
 *
 * A project asks more than one question of its own code — is this ready for a
 * PR, is it ready to release, what does an evaluation loop find — and those have
 * different steps in a different order. One pipeline could only answer one of
 * them, and tags could only ever narrow that one walk: they share its phase
 * order, and there is a single receipt, so a second scoped run replaces the
 * first and two answers can never be true at once.
 *
 * A name is not a scope. A scope narrows one walk; a name selects which walk
 * exists at all.
 */
final readonly class Pipelines
{
    /**
     * The name a config file gets when it returns a bare Pipeline.
     *
     * Not reserved: a map may declare `default` itself and means the same thing
     * by it.
     */
    public const string DEFAULT = 'default';

    /**
     * A name becomes a filename component under `receipts/`, so it is validated
     * rather than sanitised. Silently rewriting `../escape` into something safe
     * would hide the mistake and still not be the pipeline the caller asked for.
     */
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /**
     * @param  array<string, Pipeline>  $pipelines
     * @param  bool  $named  whether the config file named them, rather than returning one Pipeline
     */
    private function __construct(public array $pipelines, private bool $named) {}

    public static function single(Pipeline $pipeline): self
    {
        return new self([self::DEFAULT => $pipeline], named: false);
    }

    /**
     * @param  array<mixed, mixed>  $declared
     *
     * @throws InvalidPipelineConfigException
     */
    public static function fromArray(array $declared, string $path): self
    {
        if ($declared === []) {
            throw InvalidPipelineConfigException::declaredNoPipelines($path);
        }

        $pipelines = [];

        foreach ($declared as $name => $pipeline) {
            // A bare list of pipelines is the likely mistake, and PHP gives it
            // integer keys, so the message names that rather than the pattern.
            if (! is_string($name)) {
                throw InvalidPipelineConfigException::pipelineNameNotAString($path, $name);
            }

            if (! $pipeline instanceof Pipeline) {
                throw InvalidPipelineConfigException::mapValueNotAPipeline($path, $name, get_debug_type($pipeline));
            }

            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw InvalidPipelineConfigException::invalidPipelineName($name);
            }

            $pipelines[$name] = $pipeline;
        }

        return new self($pipelines, named: true);
    }

    public function get(string $name): ?Pipeline
    {
        return $this->pipelines[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->pipelines[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->pipelines);
    }

    public function isSingle(): bool
    {
        return count($this->pipelines) === 1;
    }

    /**
     * The only pipeline, when there is only one.
     *
     * Bindings that predate names resolve through this, so a project with one
     * pipeline keeps working unchanged. It throws rather than picking one,
     * because "the pipeline" has no answer in a project with three and guessing
     * would hand back a walk the caller never asked for.
     *
     * @throws InvalidPipelineConfigException
     */
    public function sole(): Pipeline
    {
        if (! $this->isSingle()) {
            throw InvalidPipelineConfigException::noSolePipeline($this->names());
        }

        return $this->pipelines[$this->names()[0]];
    }

    /**
     * Whether a caller has to say which pipeline it means.
     *
     * True as soon as the config file names its pipelines, even when it names
     * only one. A project that writes `['pr' => ...]` today and adds `release`
     * tomorrow would otherwise find every call site that omitted the name
     * breaking on the day the second one arrives — and the ones that kept
     * working would be the ones that had been guessing.
     */
    public function requiresName(): bool
    {
        return $this->named;
    }

    /**
     * The name to use when a caller did not give one.
     *
     * Null once the config names its pipelines: there is no default, and
     * inventing one would run a pipeline nobody selected.
     */
    public function implied(): ?string
    {
        return $this->named ? null : $this->soleName();
    }

    /**
     * The only pipeline's name, when there is only one.
     *
     * Counts, where `implied()` asks about the declaration shape. A map holding
     * one pipeline still has a sole pipeline, which is what the container
     * aliases and a bare `pipeline:verify` are asking about — "is this tree
     * verified" has exactly one answer there, whether or not the file named it.
     */
    public function soleName(): ?string
    {
        return $this->isSingle() ? $this->names()[0] : null;
    }
}
