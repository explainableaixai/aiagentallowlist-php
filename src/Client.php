<?php

declare(strict_types=1);

namespace AIAgentAllowlist;

use AIAgentAllowlist\Exception\AIAgentAllowlistException;
use AIAgentAllowlist\Exception\AuthenticationException;
use AIAgentAllowlist\Exception\BadRequestException;
use AIAgentAllowlist\Exception\QuotaException;
use AIAgentAllowlist\Exception\RateLimitException;

/**
 * AI Agent Allow List API client for PHP.
 *
 * Wraps the endpoint documented at https://www.aiagentallowlist.com/api-docs.php:
 *
 *   GET https://www.aiagentallowlist.com/api/check?url=<full URL or bare domain>[&method=POST]
 *
 * Each call returns one JSON object: whether the domain is in the 40M+ domain
 * database, its language, the verified page-type map (up to 28 types), IAB and
 * web-filtering categories, Open PageRank and, for a full URL, a verdict
 * (allow, deny or flag) with the layer that produced it.
 *
 * Requires ext-curl and ext-json only.
 */
class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://www.aiagentallowlist.com/api';
    public const DEFAULT_TIMEOUT = 30;
    public const USER_AGENT = 'aiagentallowlist-php/1.0.0 (+https://www.aiagentallowlist.com)';
    public const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    public const DEFAULT_DENY_TYPES = [
        'login', 'signup', 'checkout', 'cart', 'upload', 'post_create',
        'comment', 'subscribe', 'password_reset',
    ];

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    /** @var int */
    private $timeout;

    /** @var int */
    private $maxRetries;

    /**
     * Transport callable: fn(string $url, array $headers, int $timeout): array{status:int, body:string}
     *
     * @var callable
     */
    private $transport;

    /**
     * Sleep callable: fn(int $milliseconds): void
     *
     * @var callable
     */
    private $sleeper;

    /**
     * @param string $apiKey  key from the account area, sent as X-API-Key
     * @param array{base_url?:string, timeout?:int, max_retries?:int, transport?:callable, sleeper?:callable} $options
     *        base_url    default https://www.aiagentallowlist.com/api
     *        timeout     per-request timeout in seconds, default 30
     *        max_retries retries on HTTP 429, default 2
     *        transport   replaces the curl transport (tests, custom HTTP stacks)
     *        sleeper     replaces usleep() for retry and batch pauses
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim((string) ($options['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        $this->timeout = (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $this->maxRetries = (int) ($options['max_retries'] ?? 2);
        $this->transport = $options['transport'] ?? [$this, 'curlTransport'];
        $this->sleeper = $options['sleeper'] ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Evaluate one URL (or bare domain) and return the full record with a verdict.
     *
     * @param string $method HTTP method the agent intends to use; POST, PUT, PATCH and DELETE are passed through
     */
    public function check(string $url, string $method = 'GET'): Verdict
    {
        $params = ['url' => $url];
        $m = strtoupper(trim($method));
        if ($m !== '' && $m !== 'GET') {
            $params['method'] = $m;
        }
        return new Verdict($this->get($params));
    }

    /** True only when the verdict is "allow". "flag" and "deny" both return false. */
    public function isAllowed(string $url, string $method = 'GET'): bool
    {
        return $this->check($url, $method)->allowed();
    }

    /**
     * The domain's verified page-type map: ["login" => "https://...", "pricing" => "https://...", ...].
     *
     * @return array<string, string>
     */
    public function pageTypes(string $domain): array
    {
        return $this->check($domain)->pageTypes();
    }

    /**
     * Verified URLs on the domain that a browsing agent should not open by default.
     *
     * @param string[] $types
     * @return string[]
     */
    public function denyList(string $domain, array $types = self::DEFAULT_DENY_TYPES): array
    {
        $pt = $this->pageTypes($domain);
        $out = [];
        foreach ($types as $t) {
            if (isset($pt[$t])) {
                $out[] = $pt[$t];
            }
        }
        return $out;
    }

    /**
     * Sequentially evaluate many URLs; one lookup is charged per URL.
     *
     * Yields url => Verdict. A short pause between calls keeps batch jobs under the rate limit.
     *
     * @param iterable<string> $urls
     * @return \Generator<string, Verdict>
     */
    public function checkMany(iterable $urls, string $method = 'GET', int $pauseMs = 100): \Generator
    {
        $first = true;
        foreach ($urls as $u) {
            if (!$first && $pauseMs > 0) {
                ($this->sleeper)($pauseMs);
            }
            $first = false;
            yield (string) $u => $this->check((string) $u, $method);
        }
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function get(array $params): array
    {
        $url = $this->baseUrl . '/check?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $headers = [
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
            'X-API-Key: ' . $this->apiKey,
        ];
        $resp = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $resp = ($this->transport)($url, $headers, $this->timeout);
            if ((int) $resp['status'] === 429 && $attempt < $this->maxRetries) {
                ($this->sleeper)(1500 * ($attempt + 1));
                continue;
            }
            break;
        }
        return self::handle((int) $resp['status'], (string) $resp['body']);
    }

    /**
     * Map an HTTP status and body to decoded data or a typed exception.
     *
     * @return array<string, mixed>
     */
    public static function handle(int $status, string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $data = ['error' => substr($body, 0, 200)];
        }
        if ($status === 200) {
            return $data;
        }
        $msg = (string) ($data['error'] ?? $data['message'] ?? substr($body, 0, 200));
        switch ($status) {
            case 400:
                throw new BadRequestException($msg, 400, $data);
            case 401:
                throw new AuthenticationException($msg, 401, $data);
            case 403:
                throw new QuotaException($msg, 403, $data);
            case 429:
                throw new RateLimitException($msg, 429, $data);
            default:
                throw new AIAgentAllowlistException('HTTP ' . $status . ': ' . $msg, $status, $data);
        }
    }

    /**
     * Default transport based on ext-curl.
     *
     * @param string[] $headers
     * @return array{status:int, body:string}
     */
    public function curlTransport(string $url, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new AIAgentAllowlistException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new AIAgentAllowlistException('Transport error: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body];
    }
}
