<?php

declare(strict_types=1);

namespace Packstub\SessionReplay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Packstub\SessionReplay\Support\AllowedOrigins;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS for a frontend on another origin (ingest.origins), on the upload routes ("ingest") and the token route
 * ("token"). Outermost on those routes, so a refusal from further in (429, 503) still carries the headers and the
 * recorder can read its Retry-After.
 *
 * Only a listed origin (or the app's own) is ever named in Access-Control-Allow-Origin, never "*" and never one that
 * was not listed. The upload routes never allow credentials: they trust the signed token in the body and nothing the
 * browser would attach. The token route allows them, since it runs behind the app's own sign-in; it also refuses a
 * request from any other origin outright, before the app's middleware runs, one a browser sends without an Origin
 * header for a tag on another page included (Sec-Fetch-Site).
 *
 * @internal Wired by the package; not part of the public API.
 */
final class HandleReplayCors
{
    /** @var array<string, array{method: string, headers: list<string>, credentials: bool}> */
    private const ROUTES = [
        'ingest' => ['method' => 'POST', 'headers' => ['content-type'], 'credentials' => false],
        'token' => ['method' => 'GET', 'headers' => ['accept', 'authorization', 'content-type', 'x-requested-with', 'x-xsrf-token'], 'credentials' => true],
    ];

    private const MAX_AGE = 600;

    public function __construct(private readonly AllowedOrigins $origins) {}

    public function handle(Request $request, Closure $next, string $route = 'ingest'): Response
    {
        $rules = self::ROUTES[$route] ?? throw new InvalidArgumentException('Unknown Session Replay CORS route ['.$route.'].');
        $origin = $request->headers->get('Origin');
        // Normalised, so the header names exactly the origin the browser sent and nothing else.
        $permitted = $this->origins->match($origin, $request);

        if ($request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method')) {
            return $this->preflight($request, $rules, $permitted);
        }

        // The token is the signed-in person's: a page of another origin gets nothing, not even a run of the app's middleware.
        if ($route === 'token' && $permitted === null && ($origin !== null || $this->fromAnotherPage($request))) {
            return $this->vary(response()->json(['record' => false, 'message' => 'This origin may not fetch a recording token.'], 403));
        }

        $response = $next($request);

        if ($route === 'token') {
            // JSON and nothing else, whatever tag asked for it.
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        if (! $this->origins->any()) {
            return $response;
        }

        $this->vary($response);

        if ($permitted !== null && $this->origins->crossOrigin($request)) {
            $this->allow($response, $permitted, $rules);
            // The recorder waits as long as a 429 or 503 asks; a page of another origin can only read that when told.
            $response->headers->set('Access-Control-Expose-Headers', 'Retry-After');
        }

        return $response;
    }

    /**
     * The browser's question before a request it may not send on its own: answered 204 in any case, with the
     * permissions only for a permitted origin. The headers allowed are the ones asked for that the route takes.
     *
     * @param  array{method: string, headers: list<string>, credentials: bool}  $rules
     */
    private function preflight(Request $request, array $rules, ?string $origin): Response
    {
        $response = $this->vary(response()->noContent());

        if ($origin === null) {
            return $response;
        }

        $this->allow($response, $origin, $rules);
        $response->headers->set('Access-Control-Allow-Methods', $rules['method']);
        $response->headers->set('Access-Control-Max-Age', (string) self::MAX_AGE);

        $asked = array_map(fn (string $header): string => strtolower(trim($header)), explode(',', (string) $request->headers->get('Access-Control-Request-Headers', '')));
        $headers = array_values(array_intersect($rules['headers'], $asked));

        if ($headers !== []) {
            $response->headers->set('Access-Control-Allow-Headers', implode(', ', $headers));
        }

        return $response;
    }

    /**
     * A request without an Origin header that the browser itself says another page made: an <img>, a <script> or a
     * link there (Sec-Fetch-Site "cross-site" or "same-site"; a fetch() that could read the answer always sends
     * Origin). It could read nothing, but would run the app's sign-in and count against the person's token limit.
     * A browser without the header, and anything that is no browser, is not held back by this.
     */
    private function fromAnotherPage(Request $request): bool
    {
        return in_array(strtolower((string) $request->headers->get('Sec-Fetch-Site')), ['cross-site', 'same-site'], true);
    }

    /** @param array{method: string, headers: list<string>, credentials: bool} $rules */
    private function allow(Response $response, string $origin, array $rules): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);

        if ($rules['credentials']) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        } else {
            $response->headers->remove('Access-Control-Allow-Credentials');
        }
    }

    /** The answer depends on the Origin header: no cache may hand one origin's answer to another. */
    private function vary(Response $response): Response
    {
        $response->setVary('Origin', false);

        return $response;
    }
}
