<?php

/**
 * Laravel-style middleware that gates an agent's outbound "open this URL" tool.
 *
 * Route the agent's browse or fetch tool through an internal endpoint such as
 * POST /agent/tools/browse {"url": "...", "method": "GET"} and attach this
 * middleware. Denied or flagged targets never reach the fetcher; the model gets
 * a structured refusal plus the domain's read-safe pages to continue with.
 *
 * In a Laravel app: move the class to app/Http/Middleware, bind Client in a
 * service provider, and register the middleware on the tool route.
 * Run this file directly for a demo with a stub request:
 *
 *   AIAGENTALLOWLIST_API_KEY=... php examples/laravel-middleware.php https://example.com/account/login POST
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use AIAgentAllowlist\Client;
use AIAgentAllowlist\Exception\AIAgentAllowlistException;
use AIAgentAllowlist\Exception\RateLimitException;

final class EnforceAgentAllowList
{
    /** @var Client */
    private $client;

    /** @var bool treat "flag" as allowed (true) or send it to review (false) */
    private $allowFlagged;

    public function __construct(Client $client, bool $allowFlagged = false)
    {
        $this->client = $client;
        $this->allowFlagged = $allowFlagged;
    }

    /**
     * @param object   $request anything with input(string $key, $default = null)
     * @param \Closure $next    the next handler in the pipeline
     * @return mixed
     */
    public function handle($request, \Closure $next)
    {
        $url = (string) $request->input('url', '');
        $method = strtoupper((string) $request->input('method', 'GET'));

        try {
            $v = $this->client->check($url, $method);
        } catch (RateLimitException $e) {
            return $this->refuse(503, 'policy service busy, retry shortly', $url, $method);
        } catch (AIAgentAllowlistException $e) {
            // Fail closed: no verdict, no navigation.
            return $this->refuse(503, 'policy lookup failed: ' . $e->getMessage(), $url, $method);
        }

        if ($v->allowed() || ($v->flagged() && $this->allowFlagged)) {
            return $next($request);
        }

        $safe = array_diff_key($v->pageTypes(), array_flip(Client::DEFAULT_DENY_TYPES));
        return $this->refuse(403, sprintf(
            '%s %s is %s by layer %s (%s)',
            $method,
            $url,
            $v->verdict() ?? 'unknown',
            $v->matchedLayer() ?? 'n/a',
            $v->matchedId() ?? 'n/a'
        ), $url, $method, $safe);
    }

    /**
     * @param array<string, string> $alternatives
     * @return array<string, mixed>
     */
    private function refuse(int $status, string $reason, string $url, string $method, array $alternatives = []): array
    {
        // In Laravel return response()->json($payload, $status) instead.
        return [
            'status' => $status,
            'tool_error' => $reason,
            'url' => $url,
            'method' => $method,
            'read_safe_pages' => $alternatives,
        ];
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
    $request = new class ($argv[1] ?? 'https://example.com/pricing', $argv[2] ?? 'GET') {
        /** @var array<string, string> */
        private $data;

        public function __construct(string $url, string $method)
        {
            $this->data = ['url' => $url, 'method' => $method];
        }

        /**
         * @param mixed $default
         * @return mixed
         */
        public function input(string $key, $default = null)
        {
            return $this->data[$key] ?? $default;
        }
    };

    $middleware = new EnforceAgentAllowList(new Client(aal_api_key()));
    $result = $middleware->handle($request, static function ($req): array {
        return ['status' => 200, 'fetched' => $req->input('url')];
    });
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
}
