<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Registrar;
use SanderMuller\BoostPipeline\BoostPipelineServiceProvider;

/**
 * The page is gated three ways, and each gate answers a different question:
 * whether a consumer asked for it, whether this environment may serve it, and
 * whether the requester is on this machine. The routes serve raw command output,
 * so none of the three is redundant.
 */
function bootUi(bool $enabled, string $environment, ?string $config = null): void
{
    $path = app()->basePath('.config/pipeline.php');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }

    file_put_contents($path, $config ?? <<<'PHP'
        <?php

        use SanderMuller\BoostPipeline\Config\Pipeline;
        use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
        use SanderMuller\BoostPipeline\Phases\Steps;
        use SanderMuller\BoostPipeline\Steps\Shell;

        return Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
        });
        PHP);

    config()->set('boost-pipeline.ui.enabled', $enabled);
    // The default middleware group encrypts cookies, and the test app ships no
    // key. Throwaway, and never reused outside this suite.
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    app()->instance('env', $environment);
    app()->instance(Registrar::class, new Registrar);

    new BoostPipelineServiceProvider(app())->boot();

    // Routes registered after the application booted are not in the name lookup
    // until it is rebuilt, so `Route::has()` would answer about the wrong moment.
    Route::getRoutes()->refreshNameLookups();
}

afterEach(function (): void {
    @unlink(app()->basePath('.config/pipeline.php'));
    TrustProxies::at([]);
});

it('registers no route when a consumer never asked for the page', function (): void {
    bootUi(enabled: false, environment: 'local');

    expect(Route::has('boost-pipeline.page'))->toBeFalse()
        ->and(Route::has('boost-pipeline.data'))->toBeFalse();
});

it('registers no route outside a local environment, even when enabled', function (): void {
    // A flag committed by mistake must not open the page in production.
    bootUi(enabled: true, environment: 'production');

    expect(Route::has('boost-pipeline.page'))->toBeFalse();
});

it('registers no route when the project declares no pipeline', function (): void {
    config()->set('boost-pipeline.ui.enabled', true);
    app()->instance('env', 'local');
    app()->instance(Registrar::class, new Registrar);

    new BoostPipelineServiceProvider(app())->boot();
    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('boost-pipeline.page'))->toBeFalse();
});

it('registers both routes when asked for and local', function (): void {
    bootUi(enabled: true, environment: 'local');

    expect(Route::has('boost-pipeline.page'))->toBeTrue()
        ->and(Route::has('boost-pipeline.data'))->toBeTrue();
});

it('serves the read model as JSON to a request from this machine', function (): void {
    bootUi(enabled: true, environment: 'local');

    $response = $this->get('/boost-pipelines/data');

    $response->assertOk();

    expect($response->json('error'))->toBeNull()
        ->and($response->json('pipelines.0.pipeline'))->toBe('default');
});

it('refuses a request from another machine on both routes', function (): void {
    // APP_ENV describes the application, not the requester, and a local server
    // routinely listens on a LAN address or behind a tunnel.
    bootUi(enabled: true, environment: 'local');

    $page = $this->call('GET', '/boost-pipelines', server: ['REMOTE_ADDR' => '10.0.0.5']);
    $data = $this->call('GET', '/boost-pipelines/data', server: ['REMOTE_ADDR' => '10.0.0.5']);

    $page->assertForbidden();
    $data->assertForbidden();

    // The body, not just the status. The middleware returns a plain response
    // rather than calling `abort()`, so the refusal never travels through the
    // host application's exception handler — one consumer's handler queries the
    // database to translate its error page, which turned this 403 into a 500
    // with a SQL error. Asserting the text proves the handler is out of the loop.
    $page->assertContent('The pipeline page is reachable from this machine only.');
});

it('renders a config error rather than failing the request', function (): void {
    bootUi(enabled: true, environment: 'local', config: <<<'PHP'
        <?php

        use SanderMuller\BoostPipeline\Config\Pipeline;

        return ['Not A Valid Name' => Pipeline::configure()];
        PHP);

    $response = $this->get('/boost-pipelines/data');

    $response->assertOk();

    expect($response->json('error'))->toContain('Not A Valid Name')
        ->and($response->json('pipelines'))->toBe([]);
});

it('lets a throwable that is not a config error surface', function (): void {
    // The provider deliberately catches only this package's own validation
    // errors: swallowing a TypeError would hide a real bug in consumer code.
    bootUi(enabled: true, environment: 'local', config: '<?php throw new LogicException("consumer bug");');

    $this->withoutExceptionHandling();

    expect(fn (): mixed => $this->get('/boost-pipelines/data'))
        ->toThrow(LogicException::class, 'consumer bug');
});

it('executes the consumer config once per application, however many pipelines it declares', function (): void {
    $marker = storage_path('bp-config-loads.txt');
    @unlink($marker);

    bootUi(enabled: true, environment: 'local', config: sprintf(<<<'PHP'
        <?php

        use SanderMuller\BoostPipeline\Config\Pipeline;

        file_put_contents(%s, "x", FILE_APPEND);

        return ['one' => Pipeline::configure(), 'two' => Pipeline::configure()];
        PHP, var_export($marker, true)));

    $this->get('/boost-pipelines/data')->assertOk();
    $this->get('/boost-pipelines/data')->assertOk();

    // `Pipelines` is a singleton, so two declared pipelines and two requests
    // still cost one load — this test app keeps the container across requests, as
    // a persistent worker would. A request-per-process server pays it per
    // request; either way the number of declared pipelines does not multiply it,
    // which is what a polling page depends on.
    expect(file_get_contents($marker))->toBe('x');

    @unlink($marker);
});

it('refuses a forwarded-for header claiming to be this machine', function (): void {
    // The host app trusts proxies, which is ordinary. `Request::ip()` would then
    // return the X-Forwarded-For value and let anyone who can reach the port
    // claim to be loopback. Reading REMOTE_ADDR is what makes the gate hold.
    bootUi(enabled: true, environment: 'local');
    TrustProxies::at('*');

    $this->call('GET', '/boost-pipelines/data', server: [
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
    ])->assertForbidden();
});

it('allows the loopback forms a dual-stack server reports', function (): void {
    bootUi(enabled: true, environment: 'local');

    foreach (['127.0.0.1', '127.0.0.53', '::1', '::ffff:127.0.0.1'] as $address) {
        $this->call('GET', '/boost-pipelines/data', server: ['REMOTE_ADDR' => $address])->assertOk();
    }
});

it('keeps the loopback gate when the published config drops it', function (): void {
    // A partial published config must not leave routes serving raw command output
    // open. Only a list the consumer actually wrote replaces the shipped default.
    bootUi(enabled: true, environment: 'local');
    config()->set('boost-pipeline.ui.middleware');
    app()->instance(Registrar::class, new Registrar);
    new BoostPipelineServiceProvider(app())->boot();

    $this->call('GET', '/boost-pipelines/data', server: ['REMOTE_ADDR' => '10.0.0.5'])->assertForbidden();
});

it('honours a middleware list the consumer did write', function (): void {
    // Replacing the gate deliberately stays possible — that is the documented way
    // to reach the page from another machine.
    bootUi(enabled: true, environment: 'local');
    config()->set('boost-pipeline.ui.middleware', ['web']);
    app()->instance(Registrar::class, new Registrar);
    new BoostPipelineServiceProvider(app())->boot();
    Route::getRoutes()->refreshNameLookups();

    $this->call('GET', '/boost-pipelines/data', server: ['REMOTE_ADDR' => '10.0.0.5'])->assertOk();
});
