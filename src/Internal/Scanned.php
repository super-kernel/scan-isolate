<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolate\Internal;

use SuperKernel\ScanIsolate\Contract\ScannedInterface;

final readonly class Scanned implements ScannedInterface
{
    public function __construct(private bool $isScanned)
    {
    }

    public function isScanned(): bool
    {
        return $this->isScanned;
    }
}
