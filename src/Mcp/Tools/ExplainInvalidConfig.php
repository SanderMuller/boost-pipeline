<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use SanderMuller\BoostPipeline\Config\ConfigError;

/**
 * Stands in for `open_run` when the config could not be loaded.
 *
 * Named `open_run` deliberately: the server instructions and the driving prompt
 * both say to call it first, so the agent reaches the explanation by doing the
 * normal thing rather than by discovering a diagnostic tool it has no reason to
 * try.
 *
 * The alternative — declining to register at all — left `mcp:start` reporting
 * "server not found" on the protocol channel, which is both false (it was
 * registered, then withdrawn) and indistinguishable from a project that never
 * opted in. Worse, an unparseable frame is a response the client never gets: one
 * driver hung waiting for it.
 */
final class ExplainInvalidConfig extends Tool
{
    protected string $name = 'open_run';

    protected string $description = "Unavailable: this project's pipeline configuration could not be loaded. Call this to see why.";

    public function __construct(private readonly ConfigError $error) {}

    /** @return array<string, mixed> */
    public function annotations(): array
    {
        return ['readOnlyHint' => true];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'error' => $schema->string()->description('Why the pipeline configuration could not be loaded.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return Response::error(sprintf(
            "This project's pipeline configuration could not be loaded, so no run can be opened.\n\n%s\n\nFix .config/pipeline.php and restart the server.",
            $this->error->message,
        ));
    }
}
