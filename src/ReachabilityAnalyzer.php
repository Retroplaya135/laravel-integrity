<?php

declare(strict_types=1);

namespace LaravelIntegrity;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ReachabilityAnalyzer
{
    /** @param list<string> $entryPointSegments @param list<string> $wiringDirectories */
    public function __construct(
        private array $entryPointSegments = ['Http/Controllers', 'Http/Middleware', 'Jobs', 'Console', 'Providers', 'Listeners', 'Notifications', 'Policies'],
        private array $wiringDirectories = ['config', 'bootstrap', 'routes'],
        private string $sourceDirectory = 'app'
    ) {}

    /** @return array{reachable:list<string>,unreachable:list<string>,stale_exceptions:list<string>,accepted_exceptions:list<string>} */
    public function analyze(string $root, ReachabilityExceptions $exceptions): array
    {
        $root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
        $sourceRoot = $root . DIRECTORY_SEPARATOR . $this->sourceDirectory;
        if (!is_dir($sourceRoot)) {
            throw new \InvalidArgumentException("Application source directory not found: {$sourceRoot}");
        }
        $graph = new ReferenceGraph();
        $seeds = [];
        $sources = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
            $relative = str_replace('\\', '/', $relative);
            $normalizedRelative = '/' . trim($relative, '/') . '/';
            $source = (string) file_get_contents($path);
            $inspection = ReferenceGraph::inspect($source);
            foreach ($inspection['declarations'] as $class) {
                $graph->add($class, $relative, []);
                $sources[$class] = $source;
                foreach ($this->entryPointSegments as $segment) {
                    if (str_contains($normalizedRelative, '/' . trim($segment, '/') . '/')) {
                        $seeds[] = $class;
                        break;
                    }
                }
            }
        }
        $classes = $graph->classes();
        foreach ($sources as $class => $source) {
            $graph->setReferences($class, ReferenceGraph::referencesToKnown($source, $classes));
        }
        foreach ($this->wiringDirectories as $directory) {
            $wiringRoot = $root . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($wiringRoot)) {
                continue;
            }
            $wiring = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wiringRoot));
            foreach ($wiring as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    array_push($seeds, ...ReferenceGraph::referencesToKnown((string) file_get_contents($file->getPathname()), $classes));
                }
            }
        }
        $reachable = $graph->reachableFrom($seeds);
        $unreachableClasses = array_values(array_diff($graph->classes(), $reachable));
        $unreachablePaths = array_values(array_unique(array_filter(array_map($graph->fileFor(...), $unreachableClasses))));
        sort($unreachablePaths);
        $accepted = array_values(array_filter($unreachablePaths, $exceptions->contains(...)));
        $unregistered = array_values(array_diff($unreachablePaths, $accepted));
        $stale = array_values(array_diff($exceptions->paths(), $unreachablePaths));
        sort($accepted);
        sort($unregistered);
        sort($stale);
        return [
            'reachable' => $reachable,
            'unreachable' => $unregistered,
            'stale_exceptions' => $stale,
            'accepted_exceptions' => $accepted,
        ];
    }
}
