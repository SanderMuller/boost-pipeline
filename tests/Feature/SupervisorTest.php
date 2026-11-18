<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Drives bin/boost-pipeline-mcp as a client would: lines in on STDIN, lines out on STDOUT.
 */
final class SupervisorSession
{
    private readonly Process $process;

    private readonly InputStream $input;

    private string $buffer = '';

    /**
     * @param  array<string, string>  $environment
     * @param  list<string>|null  $server  the server command; the fake server by default
     */
    public function __construct(string $script, string $state, array $environment = [], ?array $server = null)
    {
        $this->input = new InputStream;
        $this->process = new Process(
            [PHP_BINARY, $script, ...($server ?? [PHP_BINARY, __DIR__.'/../Fixtures/fake-mcp-server.php', $state])],
            env: ['BOOST_PIPELINE_SUPERVISOR_DELAYS' => '0.1,0.1', ...$environment],
            input: $this->input,
            timeout: null,
        );
        $this->process->start();
    }

    /** @param  array<string, mixed>  $message */
    public function send(array $message): void
    {
        $this->input->write(json_encode($message)."\n");
    }

    public function call(int $id, string $tool): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]]);
    }

    /** @return array<mixed> */
    public function receive(float $timeout = 5.0): array
    {
        $deadline = microtime(true) + $timeout;

        while (! str_contains($this->buffer, "\n")) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('No answer from the supervisor. Got: '.substr($this->buffer, 0, 200));
            }

            $this->buffer .= $this->process->getIncrementalOutput();

            if (! str_contains($this->buffer, "\n")) {
                Sleep::usleep(10_000);
            }
        }

        [$line, $this->buffer] = explode("\n", $this->buffer, 2);
        $message = json_decode($line, true);

        return is_array($message) ? $message : [];
    }

    /** Wait, and keep passing queued input to the supervisor: the process writes it only when polled. */
    public function pause(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $this->process->isRunning();
            Sleep::usleep(5_000);
        }
    }

    /** Close the client side and wait for the supervisor's exit code. */
    public function close(float $timeout = 5.0): ?int
    {
        $this->input->close();

        return $this->waitForExit($timeout);
    }

    public function waitForExit(float $timeout = 5.0): ?int
    {
        $deadline = microtime(true) + $timeout;

        while ($this->process->isRunning()) {
            if (microtime(true) > $deadline) {
                $this->process->stop(0);

                throw new RuntimeException('The supervisor did not exit.');
            }

            Sleep::usleep(20_000);
        }

        return $this->process->getExitCode();
    }

    public function __destruct()
    {
        if ($this->process->isRunning()) {
            $this->process->stop(0);
        }
    }
}

/** @param  array<mixed>  $answer */
function textOf(array $answer): string
{
    $text = data_get($answer, 'result.content.0.text');

    return is_string($text) ? $text : '';
}

/** @param  array<mixed>  $answer */
function errorOf(array $answer): string
{
    $message = data_get($answer, 'error.message');

    return is_string($message) ? $message : '';
}

/**
 * The lines the fake servers read, as [pid, line] pairs.
 *
 * @return list<array{string, string}>
 */
function receivedLines(string $state): array
{
    $lines = array_filter(explode("\n", (string) file_get_contents($state.'/received.log')), static fn (string $line): bool => $line !== '');

    return array_values(array_map(static function (string $line): array {
        [$pid, $rest] = explode(' ', $line, 2);

        return [$pid, $rest];
    }, $lines));
}

/**
 * How many servers read a line, and the lines the last one read.
 *
 * @param  string|null  $waitFor  wait up to two seconds for a line that contains this: a server answers before it reads the line after the request
 * @return array{int, list<string>}
 */
function lastServerLines(string $state, ?string $waitFor = null): array
{
    $deadline = microtime(true) + 2;

    while ($waitFor !== null && ! str_contains((string) file_get_contents($state.'/received.log'), $waitFor) && microtime(true) < $deadline) {
        Sleep::usleep(10_000);
    }

    $log = receivedLines($state);
    $pids = array_values(array_unique(array_column($log, 0)));
    $last = end($pids);

    return [count($pids), array_values(array_column(array_filter($log, static fn (array $entry): bool => $entry[0] === $last), 1))];
}

beforeEach(function (): void {
    $this->state = sys_get_temp_dir().'/bp-supervisor-'.bin2hex(random_bytes(4));
    mkdir($this->state);
    $this->script = dirname(__DIR__, 2).'/bin/boost-pipeline-mcp';
});

