<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Http\LoopbackOnly;

return [

    /*
    |--------------------------------------------------------------------------
    | The pipeline page
    |--------------------------------------------------------------------------
    |
    | A page showing every declared pipeline, the run in flight and the runs
    | before it. Off by default, and it registers only in a local environment —
    | both, because a flag committed by mistake must not open it in production.
    |
    | Neither of those is access control. `APP_ENV=local` describes the
    | application, not the requester, and a local server routinely listens on a
    | LAN address or behind a tunnel. The routes serve raw command output, so
    | `LoopbackOnly` refuses a request that did not come from this machine.
    | Replace it deliberately if the page has to be reachable from another one.
    |
    */

    'ui' => [
        'enabled' => env('BOOST_PIPELINE_UI', false),

        'path' => 'boost-pipelines',

        'middleware' => ['web', LoopbackOnly::class],
    ],

    /*
    |--------------------------------------------------------------------------
    | Comparing the run's config against the config on disk
    |--------------------------------------------------------------------------
    |
    | A run records a digest of the pipeline declaration it walked, and
    | `pipeline:verify` refuses a run whose digest is not the one the config
    | produces now. That catches a server which loaded the config before it
    | changed: it runs an older definition of the same step id and records it as
    | a pass, and nothing else notices, because the verdicts are keyed by id and
    | the tree fingerprint matches.
    |
    | Turn this off ONLY if your config computes part of its declaration when it
    | loads — a command built from an environment variable, a step list read from
    | a file outside the repository. The config file is arbitrary PHP, so that is
    | allowed, but it means two processes can produce different digests from
    | files nobody touched, and the gate would then fail with nothing wrong.
    |
    | Switching it off gives up a real check. Nothing else compensates for it.
    |
    */

    'verify' => [
        'config_fingerprint' => true,
    ],

];
