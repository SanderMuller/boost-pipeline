<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request that did not come from this machine.
 *
 * The page's other two gates describe the application, not the requester: a
 * config flag says the page is wanted, and `APP_ENV=local` says which
 * environment this is. Neither stops a colleague on the same network, and a
 * local server routinely listens on a LAN address or behind a tunnel. The routes
 * serve raw command output — paths, source, environment values, test data — so
 * the requester is checked too.
 *
 * A proxy header is deliberately not consulted. `X-Forwarded-For` is set by
 * whatever is in front, which on a developer machine is usually nothing at all,
 * and trusting it here would let a header decide whether the page is open.
 */
final class LoopbackOnly
{
    /**
     * Addresses that mean "this machine", including the IPv4-mapped IPv6 form
     * a dual-stack server reports for a v4 loopback connection.
     */
    private const array LOOPBACK = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $remoteAddress = $request->server->get('REMOTE_ADDR');

        if (! is_string($remoteAddress) || ! $this->isLoopback($remoteAddress)) {
            abort(403, 'The pipeline page is reachable from this machine only.');
        }

        return $next($request);
    }

    private function isLoopback(string $ip): bool
    {
        // The whole 127.0.0.0/8 block is loopback, not just 127.0.0.1.
        return in_array($ip, self::LOOPBACK, true) || str_starts_with($ip, '127.');
    }
}
