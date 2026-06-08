<?php
// tests/TestCase.php — tiny zero-dependency test harness.
// Provides PHPUnit-style assertions and a runner, so the suite runs anywhere
// with just `php tests/run.php` (no Composer required).

class TestCase
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static array $failures = [];
    protected string $current = '';

    public function run(): void
    {
        $methods = array_filter(get_class_methods($this), fn($m) => str_starts_with($m, 'test'));
        foreach ($methods as $m) {
            $this->current = (new ReflectionClass($this))->getShortName() . '::' . $m;
            try {
                if (method_exists($this, 'setUp')) $this->setUp();
                $this->$m();
                echo "  \033[32m✓\033[0m {$m}\n";
            } catch (AssertionFailed $e) {
                self::$failed++;
                self::$failures[] = $this->current . ' — ' . $e->getMessage();
                echo "  \033[31m✗\033[0m {$m} — {$e->getMessage()}\n";
            } catch (Throwable $e) {
                self::$failed++;
                self::$failures[] = $this->current . ' — EXCEPTION: ' . $e->getMessage();
                echo "  \033[31m✗\033[0m {$m} — EXCEPTION: {$e->getMessage()}\n";
            }
        }
    }

    protected function assertTrue($cond, string $msg = ''): void
    {
        if ($cond === true) { self::$passed++; return; }
        throw new AssertionFailed($msg ?: 'Expected true, got ' . var_export($cond, true));
    }
    protected function assertFalse($cond, string $msg = ''): void
    {
        if ($cond === false) { self::$passed++; return; }
        throw new AssertionFailed($msg ?: 'Expected false, got ' . var_export($cond, true));
    }
    protected function assertEquals($expected, $actual, string $msg = ''): void
    {
        if ($expected == $actual) { self::$passed++; return; }
        throw new AssertionFailed($msg ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
    protected function assertSame($expected, $actual, string $msg = ''): void
    {
        if ($expected === $actual) { self::$passed++; return; }
        throw new AssertionFailed($msg ?: "Expected (===) " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
    protected function assertOk(array $r, string $msg = ''): void
    {
        if (($r['ok'] ?? false) === true) { self::$passed++; return; }
        throw new AssertionFailed($msg ?: 'Expected ok=true, got error: ' . ($r['error'] ?? '?'));
    }
    protected function assertErr(array $r, ?int $code = null, string $msg = ''): void
    {
        if (($r['ok'] ?? true) !== false) throw new AssertionFailed($msg ?: 'Expected an error result, got ok');
        if ($code !== null && ($r['code'] ?? null) !== $code)
            throw new AssertionFailed($msg ?: "Expected error code $code, got " . ($r['code'] ?? '?') . " ({$r['error']})");
        self::$passed++;
    }
}

class AssertionFailed extends Exception {}
