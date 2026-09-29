# aiagentallowlist for PHP

PHP client for the [AI agent allow list](https://www.aiagentallowlist.com) API from Alpha Quantum. Give it a URL and the HTTP method your agent is about to use, and it returns `allow`, `deny` or `flag`, together with the layer that decided and the verified page-type map of the domain.

It targets PHP 7.4 and newer, needs only `ext-curl` and `ext-json`, and installs through Composer from Packagist as `aiagentallowlist/aiagentallowlist`.

```bash
composer require aiagentallowlist/aiagentallowlist
```

## Contents

- [What the API returns](#what-the-api-returns)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Method reference](#method-reference)
- [The Verdict object](#the-verdict-object)
- [Error handling](#error-handling)
- [Example 1: Laravel-style middleware for an agent tool route](#example-1-laravel-style-middleware-for-an-agent-tool-route)
- [Example 2: batch audit over a URL list](#example-2-batch-audit-over-a-url-list)
- [Example 3: command-line lookups](#example-3-command-line-lookups)
- [Testing without network access](#testing-without-network-access)
- [Why put a URL policy in front of a PHP agent](#why-put-a-url-policy-in-front-of-a-php-agent)
- [FAQ](#faq)
- [Related packages](#related-packages)
- [Links](#links)

## What the API returns

Every lookup hits one endpoint, `GET https://www.aiagentallowlist.com/api/check`, with the key in the `X-API-Key` header. You pass either a full URL or a bare domain, plus an optional `method`.

The data behind it covers 40 million+ domains, with up to 28 verified page types per domain, built by analyzing over 10 billion links. A decision comes from three layers, checked in order:

| Layer | `matched.layer` value | What it holds |
|---|---|---|
| High-Value Host List | `high_value_hosts` | Hosts where any agent action carries outsized consequence |
| Page-type database | `page_type_db` | The exact login, signup, checkout, upload and other URLs of each domain |
| Rules | `rules` | About 40 method-aware URL-pattern rules for paths not yet in the database |
| Default | `default` | Reads pass, writes (POST, PUT, PATCH, DELETE) are denied |

A single JSON record comes back for every call. The fields are:

| Field | Type | Meaning |
|---|---|---|
| `found` | bool | The domain is in the page-type database |
| `verdict` | string | `allow`, `deny` or `flag` |
| `verdict_scope` | string | `url` for a full URL, `domain_root` for a bare domain |
| `matched` | object | `{layer, id, note}` explaining the decision |
| `page_types` | object | `{type: verified_url}`, up to 28 entries |
| `language` | string | Primary language of the site |
| `iab_category` | string or array | IAB content category |
| `filtering_categories` | array | Web filtering categories |
| `open_page_rank` | number | Open PageRank score |
| `global_rank` | number | Global rank of the domain |
| `remaining_lookups` | int | Lookups left on your plan |

## Installation

Requirements:

- PHP 7.4, 8.0, 8.1, 8.2, 8.3 or later
- `ext-curl` and `ext-json` (both ship with nearly every PHP build)
- An API key from your account at aiagentallowlist.com

Install with Composer:

```bash
composer require aiagentallowlist/aiagentallowlist
```

Then load Composer's autoloader as usual. All classes live under the `AIAgentAllowlist\` namespace, mapped to `src/` through PSR-4.

```php
require __DIR__ . '/vendor/autoload.php';

use AIAgentAllowlist\Client;
```

Keep the key out of source control. Read it from an environment variable, a Laravel `config()` entry, a Symfony parameter or your secrets manager.

## Quick start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use AIAgentAllowlist\Client;

$client = new Client(getenv('AIAGENTALLOWLIST_API_KEY'));

// 1. A read the agent wants to do
$v = $client->check('https://example.com/pricing');
echo $v->verdict();            // "allow"

// 2. A write on a sign-in form
$v = $client->check('https://example.com/account/login', 'POST');
echo $v->verdict();            // "deny"
echo $v->matchedLayer();       // "page_type_db"
echo $v->matchedId();          // "login"

// 3. One boolean for a navigation hook
if (!$client->isAllowed('https://example.com/upload', 'PUT')) {
    throw new RuntimeException('Agent may not upload here');
}

// 4. Everything the database knows about the domain
foreach ($client->pageTypes('example.com') as $type => $url) {
    echo "$type => $url\n";
}
```

`isAllowed()` returns `true` only for `allow`. Both `flag` and `deny` return `false`, so a `flag` falls to whatever review path you choose rather than going through silently.

### Configuration options

The constructor takes the key and an optional array:

```php
$client = new Client($apiKey, [
    'base_url'    => 'https://www.aiagentallowlist.com/api', // override for a gateway or proxy
    'timeout'     => 30,        // seconds per request
    'max_retries' => 2,         // automatic retries on HTTP 429
    'transport'   => null,      // callable replacing curl, see "Testing"
    'sleeper'     => null,      // callable replacing usleep(), see "Testing"
]);
```

The client sends `User-Agent: aiagentallowlist-php/1.0.0 (+https://www.aiagentallowlist.com)` and `Accept: application/json` on every request.

## Method reference

| Method | Returns | Description |
|---|---|---|
| `__construct(string $apiKey, array $options = [])` | `Client` | Throws `InvalidArgumentException` on an empty key |
| `check(string $url, string $method = 'GET')` | `Verdict` | Full record for one URL or bare domain. Non-GET methods are sent as the `method` parameter |
| `isAllowed(string $url, string $method = 'GET')` | `bool` | `true` only when the verdict is `allow` |
| `pageTypes(string $domain)` | `array<string,string>` | The verified page-type map of the domain |
| `denyList(string $domain, array $types = Client::DEFAULT_DENY_TYPES)` | `string[]` | Verified URLs of write surfaces on the domain |
| `checkMany(iterable $urls, string $method = 'GET', int $pauseMs = 100)` | `Generator<string,Verdict>` | Evaluates URLs one after another, yields `url => Verdict`, pauses between calls |
| `getBaseUrl()` | `string` | The effective base URL |
| `Client::handle(int $status, string $body)` (static) | `array` | Maps a raw HTTP response to data or a typed exception |

Constants on `Client`:

| Constant | Value |
|---|---|
| `VERSION` | `1.0.0` |
| `DEFAULT_BASE_URL` | `https://www.aiagentallowlist.com/api` |
| `DEFAULT_TIMEOUT` | `30` |
| `USER_AGENT` | `aiagentallowlist-php/1.0.0 (+https://www.aiagentallowlist.com)` |
| `WRITE_METHODS` | `['POST', 'PUT', 'PATCH', 'DELETE']` |
| `DEFAULT_DENY_TYPES` | `login`, `signup`, `checkout`, `cart`, `upload`, `post_create`, `comment`, `subscribe`, `password_reset` |

Each call to `check()`, `isAllowed()`, `pageTypes()` or `denyList()` costs one lookup. `checkMany()` costs one lookup per URL.

### Passing the method

The method parameter matters. `GET https://example.com/settings` and `POST https://example.com/settings` are different actions, and the API answers them differently. The client uppercases the method, omits it for GET and forwards POST, PUT, PATCH and DELETE unchanged:

```php
$client->check('https://example.com/api/items/42', 'delete'); // sent as method=DELETE
```

## The Verdict object

`check()` returns `AIAgentAllowlist\Verdict`. It wraps the decoded JSON and adds typed accessors. It is read-only, implements `ArrayAccess` and `JsonSerializable`, and keeps every raw field.

| Accessor | Returns | Source field |
|---|---|---|
| `verdict()` | `?string` | `verdict` |
| `allowed()` / `denied()` / `flagged()` | `bool` | `verdict` |
| `found()` | `bool` | `found` |
| `verdictScope()` | `?string` | `verdict_scope` |
| `matched()` | `array` | `matched` |
| `matchedLayer()` / `matchedId()` / `matchedNote()` | `?string` | `matched.layer`, `matched.id`, `matched.note` |
| `pageTypes()` | `array<string,string>` | `page_types` |
| `language()` | `?string` | `language` |
| `iabCategory()` | `mixed` | `iab_category` |
| `filteringCategories()` | `array` | `filtering_categories` |
| `openPageRank()` / `globalRank()` | `mixed` | `open_page_rank`, `global_rank` |
| `remainingLookups()` | `?int` | `remaining_lookups` |
| `get($key, $default)` | `mixed` | any field |
| `toArray()` | `array` | the whole record |

```php
$v = $client->check('https://example.com/checkout', 'POST');

$v['page_types']['pricing'];          // array access
json_encode($v);                      // serialises the raw record
$v->get('some_future_field', 'n/a');  // new API fields need no client update
```

## Error handling

Every exception extends `AIAgentAllowlist\Exception\AIAgentAllowlistException`, which extends `RuntimeException`. Each one exposes `getStatus()` (the HTTP status, or 0 for a transport failure) and `getBody()` (the decoded error body).

| HTTP status | Exception | Typical cause | What to do |
|---|---|---|---|
| 400 | `BadRequestException` | The `url` value could not be parsed into a host | Validate input; treat as deny |
| 401 | `AuthenticationException` | Missing or unknown API key | Fix configuration; alert |
| 403 | `QuotaException` | Account not activated, or lookup quota used up | Upgrade or wait for the reset; fail closed |
| 429 | `RateLimitException` | Too many requests | Raised only after the built-in retries |
| other | `AIAgentAllowlistException` | Network failure, timeout, 5xx | Retry later; fail closed |

On a 429 the client waits 1.5 seconds, then 3 seconds, before giving up (with the default `max_retries` of 2).

```php
use AIAgentAllowlist\Exception\AIAgentAllowlistException;
use AIAgentAllowlist\Exception\AuthenticationException;
use AIAgentAllowlist\Exception\QuotaException;

function agentMayOpen(Client $client, string $url, string $method): bool
{
    try {
        return $client->isAllowed($url, $method);
    } catch (AuthenticationException | QuotaException $e) {
        error_log('allow list unavailable: ' . $e->getMessage());
        return false;               // fail closed
    } catch (AIAgentAllowlistException $e) {
        error_log(sprintf('lookup failed (HTTP %d): %s', $e->getStatus(), $e->getMessage()));
        return false;
    }
}
```

Failing closed is the right default. An agent that cannot get a verdict should not act on the web.

## Example 1: Laravel-style middleware for an agent tool route

A common PHP layout runs the model loop in a queue worker and exposes tools as internal routes, such as `POST /agent/tools/browse`. The middleware below sits on that route. Denied targets never reach the fetcher, and the model receives the domain's read-safe pages so it can continue with the task.

```php
namespace App\Http\Middleware;

use AIAgentAllowlist\Client;
use AIAgentAllowlist\Exception\AIAgentAllowlistException;
use Closure;
use Illuminate\Http\Request;

final class EnforceAgentAllowList
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function handle(Request $request, Closure $next)
    {
        $url = (string) $request->input('url');
        $method = strtoupper((string) $request->input('method', 'GET'));

        try {
            $v = $this->client->check($url, $method);
        } catch (AIAgentAllowlistException $e) {
            return response()->json(['tool_error' => 'policy lookup failed'], 503);
        }

        if ($v->allowed()) {
            return $next($request);
        }

        $readSafe = array_diff_key($v->pageTypes(), array_flip(Client::DEFAULT_DENY_TYPES));

        return response()->json([
            'tool_error' => sprintf('%s %s is %s (%s/%s)', $method, $url,
                $v->verdict(), $v->matchedLayer(), $v->matchedId()),
            'read_safe_pages' => $readSafe,
        ], 403);
    }
}
```

Bind the client once in a service provider:

```php
$this->app->singleton(\AIAgentAllowlist\Client::class, fn () =>
    new \AIAgentAllowlist\Client(config('services.aiagentallowlist.key'), ['timeout' => 10])
);
```

The same logic fits a [PSR-15](https://www.php-fig.org/psr/psr-15/) `MiddlewareInterface::process()` in Slim, Mezzio or any PSR-15 stack: read the target from the parsed body, call `check()`, return a 403 response or delegate to the handler. A runnable version with a stub request, needing no framework, is in `examples/laravel-middleware.php`.

## Example 2: batch audit over a URL list

Before you give an agent a new set of seed URLs, or after a run to review what it touched, evaluate the whole list and keep a CSV. `checkMany()` walks any iterable (an array, a generator reading a file, a database cursor) and pauses between calls to respect the rate limit.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use AIAgentAllowlist\Client;
use AIAgentAllowlist\Exception\QuotaException;

$client = new Client(getenv('AIAGENTALLOWLIST_API_KEY'));

$urls = (function (string $path) {
    foreach (new SplFileObject($path) as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            yield $line;
        }
    }
})('visited-urls.txt');

$out = fopen('policy-report.csv', 'wb');
fputcsv($out, ['url', 'verdict', 'layer', 'id', 'language']);

try {
    foreach ($client->checkMany($urls, 'GET', 200) as $url => $v) {
        fputcsv($out, [$url, $v->verdict(), $v->matchedLayer(), $v->matchedId(), $v->language()]);
    }
} catch (QuotaException $e) {
    fwrite(STDERR, "Stopped: quota exhausted\n");
}
fclose($out);
```

`examples/batch-audit.php` extends this: it accepts `METHOD URL` lines so you can audit write actions too, and prints an allow, deny and flag summary. It is a good fit for a nightly cron entry:

```cron
15 2 * * * AIAGENTALLOWLIST_API_KEY=... php /srv/agent/examples/batch-audit.php /srv/agent/visited.txt /srv/agent/reports/$(date +\%F).csv
```

## Example 3: command-line lookups

`examples/cli.php` is a small tool for operators and for shell scripts. Exit codes make it usable in CI checks: 0 for allow, 1 for deny, 3 for flag.

```bash
export AIAGENTALLOWLIST_API_KEY=your_key

php examples/cli.php check https://example.com/account/login POST
# DENY   POST https://example.com/account/login
# layer=page_type_db id=login scope=url

php examples/cli.php types example.com
# pricing          https://example.com/pricing
# documentation    https://docs.example.com
# login            https://example.com/account/login

php examples/cli.php denylist example.com
php examples/cli.php json https://example.com/blog
```

The `denylist` command prints the verified write-surface URLs for a domain. Pipe it into a proxy deny file or a browser policy to block those pages for an agent profile.

## Testing without network access

The client accepts a `transport` callable with the signature `fn(string $url, array $headers, int $timeout): array{status:int, body:string}`. Swap it in your own tests to return fixed responses, and pass a `sleeper` so retry pauses cost nothing:

```php
$client = new Client('test-key', [
    'transport' => fn ($url, $headers, $timeout) => [
        'status' => 200,
        'body'   => json_encode(['verdict' => 'deny', 'matched' => ['layer' => 'rules', 'id' => 'r12']]),
    ],
    'sleeper' => fn (int $ms) => null,
]);

assert($client->check('https://example.com/x', 'POST')->denied());
```

The package's own suite works the same way. Run it with PHPUnit (`vendor/bin/phpunit tests`) or with the bundled runner, which needs no dependencies:

```bash
php tests/run.php
```

The same hook lets you route calls through an existing Guzzle or Symfony HttpClient instance, or through a corporate egress proxy.

## Why put a URL policy in front of a PHP agent

PHP runs a large share of the web's CMS, commerce and SaaS back ends, and more of those back ends now host agents. Support bots follow links, content pipelines research topics, and procurement assistants compare vendor pages. Each of them eventually proposes a URL to open.

Three things make a dedicated per-URL policy worth the extra call:

- **Domains are too coarse.** A single domain carries its documentation and pricing alongside its sign-in form, token settings and upload page. Blocking the domain loses the research value, allowing it opens the forms.
- **Methods change the risk.** Reading a page is rarely harmful. Posting to it can create accounts, spend money, publish content or change settings. The verdict here is computed per method.
- **Prompt injection targets actions.** The [OWASP Top 10 for LLM Applications](https://owasp.org/www-project-top-10-for-large-language-model-applications/) lists excessive agency and prompt injection among the leading risks, and [MITRE ATLAS](https://atlas.mitre.org/) catalogues techniques that steer models toward unintended actions. A policy enforced in your own code, outside the model, holds regardless of what the page text says.

This lines up with the Govern and Manage functions of the [NIST AI Risk Management Framework](https://www.nist.gov/itl/ai-risk-management-framework): a documented, testable control on what an AI system may do, with logs you can show an auditor. Every verdict names the layer and rule that produced it, which gives you that paper trail.

The data side is what makes "read freely, never write" practical. Guessing that `/login` is a login page misses `/account/sign-in`, `/auth`, `id.example.com` and localized paths. The page-type database stores the URL each site itself links to, so the policy matches the real form. For the full list of page types and coverage statistics, the published [open dataset of AI agent page types](https://www.aiagentallowlist.com/ai-agent-page-types-dataset.php) documents the schema.

## FAQ

### What is an AI agent allow list?

It is a policy layer that decides, per URL and per HTTP method, whether a web-browsing AI agent may open a page. The [allow list for AI agents](https://www.aiagentallowlist.com) at aiagentallowlist.com combines a High-Value Host List, a page-type database covering 40 million+ domains and about 40 method-aware rules, and denies writes by default.

### How do I block an AI agent from submitting login or checkout forms in PHP?

Call `$client->check($url, $method)` before every navigation or HTTP request the agent makes, and refuse anything that is not `allow`. Login, signup, password reset, cart, checkout, subscribe, upload, post and comment pages resolve to `deny` for write methods through the page-type layer.

### Does the package work with Laravel, Symfony, Slim or WordPress?

Yes. It has no framework dependency. Bind `Client` as a singleton in a Laravel service provider or Symfony service definition, wrap it in PSR-15 middleware for Slim or Mezzio, or call it inside a WordPress plugin before `wp_remote_get()`.

### What does a "flag" verdict mean and how should I treat it?

`flag` marks a URL that is neither clearly safe nor clearly a write surface. Common handling is to route it to human review, allow it only for GET, or allow it for trusted agent profiles. `isAllowed()` returns `false` for `flag`, so it never passes by accident.

### How many lookups does a batch job use?

One per URL. Use `remaining_lookups` from any response, via `$v->remainingLookups()`, to track usage, and catch `QuotaException` to stop cleanly when the plan limit is reached. Caching verdicts per URL and method for a few hours cuts repeat calls.

### Can I check a bare domain instead of a full URL?

Yes. Pass `example.com` and the response has `verdict_scope` set to `domain_root`, plus the full `page_types` map. `pageTypes()` and `denyList()` use this form.

### Which URL formats are accepted?

Absolute URLs with a scheme, as defined in [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986), and bare hostnames. The client encodes the value for the query string, so paths with spaces or query strings are safe to pass. An input the server cannot parse into a host raises `BadRequestException`.

### Is there a Node.js or Python version?

Yes. The same API has clients on npm and PyPI under the name `aiagentallowlist`, listed below. All three follow the same method names and error classes, adapted to each language's conventions.

## Related packages

The allow list governs where your agents go. Alpha Quantum also publishes data on where people go. The [AI tool classification database](https://www.aitoolsblocklist.com) covers 20,000+ AI-tool domains in 18 functional categories, refreshed daily, and is available as a [generative AI blocklist feed](https://www.aitoolsblocklist.com) for firewalls, DNS resolvers and proxies. To see which of those tools are already reached from a network, a [shadow AI audit from a DNS export](https://www.shadowaitools.com) produces a dated [shadow AI inventory](https://www.shadowaitools.com) with vendor training verdicts and a PDF evidence pack.

- npm: [aiagentallowlist](https://www.npmjs.com/package/aiagentallowlist), [aiblocklist](https://www.npmjs.com/package/aiblocklist), [aitoolsblocklist](https://www.npmjs.com/package/aitoolsblocklist), [shadowaitools](https://www.npmjs.com/package/shadowaitools), [phishingdetectionapi](https://www.npmjs.com/package/phishingdetectionapi), [webfilteringdatabase](https://www.npmjs.com/package/webfilteringdatabase), [websitecategorization](https://www.npmjs.com/package/websitecategorization), [cipawebfiltering](https://www.npmjs.com/package/cipawebfiltering)
- PyPI: [aiagentallowlist](https://pypi.org/project/aiagentallowlist/), [aiblocklist](https://pypi.org/project/aiblocklist/), [aitoolsblocklist](https://pypi.org/project/aitoolsblocklist/), [shadowaitools](https://pypi.org/project/shadowaitools/), [phishingdetectionapi](https://pypi.org/project/phishingdetectionapi/), [websiteclassificationapi](https://pypi.org/project/websiteclassificationapi/), [cipawebfiltering](https://pypi.org/project/cipawebfiltering/)
- Products: [AI Agent Allow List](https://www.aiagentallowlist.com), [AI Tools Blocklist](https://www.aitoolsblocklist.com), [Shadow AI Tools](https://www.shadowaitools.com), [Website Categorization API](https://www.websitecategorizationapi.com), [Web Filtering Database](https://www.webfilteringdatabase.com), [Phishing Detection API](https://www.phishingdetectionapi.com), [CIPA Web Filtering](https://www.cipawebfiltering.com), [PII Detection API](https://www.piidetectionapi.com)

## Links

- Product: [per-URL policy for browsing agents](https://www.aiagentallowlist.com)
- API documentation: [aiagentallowlist.com/api-docs.php](https://www.aiagentallowlist.com/api-docs.php)
- Support and contact: [aiagentallowlist.com/contact.php](https://www.aiagentallowlist.com/contact.php)
- GitHub: [github.com/explainableaixai/aiagentallowlist-php](https://github.com/explainableaixai/aiagentallowlist-php)
- GitLab: [gitlab.com/url-classifications/aiagentallowlist](https://gitlab.com/url-classifications/aiagentallowlist)
- Packagist: [aiagentallowlist/aiagentallowlist](https://packagist.org/packages/aiagentallowlist/aiagentallowlist)
- Changelog: [CHANGELOG.md](CHANGELOG.md)

## License

MIT, copyright 2026 Alpha Quantum. Questions: info@alpha-quantum.com.
