<?php

declare(strict_types=1);

require __DIR__ . '/../src/ReferenceGraph.php';
require __DIR__ . '/../src/ReachabilityExceptions.php';
require __DIR__ . '/../src/ReachabilityRatchet.php';
require __DIR__ . '/../src/ReachabilityAnalyzer.php';

use LaravelIntegrity\ReferenceGraph;
use LaravelIntegrity\ReachabilityAnalyzer;
use LaravelIntegrity\ReachabilityExceptions;
use LaravelIntegrity\ReachabilityRatchet;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tests = [];
$tests['reference graph resolves imports transitively'] = static function () use ($expect): void {
    $first = ReferenceGraph::inspect('<?php namespace App\\Jobs; use App\\Services\\Worker; class RunJob extends Worker {}');
    $expect($first['declarations'] === ['App\\Jobs\\RunJob'], 'Declaration parsing did not preserve the namespace.');
    $expect($first['references'] === ['App\\Services\\Worker'], 'Imported reference did not resolve to its fully qualified name.');
    $expect(
        ReferenceGraph::referencesToKnown(
            '<?php namespace App\\Jobs; use App\\Services\\Worker; class RunJob extends Worker {}',
            ['App\\Jobs\\RunJob', 'App\\Services\\Worker']
        ) === ['App\\Jobs\\RunJob', 'App\\Services\\Worker'],
        'Known-reference discovery did not resolve a same-line import.'
    );
    $graph = new ReferenceGraph();
    $graph->add('App\\Jobs\\RunJob', 'Jobs/RunJob.php', ['App\\Services\\Worker']);
    $graph->add('App\\Services\\Worker', 'Services/Worker.php', []);
    $expect(
        $graph->reachableFrom(['App\\Jobs\\RunJob']) === ['App\\Jobs\\RunJob', 'App\\Services\\Worker'],
        'Transitive reachability omitted a referenced class.'
    );
};
$tests['exceptions reject malformed entries'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'li-exceptions-');
    file_put_contents($path, '{"App\\\\A":{"reason":""}}');
    try {
        ReachabilityExceptions::fromFile($path);
        throw new RuntimeException('Malformed registry was accepted.');
    } catch (InvalidArgumentException) {
    } finally {
        unlink($path);
    }
};
$tests['ratchet rejects growth'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'li-exceptions-');
    file_put_contents($path, '{"app/Legacy/A.php":{"reason":"fixture","debt":true}}');
    $exceptions = ReachabilityExceptions::fromFile($path);
    try {
        (new ReachabilityRatchet(0, 0))->assertWithin($exceptions);
        throw new RuntimeException('Ratchet growth was accepted.');
    } catch (RuntimeException) {
    } finally {
        unlink($path);
    }
};
$tests['analyzer detects unregistered and stale classes'] = static function () use ($expect): void {
    $root = sys_get_temp_dir() . '/li-fixture-' . bin2hex(random_bytes(4));
    mkdir($root . '/app/Jobs', 0777, true);
    mkdir($root . '/app/Services', 0777, true);
    file_put_contents($root . '/app/Jobs/RunJob.php', '<?php namespace App\\Jobs; use App\\Services\\UsedService; class RunJob extends UsedService {}');
    file_put_contents($root . '/app/Services/UsedService.php', '<?php namespace App\\Services; class UsedService {}');
    file_put_contents($root . '/app/Services/UnusedService.php', '<?php namespace App\\Services; class UnusedService {}');
    $registry = $root . '/exceptions.json';
    file_put_contents($registry, '{"app/Missing.php":{"reason":"fixture","debt":false}}');
    $result = (new ReachabilityAnalyzer())->analyze($root, ReachabilityExceptions::fromFile($registry));
    $expect($result['unreachable'] === ['app/Services/UnusedService.php'], 'Analyzer did not isolate the unregistered file.');
    $expect($result['stale_exceptions'] === ['app/Missing.php'], 'Analyzer did not report the stale exception.');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}
exit($failures === 0 ? 0 : 1);