afterEach(function (): void {
    new Process(['rm', '-rf', $this->state])->run();
});

it('forwards a request and its answer, and marks the server as supervised with the protocol version', function (): void {
    $session = new SupervisorSession($this->script, $this->state);
    $session->call(1, 'open_run');

    $answer = $session->receive();

    expect($answer['id'])->toBe(1)
        ->and(textOf($answer))->toBe('open_run answered by the first server (supervised=1)')
        ->and($session->close())->toBe(0);
});

it('starts a new server on exit 75 and sends it the request that had no answer, with no initialize', function (): void {
    file_put_contents($this->state.'/exit-open_run', '75');
    $session = new SupervisorSession($this->script, $this->state);
    $session->send(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => []]);
    $session->receive();
    $session->call(1, 'open_run');

    $answer = $session->receive();

    [$servers, $second] = lastServerLines($this->state);

    expect($answer['id'])->toBe(1)
        ->and(textOf($answer))->toBe('open_run answered by the restarted server (supervised=1)')
        ->and($servers)->toBe(2)
        ->and($second)->toHaveCount(1)
        ->and($second[0])->toContain('"open_run"')
        ->and($session->close())->toBe(0);
});

it('resends every unanswered request in order, to the new server only', function (): void {
    file_put_contents($this->state.'/exit-first', '75');
    $session = new SupervisorSession($this->script, $this->state);
    $session->call(1, 'first');
    $session->call(2, 'second');

    $answers = [$session->receive(), $session->receive()];
    [, $second] = lastServerLines($this->state);

    expect(array_column($answers, 'id'))->toBe([1, 2])
        ->and(textOf($answers[0]))->toContain('restarted')
        ->and(textOf($answers[1]))->toContain('restarted')
        ->and($second)->toHaveCount(2)
        ->and($second[0])->toContain('"first"')
        ->and($second[1])->toContain('"second"');
});

it('drops the half line a dying server wrote, so the client gets one whole answer', function (): void {
    $session = new SupervisorSession($this->script, $this->state);
    $session->call(1, 'partial');

    $answer = $session->receive();

    expect($answer['id'])->toBe(1)
        ->and(textOf($answer))->toBe('partial answered by the restarted server (supervised=1)');
});

it('retries a replacement that fails at boot, then answers', function (): void {
    $session = new SupervisorSession($this->script, $this->state);
    $session->call(0, 'warmup');
    $session->receive();

    file_put_contents($this->state.'/exit-open_run', '75');
    file_put_contents($this->state.'/boot-exit', "1\n");
    $started = microtime(true);
    $session->call(1, 'open_run');
    $answer = $session->receive();

    expect($answer['id'])->toBe(1)
        ->and(textOf($answer))->toContain('restarted')
        ->and(microtime(true) - $started)->toBeGreaterThan(0.09);
});

it('keeps a client notification until a replacement answers, so a replacement that fails at boot does not lose it', function (): void {
    $session = new SupervisorSession($this->script, $this->state);
    $session->call(0, 'warmup');
    $session->receive();

    file_put_contents($this->state.'/exit-open_run', '75');
    file_put_contents($this->state.'/boot-exit', "1\n1\n");
    $session->call(1, 'open_run');
    // Both replacements fail at boot. The notification reaches the second one, which never reads it.
    $session->pause(0.15);
    $session->send(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 1]]);
    $session->receive();

    [, $last] = lastServerLines($this->state, waitFor: 'cancelled');

    expect($last)->toHaveCount(2)
        ->and($last[0])->toContain('"open_run"')
        ->and($last[1])->toContain('cancelled');
});

it('sends a notification that the restarting server did not read to the new server, after the resent request', function (): void {
    $session = new SupervisorSession($this->script, $this->state);
    $session->call(0, 'warmup');
    $session->receive();

    // The old server reads open_run and exits 75 before it reads the next line.
    file_put_contents($this->state.'/exit-open_run', '75');
    $session->call(1, 'open_run');
    $session->send(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 1]]);
    $session->receive();

    [$servers, $last] = lastServerLines($this->state, waitFor: 'cancelled');

    expect($servers)->toBe(2)
        ->and($last)->toHaveCount(2)
        ->and($last[0])->toContain('"open_run"')
        ->and($last[1])->toContain('cancelled');
});

