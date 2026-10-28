<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolate\Executor;

use Phar;
use SuperKernel\ScanIsolate\Contract\ScanIsolatorInterface;
use SuperKernel\ScanIsolate\Contract\ScannedInterface;
use SuperKernel\ScanIsolate\Internal\Scanned;

use function extension_loaded;

final readonly class NullScanIsolator implements ScanIsolatorInterface
{
    public function supports(): bool
    {
        if (!extension_loaded('phar')) {
            return false;
        }

        if (Phar::running(false) === '') {
            return false;
        }

        return true;
    }

    public function execute(callable $callback): ScannedInterface
    {
        return new Scanned(true);
    }
}
