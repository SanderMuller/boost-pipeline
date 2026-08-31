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

];
