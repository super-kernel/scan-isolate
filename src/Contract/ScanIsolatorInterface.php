<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolate\Contract;

interface ScanIsolatorInterface
{
    public function supports(): bool;

    /**
     * @param callable $callback
     * @return ScannedInterface
     */
    public function execute(callable $callback): ScannedInterface;
}