it('answers with a wait message, not a reconnect, once every retry failed, and starts again on the next request', function (): void {
    $session = new SupervisorSession($this->script, $this->state);
    $session->call(0, 'warmup');
    $session->receive();

    file_put_contents($this->state.'/exit-open_run', '75');
    file_put_contents($this->state.'/boot-exit', "1\n1\n1\n");
    $session->call(1, 'open_run');
    $failed = $session->receive();

    expect($failed['id'])->toBe(1)
        ->and(errorOf($failed))->toContain('Wait, then call open_run again')
        ->and(errorOf($failed))->toContain('No reconnect is needed');

    $session->call(2, 'open_run');
    $answer = $session->receive();

    expect($answer['id'])->toBe(2)
        ->and(textOf($answer))->toContain('restarted');
});

it('exits with the server code when an answering server dies for another reason', function (): void {
    $session = new SupervisorSession($this->script, $this->state);
    $session->call(0, 'warmup');
    $session->receive();

    file_put_contents($this->state.'/exit-crash', '3');
    $session->call(1, 'crash');

    expect($session->waitForExit())->toBe(3);
});

it('delivers a large answer whole', function (): void {
    $session = new SupervisorSession($this->script, $this->state);
    $session->call(1, 'big');

    expect(textOf($session->receive()))->toHaveLength(2_000_000);
});

it('runs from a directory with no vendor/ in reach, so it loads no autoloader', function (): void {
    $alone = $this->state.'/alone';
    mkdir($alone);
    copy($this->script, $alone.'/boost-pipeline-mcp');

    $session = new SupervisorSession($alone.'/boost-pipeline-mcp', $this->state);
    $session->call(1, 'open_run');

    expect($session->receive()['id'])->toBe(1);
});

it('refuses to start without a server command', function (): void {
    $process = new Process([PHP_BINARY, $this->script]);
    $process->run();

    expect($process->getExitCode())->toBe(64)
        ->and($process->getErrorOutput())->toContain('Pass the server command');
});

/** Do what a finished composer install does to an application base path: acme/tool at $version in the lock and in installed.json. */
function installEverywhere(string $base, string $version): void
{
    $package = ['name' => 'acme/tool', 'version' => $version, 'autoload' => ['psr-4' => ['Acme\\Tool\\' => 'src/']]];
    file_put_contents($base.'/composer.lock', (string) json_encode(['packages' => [$package], 'packages-dev' => []]));
    file_put_contents($base.'/vendor/composer/installed.json', (string) json_encode(['packages' => [$package], 'dev' => true]));
}

it('restarts the real server after a vendor change, and the client gets an answer on the same connection', function (): void {
    $base = $this->state.'/app';
    new Process(['cp', '-R', dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel', $base])->mustRun();
    // A local copy links vendor/ to this repository's, and a CI copy has none.
    // Either way, give the copy a vendor/ this test may write into.
    new Process(['rm', '-rf', $base.'/vendor'])->mustRun();
    mkdir($base.'/vendor/composer', recursive: true);
    @mkdir($base.'/.config');
    file_put_contents($base.'/.config/pipeline.php', "<?php\n\nuse SanderMuller\\BoostPipeline\\Config\\Pipeline;\nuse SanderMuller\\BoostPipeline\\Phases\\Defaults\\Tests;\nuse SanderMuller\\BoostPipeline\\Phases\\Steps;\nuse SanderMuller\\BoostPipeline\\Steps\\Shell;\n\nreturn Pipeline::configure()->withSteps(function (Steps \$steps): void {\n\$steps->in(Tests::class)->append(Shell::run('true', id: 'probe'));\n});\n");
    installEverywhere($base, '1.0.0');

    $session = new SupervisorSession($this->script, $this->state, server: [PHP_BINARY, __DIR__.'/../Fixtures/testbench-mcp-server.php', $base]);
    $session->call(1, 'open_run');

    $first = $session->receive(30.0);

    expect($first['id'] ?? null)->toBe(1)
        ->and(data_get($first, 'result.isError'))->toBeFalse();

    installEverywhere($base, '1.1.0');
    $session->call(2, 'open_run');
    $notification = $session->receive(30.0);
    $answer = $session->receive(30.0);

    expect($notification['method'] ?? null)->toBe('notifications/tools/list_changed')
        ->and($answer['id'] ?? null)->toBe(2)
        ->and(data_get($answer, 'result.isError'))->toBeFalse()
        ->and(textOf($answer))->toContain('restarted after a vendor/ change')
        ->and($session->close(30.0))->toBe(0);
});
