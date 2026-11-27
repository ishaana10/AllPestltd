<?php
declare(strict_types=1);

// Dependency-free runner: php tests/AllPest/run.php <file|dir> [...]
// Uses real PHPUnit's TestCase when it is installed, otherwise tests/AllPest/MiniTestCase.php.

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
if (!class_exists('PHPUnit\Framework\TestCase')) {
    require_once __DIR__ . '/MiniTestCase.php';
}

$args = array_slice($argv, 1);
if (!$args) {
    fwrite(STDERR, "usage: php tests/AllPest/run.php <file|dir> [...]\n");
    exit(2);
}

$files = [];
foreach ($args as $a) {
    if (is_dir($a)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), 'Test.php')) $files[] = $f->getPathname();
        }
    } elseif (is_file($a)) {
        $files[] = $a;
    } else {
        fwrite(STDERR, "not found: $a\n");
        exit(2);
    }
}
sort($files);

$before = get_declared_classes();
foreach ($files as $f) require_once $f;
$classes = array_diff(get_declared_classes(), $before);

$run = 0;
$failures = [];
foreach ($classes as $class) {
    $rc = new ReflectionClass($class);
    if ($rc->isAbstract() || !$rc->isSubclassOf('PHPUnit\Framework\TestCase')) continue;
    foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if (!str_starts_with($m->getName(), 'test')) continue;
        $run++;
        $t = $rc->newInstance();
        $label = $class . '::' . $m->getName();
        try {
            if (method_exists($t, 'runSetUp')) $t->runSetUp();
            try {
                $t->{$m->getName()}();
                if (!empty($t->expectedException)) {
                    throw new RuntimeException('Expected exception ' . $t->expectedException . ' was not thrown');
                }
            } catch (Throwable $e) {
                $exp = $t->expectedException ?? null;
                if (!$exp || !($e instanceof $exp)) throw $e;
            } finally {
                if (method_exists($t, 'runTearDown')) $t->runTearDown();
            }
            echo '.';
        } catch (Throwable $e) {
            echo 'F';
            $failures[] = $label . ' - ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }
    }
}
echo "\n";
if ($failures) {
    echo 'FAILURES (' . count($failures) . " of $run):\n";
    foreach ($failures as $f) echo ' - ' . $f . "\n";
    exit(1);
}
echo "OK ($run tests)\n";
