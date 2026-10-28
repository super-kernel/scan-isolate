<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolate\Contract;

interface ScannedInterface
{
    public function isScanned(): bool;
}
