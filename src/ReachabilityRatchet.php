<?php

declare(strict_types=1);

namespace LaravelIntegrity;

use InvalidArgumentException;
use RuntimeException;

final class ReachabilityRatchet
{
    public function __construct(private int $totalCeiling, private int $debtCeiling)
    {
        if ($totalCeiling < 0 || $debtCeiling < 0 || $debtCeiling > $totalCeiling) {
            throw new InvalidArgumentException('Invalid reachability baseline.');
        }
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("Baseline not found: {$path}");
        }
        $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($value) || !is_int($value['total'] ?? null) || !is_int($value['debt'] ?? null)) {
            throw new InvalidArgumentException('Baseline requires integer total and debt ceilings.');
        }
        return new self($value['total'], $value['debt']);
    }

    public function assertWithin(ReachabilityExceptions $exceptions): void
    {
        if ($exceptions->total() > $this->totalCeiling || $exceptions->debt() > $this->debtCeiling) {
            throw new RuntimeException(sprintf(
                'Reachability exceptions exceed baseline: total %d/%d, debt %d/%d.',
                $exceptions->total(), $this->totalCeiling, $exceptions->debt(), $this->debtCeiling
            ));
        }
    }
}
