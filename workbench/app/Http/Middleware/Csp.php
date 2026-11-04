<?php

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/** A strict CSP with a nonce, to see what the recorder and the player need. */
class Csp
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);
        $nonce = Vite::cspNonce();
        // ?csp=inline: style-src with 'unsafe-inline' and no nonce; ?csp=both: nonce and 'unsafe-inline' together.
        $styleSrc = match ($request->query('csp')) {
            'inline' => "'self' 'unsafe-inline'",
            'both' => "'self' 'nonce-{$nonce}' 'unsafe-inline'",
            default => "'self' 'nonce-{$nonce}'",
        };

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src {$styleSrc}",
            "connect-src 'self'",
            "img-src 'self' data:",
            "frame-src 'self'",
            "worker-src 'none'",
        ]));

        return $response;
    }
}
