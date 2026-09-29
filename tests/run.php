<?php

/**
 * Runs tests/ClientTest.php without PHPUnit.
 *
 * When PHPUnit is not installed, a minimal TestCase shim providing the assertions
 * used by ClientTest is declared, then every test* method (and its data provider) is run.
 *
 *   php tests/run.php
 */

declare(strict_types=1);

namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        class AssertionFailedError extends \Exception
        {
        }

        abstract class TestCase
        {
            /** @var string|null */
            private $expectedException;

            /** @var string|null */
            private $expectedMessage;

            /** @var int */
            public static $assertions = 0;

            /**
             * @param mixed $cond
             */
            private function check($cond, string $msg): void
            {
                self::$assertions++;
                if (!$cond) {
                    throw new AssertionFailedError($msg);
                }
            }

            /**
             * @param mixed $expected
             * @param mixed $actual
             */
            public function assertSame($expected, $actual): void
            {
                $this->check($expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
            }

            /**
             * @param mixed $v
             */
            public function assertTrue($v): void
            {
                $this->check($v === true, 'expected true');
            }

            /**
             * @param mixed $v
             */
            public function assertFalse($v): void
            {
                $this->check($v === false, 'expected false');
            }

            /**
             * @param mixed $v
             */
            public function assertNull($v): void
            {
                $this->check($v === null, 'expected null, got ' . var_export($v, true));
            }

            /**
             * @param mixed $obj
             */
            public function assertInstanceOf(string $class, $obj): void
            {
                $this->check($obj instanceof $class, 'expected instance of ' . $class);
            }

            /**
             * @param mixed $needle
             * @param iterable<mixed> $haystack
             */
            public function assertContains($needle, iterable $haystack): void
            {
                $found = false;
                foreach ($haystack as $h) {
                    if ($h === $needle) {
                        $found = true;
                    }
                }
                $this->check($found, 'missing ' . var_export($needle, true));
            }

            /**
             * @param \Countable|array<mixed> $v
             */
            public function assertCount(int $n, $v): void
            {
                $this->check(count($v) === $n, "expected count {$n}");
            }

            public function assertStringContainsString(string $needle, string $hay): void
            {
                $this->check(strpos($hay, $needle) !== false, "'{$hay}' does not contain '{$needle}'");
            }

            public function assertStringNotContainsString(string $needle, string $hay): void
            {
                $this->check(strpos($hay, $needle) === false, "'{$hay}' contains '{$needle}'");
            }

            public function fail(string $msg): void
            {
                throw new AssertionFailedError($msg);
            }

            public function expectException(string $class): void
            {
                $this->expectedException = $class;
            }

            public function expectExceptionMessage(string $msg): void
            {
                $this->expectedMessage = $msg;
            }

            /**
             * @param array<int, mixed> $args
             */
            public function runTest(string $name, array $args): void
            {
                $this->expectedException = null;
                $this->expectedMessage = null;
                try {
                    $this->{$name}(...$args);
                } catch (AssertionFailedError $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    if ($this->expectedException !== null && $e instanceof $this->expectedException) {
                        self::$assertions++;
                        if ($this->expectedMessage !== null) {
                            $this->assertStringContainsString($this->expectedMessage, $e->getMessage());
                        }
                        return;
                    }
                    throw $e;
                }
                if ($this->expectedException !== null) {
                    throw new AssertionFailedError('expected exception ' . $this->expectedException);
                }
            }
        }
    }
}

namespace {
    $root = dirname(__DIR__);
    spl_autoload_register(static function (string $class) use ($root): void {
        $map = ['AIAgentAllowlist\\Tests\\' => $root . '/tests/', 'AIAgentAllowlist\\' => $root . '/src/'];
        foreach ($map as $prefix => $dir) {
            if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    });

    $class = \AIAgentAllowlist\Tests\ClientTest::class;
    $ref = new \ReflectionClass($class);
    $passed = 0;
    $failed = 0;
    foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
        if (strpos($m->getName(), 'test') !== 0) {
            continue;
        }
        $sets = ['' => []];
        if (preg_match('/@dataProvider\s+(\w+)/', (string) $m->getDocComment(), $mm)) {
            $sets = (new $class())->{$mm[1]}();
        }
        foreach ($sets as $label => $args) {
            $name = $m->getName() . ($label !== '' ? " [{$label}]" : '');
            try {
                (new $class())->runTest($m->getName(), array_values($args));
                $passed++;
                echo "ok   {$name}\n";
            } catch (\Throwable $e) {
                $failed++;
                echo "FAIL {$name}: " . get_class($e) . ': ' . $e->getMessage() . "\n";
            }
        }
    }
    printf("\n%d passed, %d failed, %d assertions\n", $passed, $failed, \PHPUnit\Framework\TestCase::$assertions);
    exit($failed === 0 ? 0 : 1);
}
