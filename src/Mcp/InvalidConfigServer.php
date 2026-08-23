<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp;

use Laravel\Mcp\Server;
use SanderMuller\BoostPipeline\Mcp\Tools\ExplainInvalidConfig;

/**
 * Registered in place of the real server when the config cannot be loaded.
 *
 * Registering something is what keeps the failure on the protocol. Declining left
 * `mcp:start` writing "server not found" to stdout — the JSON-RPC channel for a
 * stdio server — which is unparseable to a client, reads as a registration
 * mistake rather than a config error, and looks identical to a project that
 * deliberately never opted in. One driver hung waiting for a response that line
 * was never going to be.
 *
 * The reason itself travels on the tool error rather than in these instructions,
 * because instructions are resolved before the container holds it.
 */
final class InvalidConfigServer extends Server
{
    protected string $name = 'Pipeline (configuration error)';

    protected string $version = '0.1.0';

    protected string $instructions = <<<'TXT'
    This project's pipeline configuration could not be loaded, so no verification
    run can be opened.

    Call open_run for the reason, then report it to the user. Do not treat the
    absence of a run as a pass: nothing has been verified.
    TXT;

    /** @var array<int|string, array<int, class-string<Server\Tool>|Server\Tool>|class-string<Server\Tool>|Server\Tool> */
    protected array $tools = [
        ExplainInvalidConfig::class,
    ];
}
