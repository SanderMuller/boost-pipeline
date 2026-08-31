<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Run\PipelineOverview;
use SanderMuller\BoostPipeline\Run\StepLogReader;

/**
 * Serves the page and the data it polls.
 *
 * Reading the overview executes the consumer's `.config/pipeline.php` — a third
 * execution context for that file, after the MCP server and the console. A
 * config error is rendered rather than thrown, matching what the invalid-config
 * server shows an agent. Anything else is left to surface: the provider catches
 * only this package's own validation errors, on the stated ground that
 * swallowing a TypeError would hide a real bug in consumer code.
 */
final readonly class PipelineController
{
    /**
     * Stands in for a route parameter the page fills in per step.
     *
     * Deliberately a string no id can produce: `SafeFilename` keeps ids to
     * `[A-Za-z0-9._-]`, and this holds neither a letter nor a digit.
     */
    private const string URL_PLACEHOLDER = '---';

    /**
     * The overview is resolved inside the try, never injected.
     *
     * Building it resolves `Pipelines`, which loads the consumer's config — so an
     * injected parameter throws during dependency resolution, before any handler
     * body runs, and the catch below would never see it.
     */
    public function __construct(private Container $container) {}

    public function page(ViewFactory $views, UrlGenerator $urls): View
    {
        return $views->make('boost-pipeline::page', [
            ...$this->payload(),
            'dataUrl' => $urls->route('boost-pipeline.data'),
            // Built from the named route, never from the configured path: the
            // provider normalises that value before registering, so rebuilding it
            // in the view would break the page on a path the routes accepted.
            'logUrlTemplate' => $urls->route('boost-pipeline.log', [
                'pipeline' => self::URL_PLACEHOLDER.'P',
                'run' => self::URL_PLACEHOLDER.'R',
                'step' => self::URL_PLACEHOLDER.'S',
            ]),
            'urlPlaceholder' => self::URL_PLACEHOLDER,
        ]);
    }

    public function data(): JsonResponse
    {
        return new JsonResponse($this->payload());
    }

    /**
     * One step's output, on demand.
     *
     * The ids address a record, never a file: the path comes from that run's
     * recorded log map and is checked against the log root before it is read.
     * A step that wrote no log, a log since deleted, and a path resolving outside
     * the root are one answer here — 404, rather than a hint about which.
     */
    public function log(StepLogReader $logs, string $pipeline, string $run, string $step): JsonResponse
    {
        try {
            $summary = $logs->read($pipeline, $run, $step);
        } catch (InvalidPipelineConfigException) {
            // An undeclared pipeline name in the URL. The stores refuse it, and
            // that refusal is a bad request rather than a server fault.
            $summary = null;
        }

        return $summary === null
            ? new JsonResponse(['message' => 'No readable log for that step.'], 404)
            : new JsonResponse($summary);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        try {
            return [
                'pipelines' => $this->container->make(PipelineOverview::class)->all(),
                'error' => null,
            ];
        } catch (InvalidPipelineConfigException $invalidPipelineConfigException) {
            return ['pipelines' => [], 'error' => $invalidPipelineConfigException->getMessage()];
        }
    }
}
