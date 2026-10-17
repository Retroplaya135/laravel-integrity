<?php

declare(strict_types=1);

namespace LaravelIntegrity;

use InvalidArgumentException;

final class ReachabilityExceptions
{
    /** @param array<string, array{reason:string,debt:bool}> $entries */
    public function __construct(private array $entries) {}

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("Exception registry not found: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Exception registry must be an object.');
        }
        $entries = [];
        foreach ($decoded as $path => $entry) {
            if (!is_string($path) || $path === '' || !is_array($entry)) {
                throw new InvalidArgumentException('Each exception must use a relative file path and object value.');
            }
            $reason = $entry['reason'] ?? null;
            $debt = $entry['debt'] ?? false;
            if (!is_string($reason) || trim($reason) === '' || !is_bool($debt)) {
                throw new InvalidArgumentException("Malformed exception entry: {$path}");
            }
            $entries[str_replace('\\', '/', $path)] = ['reason' => trim($reason), 'debt' => $debt];
        }
        ksort($entries);
        return new self($entries);
    }

    public function contains(string $path): bool
    {
        return isset($this->entries[str_replace('\\', '/', $path)]);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->entries);
    }

    public function total(): int
    {
        return count($this->entries);
    }

    public function debt(): int
    {
        return count(array_filter($this->entries, fn (array $entry): bool => $entry['debt']));
    }
}
