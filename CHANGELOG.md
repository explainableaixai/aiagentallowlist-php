# Changelog

All notable changes to this package are documented here. The format follows Keep a Changelog, and versions follow Semantic Versioning.

## [1.0.0] - 2026-09-29

### Added

- `AIAgentAllowlist\Client` for `GET https://www.aiagentallowlist.com/api/check` with the `X-API-Key` header, curl transport, configurable timeout and base URL.
- `check()`, `isAllowed()`, `pageTypes()`, `denyList()` and `checkMany()` (generator with a pause between calls).
- Method-aware lookups: POST, PUT, PATCH and DELETE are passed through as the `method` parameter.
- Automatic retries on HTTP 429 (1.5 s, then 3 s).
- `AIAgentAllowlist\Verdict` with typed accessors, read-only array access and JSON serialisation.
- Typed exceptions: `BadRequestException` (400), `AuthenticationException` (401), `QuotaException` (403), `RateLimitException` (429), all extending `AIAgentAllowlistException`.
- Injectable `transport` and `sleeper` callables for offline tests.
- Examples: Laravel-style middleware, batch CSV audit, command-line tool.
- Offline test suite (`tests/ClientTest.php`) and a dependency-free runner (`tests/run.php`).
