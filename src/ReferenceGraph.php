<?php

declare(strict_types=1);

namespace LaravelIntegrity;

final class ReferenceGraph
{
    /** @var array<string, array{file:string,references:list<string>}> */
    private array $nodes = [];

    /** @param list<string> $references */
    public function add(string $class, string $file, array $references): void
    {
        $references = array_values(array_unique(array_filter($references, fn (string $item): bool => $item !== $class)));
        sort($references);
        $this->nodes[$class] = ['file' => $file, 'references' => $references];
        ksort($this->nodes);
    }

    /** @param list<string> $references */
    public function setReferences(string $class, array $references): void
    {
        if (!isset($this->nodes[$class])) {
            return;
        }
        $references = array_values(array_unique(array_filter(
            $references,
            fn (string $item): bool => $item !== $class && isset($this->nodes[$item])
        )));
        sort($references);
        $this->nodes[$class]['references'] = $references;
    }

    /** @return list<string> */
    public function classes(): array
    {
        return array_keys($this->nodes);
    }

    public function fileFor(string $class): ?string
    {
        return $this->nodes[$class]['file'] ?? null;
    }

    /** @return list<string> */
    public function reachableFrom(array $seeds): array
    {
        $seen = [];
        $queue = array_values(array_unique($seeds));
        while ($queue !== []) {
            $class = array_shift($queue);
            if (isset($seen[$class]) || !isset($this->nodes[$class])) {
                continue;
            }
            $seen[$class] = true;
            foreach ($this->nodes[$class]['references'] as $reference) {
                if (!isset($seen[$reference])) {
                    $queue[] = $reference;
                }
            }
        }
        $result = array_keys($seen);
        sort($result);
        return $result;
    }

    /** @return array{declarations:list<string>,references:list<string>} */
    public static function inspect(string $source): array
    {
        $tokens = token_get_all($source);
        $namespace = '';
        $uses = [];
        $declarations = [];
        $references = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                continue;
            }
            [$kind] = $token;
            if ($kind === T_NAMESPACE) {
                [$name, $index] = self::readName($tokens, $index + 1);
                $namespace = trim($name, '\\');
                continue;
            }
            if ($kind === T_USE) {
                [$name, $index] = self::readName($tokens, $index + 1);
                if ($name !== '') {
                    $parts = explode('\\', trim($name, '\\'));
                    $uses[end($parts)] = trim($name, '\\');
                }
                continue;
            }
            $declarationKinds = [T_CLASS, T_INTERFACE, T_TRAIT];
            if (defined('T_ENUM')) {
                $declarationKinds[] = T_ENUM;
            }
            if (in_array($kind, $declarationKinds, true)) {
                $previous = self::previousMeaningful($tokens, $index - 1);
                if ($kind === T_CLASS && is_array($previous) && $previous[0] === T_NEW) {
                    continue;
                }
                [$name] = self::readName($tokens, $index + 1);
                if ($name !== '') {
                    $declarations[] = self::qualify($name, $namespace, $uses);
                }
                continue;
            }
            if (in_array($kind, [T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF], true)) {
                [$name, $index] = self::readName($tokens, $index + 1);
                if ($name !== '') {
                    $references[] = self::qualify($name, $namespace, $uses);
                }
            }
        }

        $declarations = array_values(array_unique($declarations));
        $references = array_values(array_unique($references));
        sort($declarations);
        sort($references);
        return ['declarations' => $declarations, 'references' => $references];
    }

    /**
     * Resolve class-like names in source, then retain only declarations known to
     * the current application graph. This catches typed properties, parameters,
     * return types, attributes, and ::class references without executing code.
     *
     * @param list<string> $knownClasses
     * @return list<string>
     */
    public static function referencesToKnown(string $source, array $knownClasses): array
    {
        $tokens = token_get_all($source);
        $namespace = '';
        $uses = self::extractUses($source);
        $candidates = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                [$name, $index] = self::readName($tokens, $index + 1);
                $namespace = trim($name, '\\');
                continue;
            }
            $nameKinds = [T_STRING];
            foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
                if (defined($constant)) {
                    $nameKinds[] = constant($constant);
                }
            }
            if (in_array($token[0], $nameKinds, true)) {
                $candidates[] = self::qualify($token[1], $namespace, $uses);
            }
        }

        $known = array_fill_keys($knownClasses, true);
        $result = array_values(array_unique(array_filter($candidates, fn (string $name): bool => isset($known[$name]))));
        sort($result);
        return $result;
    }

    /** @return array<string,string> */
    private static function extractUses(string $source): array
    {
        $uses = [];
        $tokens = token_get_all($source);
        $depth = 0;
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if (!is_array($token) || $token[0] !== T_USE || $depth !== 0) {
                continue;
            }
            [$name, $nameEnd] = self::readName($tokens, $index + 1);
            if ($name === '') {
                continue;
            }
            $index = $nameEnd;
            $parts = explode('\\', trim($name, '\\'));
            $alias = end($parts);
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $candidate = $tokens[$cursor];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($candidate) && $candidate[0] === T_AS) {
                    [$explicitAlias, $aliasEnd] = self::readName($tokens, $cursor + 1);
                    if ($explicitAlias !== '') {
                        $alias = $explicitAlias;
                    }
                    $index = $aliasEnd;
                }
                break;
            }
            $uses[$alias] = trim($name, '\\');
        }
        return $uses;
    }

    /** @return array{0:string,1:int} */
    private static function readName(array $tokens, int $index): array
    {
        $name = '';
        $count = count($tokens);
        for (; $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $nameKinds = [T_STRING, T_NS_SEPARATOR];
            foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
                if (defined($constant)) {
                    $nameKinds[] = constant($constant);
                }
            }
            if (is_array($token) && in_array($token[0], $nameKinds, true)) {
                $name .= $token[1];
                continue;
            }
            break;
        }
        return [trim($name), $index - 1];
    }

    private static function qualify(string $name, string $namespace, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return trim($name, '\\');
        }
        $parts = explode('\\', $name);
        if (isset($uses[$parts[0]])) {
            array_shift($parts);
            return $uses[explode('\\', $name)[0]] . ($parts ? '\\' . implode('\\', $parts) : '');
        }
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    private static function previousMeaningful(array $tokens, int $index): mixed
    {
        for (; $index >= 0; $index--) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $token;
        }
        return null;
    }
}
