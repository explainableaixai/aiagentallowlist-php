<?php

declare(strict_types=1);

namespace AIAgentAllowlist\Tests;

use AIAgentAllowlist\Client;
use AIAgentAllowlist\Exception\AIAgentAllowlistException;
use AIAgentAllowlist\Exception\AuthenticationException;
use AIAgentAllowlist\Exception\BadRequestException;
use AIAgentAllowlist\Exception\QuotaException;
use AIAgentAllowlist\Exception\RateLimitException;
use AIAgentAllowlist\Verdict;
use PHPUnit\Framework\TestCase;

/**
 * Offline tests: every HTTP exchange goes through FakeTransport, no network is used.
 */
final class ClientTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    public static function loginDeny(): array
    {
        return [
            'found' => true,
            'verdict' => 'deny',
            'verdict_scope' => 'url',
            'matched' => ['layer' => 'page_type_db', 'id' => 'login', 'note' => 'verified login page'],
            'page_types' => [
                'login' => 'https://example.com/login',
                'pricing' => 'https://example.com/pricing',
                'checkout' => 'https://example.com/checkout',
                'documentation' => 'https://docs.example.com',
            ],
            'language' => 'en',
            'iab_category' => 'Technology & Computing',
            'filtering_categories' => ['Business'],
            'open_page_rank' => 6.2,
            'global_rank' => 1234,
            'remaining_lookups' => 9999,
        ];
    }

    /**
     * @param array<int, array{status:int, body:string}> $queue
     */
    private function client(array $queue, ?FakeTransport &$fake = null, array $options = []): Client
    {
        $fake = new FakeTransport($queue);
        return new Client('test-key', $options + ['transport' => $fake, 'sleeper' => $fake->sleeper()]);
    }

    public function testVerdictParsing(): void
    {
        $client = $this->client([FakeTransport::json(200, self::loginDeny())], $fake);
        $v = $client->check('https://example.com/login');

        $this->assertInstanceOf(Verdict::class, $v);
        $this->assertSame('deny', $v->verdict());
        $this->assertTrue($v->denied());
        $this->assertFalse($v->allowed());
        $this->assertFalse($v->flagged());
        $this->assertTrue($v->found());
        $this->assertSame('url', $v->verdictScope());
        $this->assertSame('page_type_db', $v->matchedLayer());
        $this->assertSame('login', $v->matchedId());
        $this->assertSame('verified login page', $v->matchedNote());
        $this->assertSame('https://example.com/pricing', $v->pageTypes()['pricing']);
        $this->assertSame('en', $v->language());
        $this->assertSame(['Business'], $v->filteringCategories());
        $this->assertSame(9999, $v->remainingLookups());
        $this->assertSame('deny', $v['verdict']);
        $this->assertSame(self::loginDeny(), $v->toArray());
    }

    public function testRequestShape(): void
    {
        $client = $this->client([FakeTransport::json(200, ['verdict' => 'allow'])], $fake,
            ['base_url' => 'https://proxy.internal/api/', 'timeout' => 7]);
        $this->assertTrue($client->isAllowed('https://example.com/a b?x=1', 'post'));

        $req = $fake->requests[0];
        $this->assertSame('https://proxy.internal/api/check?url=https%3A%2F%2Fexample.com%2Fa%20b%3Fx%3D1&method=POST', $req['url']);
        $this->assertContains('X-API-Key: test-key', $req['headers']);
        $this->assertContains('User-Agent: aiagentallowlist-php/1.0.0 (+https://www.aiagentallowlist.com)', $req['headers']);
        $this->assertSame(7, $req['timeout']);
    }

    public function testGetMethodIsNotSent(): void
    {
        $client = $this->client([FakeTransport::json(200, ['verdict' => 'allow'])], $fake);
        $client->check('example.com');
        $this->assertStringNotContainsString('method=', $fake->requests[0]['url']);
    }

    public function testEmptyVerdict(): void
    {
        $v = new Verdict([]);
        $this->assertNull($v->verdict());
        $this->assertFalse($v->allowed());
        $this->assertFalse($v->found());
        $this->assertSame([], $v->pageTypes());
        $this->assertNull($v->matchedLayer());
        $this->assertNull($v->remainingLookups());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public function errorProvider(): array
    {
        return [
            '400' => [400, BadRequestException::class],
            '401' => [401, AuthenticationException::class],
            '403' => [403, QuotaException::class],
            '429' => [429, RateLimitException::class],
            '500' => [500, AIAgentAllowlistException::class],
        ];
    }

    /**
     * @dataProvider errorProvider
     */
    public function testErrorMapping(int $status, string $class): void
    {
        $client = $this->client([FakeTransport::json($status, ['error' => 'nope'])], $fake, ['max_retries' => 0]);
        try {
            $client->check('https://example.com');
            $this->fail('expected ' . $class);
        } catch (AIAgentAllowlistException $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertSame($status, $e->getStatus());
            $this->assertStringContainsString('nope', $e->getMessage());
            $this->assertSame(['error' => 'nope'], $e->getBody());
        }
    }

    public function testNonJsonErrorBody(): void
    {
        $this->expectException(AIAgentAllowlistException::class);
        $this->expectExceptionMessage('HTTP 502: Bad Gateway');
        Client::handle(502, 'Bad Gateway');
    }

    public function testRetryOn429ThenSuccess(): void
    {
        $client = $this->client([
            FakeTransport::json(429, ['error' => 'slow down']),
            FakeTransport::json(429, ['error' => 'slow down']),
            FakeTransport::json(200, ['verdict' => 'flag']),
        ], $fake);
        $this->assertTrue($client->check('https://example.com/x')->flagged());
        $this->assertSame([1500, 3000], $fake->sleeps);
        $this->assertCount(3, $fake->requests);
    }

    public function testCheckManyAndDenyList(): void
    {
        $client = $this->client([
            FakeTransport::json(200, ['verdict' => 'allow']),
            FakeTransport::json(200, ['verdict' => 'deny']),
            FakeTransport::json(200, self::loginDeny()),
        ], $fake);
        $out = iterator_to_array($client->checkMany(['https://a.com/', 'https://b.com/login'], 'GET', 250));
        $this->assertTrue($out['https://a.com/']->allowed());
        $this->assertTrue($out['https://b.com/login']->denied());
        $this->assertSame([250], $fake->sleeps);

        $this->assertSame(['https://example.com/login', 'https://example.com/checkout'], $client->denyList('example.com'));
    }

    public function testEmptyKeyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }
}
