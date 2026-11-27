<?php
declare(strict_types=1);

// Minimal stand-in for PHPUnit\Framework\TestCase, loaded only by tests/AllPest/run.php
// when real PHPUnit is not installed (for example in a sandbox without Composer access).

namespace PHPUnit\Framework;

class AssertionFailedError extends \Exception {}

abstract class TestCase
{
    public ?string $expectedException = null;

    protected function setUp(): void {}
    protected function tearDown(): void {}

    public function runSetUp(): void { $this->setUp(); }
    public function runTearDown(): void { $this->tearDown(); }

    public function expectException(string $class): void { $this->expectedException = $class; }

    private static function fail(string $msg): never { throw new AssertionFailedError($msg); }
    private static function show(mixed $v): string { return var_export($v, true); }

    public function assertSame(mixed $expected, mixed $actual, string $m = ''): void
    {
        if ($expected !== $actual) self::fail($m ?: 'Failed asserting that ' . self::show($actual) . ' is identical to ' . self::show($expected));
    }
    public function assertNotSame(mixed $expected, mixed $actual, string $m = ''): void
    {
        if ($expected === $actual) self::fail($m ?: 'Failed asserting that values are not identical: ' . self::show($actual));
    }
    public function assertEquals(mixed $expected, mixed $actual, string $m = ''): void
    {
        if ($expected != $actual) self::fail($m ?: 'Failed asserting that ' . self::show($actual) . ' equals ' . self::show($expected));
    }
    public function assertEqualsWithDelta(float|int $expected, float|int $actual, float $delta, string $m = ''): void
    {
        if (abs($expected - $actual) > $delta) self::fail($m ?: "Failed asserting that $actual matches $expected within $delta");
    }
    public function assertTrue(mixed $v, string $m = ''): void { if ($v !== true) self::fail($m ?: 'Failed asserting that ' . self::show($v) . ' is true'); }
    public function assertFalse(mixed $v, string $m = ''): void { if ($v !== false) self::fail($m ?: 'Failed asserting that ' . self::show($v) . ' is false'); }
    public function assertNull(mixed $v, string $m = ''): void { if ($v !== null) self::fail($m ?: 'Failed asserting that ' . self::show($v) . ' is null'); }
    public function assertNotNull(mixed $v, string $m = ''): void { if ($v === null) self::fail($m ?: 'Failed asserting that value is not null'); }
    public function assertCount(int $n, \Countable|iterable $c, string $m = ''): void
    {
        $actual = is_countable($c) ? count($c) : iterator_count($c instanceof \Traversable ? $c : new \ArrayIterator($c));
        if ($actual !== $n) self::fail($m ?: "Failed asserting that count $actual matches expected $n");
    }
    public function assertEmpty(mixed $v, string $m = ''): void { if (!empty($v)) self::fail($m ?: 'Failed asserting that ' . self::show($v) . ' is empty'); }
    public function assertNotEmpty(mixed $v, string $m = ''): void { if (empty($v)) self::fail($m ?: 'Failed asserting that value is not empty'); }
    public function assertContains(mixed $needle, iterable $h, string $m = ''): void
    {
        foreach ($h as $x) { if ($x === $needle) return; }
        self::fail($m ?: 'Failed asserting that array contains ' . self::show($needle));
    }
    public function assertNotContains(mixed $needle, iterable $h, string $m = ''): void
    {
        foreach ($h as $x) { if ($x === $needle) self::fail($m ?: 'Failed asserting that array does not contain ' . self::show($needle)); }
    }
    public function assertArrayHasKey(int|string $k, array $a, string $m = ''): void { if (!array_key_exists($k, $a)) self::fail($m ?: "Failed asserting that array has key $k"); }
    public function assertStringContainsString(string $needle, string $h, string $m = ''): void { if (!str_contains($h, $needle)) self::fail($m ?: "Failed asserting that '$h' contains '$needle'"); }
    public function assertStringStartsWith(string $prefix, string $h, string $m = ""): void { if (!str_starts_with($h, $prefix)) self::fail($m ?: "Failed asserting that string starts with $prefix"); }
    public function assertStringNotContainsString(string $needle, string $h, string $m = ''): void { if (str_contains($h, $needle)) self::fail($m ?: "Failed asserting that '$h' does not contain '$needle'"); }
    public function assertGreaterThan(float|int $e, float|int $a, string $m = ''): void { if (!($a > $e)) self::fail($m ?: "Failed asserting that $a is greater than $e"); }
    public function assertGreaterThanOrEqual(float|int $e, float|int $a, string $m = ''): void { if (!($a >= $e)) self::fail($m ?: "Failed asserting that $a is >= $e"); }
    public function assertLessThan(float|int $e, float|int $a, string $m = ''): void { if (!($a < $e)) self::fail($m ?: "Failed asserting that $a is less than $e"); }
    public function assertLessThanOrEqual(float|int $e, float|int $a, string $m = ''): void { if (!($a <= $e)) self::fail($m ?: "Failed asserting that $a is <= $e"); }
    public function assertInstanceOf(string $class, mixed $v, string $m = ''): void { if (!($v instanceof $class)) self::fail($m ?: "Failed asserting that value is an instance of $class"); }
    public function assertMatchesRegularExpression(string $re, string $s, string $m = ''): void { if (!preg_match($re, $s)) self::fail($m ?: "Failed asserting that '$s' matches $re"); }
}
