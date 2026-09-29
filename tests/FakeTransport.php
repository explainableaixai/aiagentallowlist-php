<?php

declare(strict_types=1);

namespace AIAgentAllowlist\Tests;

/**
 * Offline transport: returns queued responses and records every request.
 */
final class FakeTransport
{
    /** @var array<int, array{status:int, body:string}> */
    private $queue;

    /** @var array<int, array{url:string, headers:array<int,string>, timeout:int}> */
    public $requests = [];

    /** @var int[] */
    public $sleeps = [];

    /**
     * @param array<int, array{status:int, body:string}> $queue
     */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    /**
     * @param string[] $headers
     * @return array{status:int, body:string}
     */
    public function __invoke(string $url, array $headers, int $timeout): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'timeout' => $timeout];
        if (!$this->queue) {
            throw new \LogicException('FakeTransport queue is empty');
        }
        return array_shift($this->queue);
    }

    public function sleeper(): callable
    {
        return function (int $ms): void {
            $this->sleeps[] = $ms;
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status:int, body:string}
     */
    public static function json(int $status, array $data): array
    {
        return ['status' => $status, 'body' => (string) json_encode($data)];
    }
}
