<?php
// tests/run.php — entry point.  Usage:
//   EARMS_DB_USER=root EARMS_DB_PASS=secret php tests/run.php
// Builds an isolated test DB, runs every TestCase, prints a summary.

require_once __DIR__ . '/bootstrap.php';

$suites = [];
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require_once $file;
    $class = pathinfo($file, PATHINFO_FILENAME);
    if (class_exists($class)) $suites[] = $class;
}

echo "\nEARMS — running " . count($suites) . " test suite(s)\n";
echo str_repeat('─', 48) . "\n";
foreach ($suites as $class) {
    echo "$class\n";
    (new $class())->run();
    echo "\n";
}

echo str_repeat('─', 48) . "\n";
$total = TestCase::$passed + TestCase::$failed;
if (TestCase::$failed === 0) {
    echo "\033[32mPASS\033[0m — " . TestCase::$passed . " assertions, 0 failures\n";
    exit(0);
}
echo "\033[31mFAIL\033[0m — " . TestCase::$failed . " failed of $total assertions\n";
foreach (TestCase::$failures as $f) echo "  • $f\n";
exit(1);
