<?php

/*
 * A stand-in MCP server for the supervisor tests. Plain PHP, newline JSON.
 *
 * State lives in the directory given as the first argument:
 * - boot-exit: one exit code per line. Each start takes the first line and exits with it before reading anything.
 * - exit-<tool>: a call to <tool> exits with the code in this file, once, before it answers.
 * - received.log: every line this server read, prefixed with its pid.
 *
 * The first call to "partial" writes half an answer and exits 75. A call to "big" answers with 2 MB of text.
 */

declare(strict_types=1);

$arguments = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$state = is_string($arguments[1] ?? null) ? $arguments[1] : sys_get_temp_dir();

$boot = $state.'/boot-exit';

if (is_file($boot)) {
    $codes = array_values(array_filter(explode("\n", (string) file_get_contents($boot)), static fn (string $code): bool => $code !== ''));

    if ($codes !== []) {
        $code = (int) array_shift($codes);
        file_put_contents($boot, implode("\n", $codes));

        exit($code);
    }
}

/** @param  array<mixed>  $message */
function data_name(array $message): ?string
{
    $params = $message['params'] ?? null;
    $name = is_array($params) ? $params['name'] ?? null : null;

    return is_string($name) ? $name : null;
}

$pid = getmypid();
$restarted = getenv('BOOST_PIPELINE_RESTARTED') === '1' ? 'restarted' : 'first';
$supervised = (string) getenv('BOOST_PIPELINE_SUPERVISED');

while (($line = fgets(STDIN)) !== false) {
    file_put_contents($state.'/received.log', $pid.' '.$line, FILE_APPEND);
    $message = json_decode($line, true);

    if (! is_array($message) || ! array_key_exists('id', $message) || ! isset($message['method'])) {
        continue;
    }

    $name = data_name($message);
    $tool = $name ?? (is_string($message['method']) ? $message['method'] : '');
    $exit = $state.'/exit-'.$tool;

    if (is_file($exit)) {
        $code = (int) file_get_contents($exit);
        unlink($exit);

        exit($code);
    }

    if ($tool === 'partial' && ! is_file($state.'/partial-done')) {
        touch($state.'/partial-done');
        fwrite(STDOUT, '{"jsonrpc":"2.0","id":');
        fflush(STDOUT);

        exit(75);
    }

    $text = $tool === 'big' ? str_repeat('x', 2_000_000) : sprintf('%s answered by the %s server (supervised=%s)', $tool, $restarted, $supervised);

    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['content' => [['type' => 'text', 'text' => $text]]]])."\n");
    fflush(STDOUT);
}
