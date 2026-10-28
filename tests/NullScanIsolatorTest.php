<?php

declare(strict_types=1);

namespace SuperKernelTests\ScanIsolate;

use Phar;
use PHPUnit\Framework\TestCase;
use SuperKernel\ScanIsolate\Executor\NullScanIsolator;

use function extension_loaded;

final class NullScanIsolatorTest extends TestCase
{
    public function testSupportsMatchesCurrentPharRuntime(): void
    {
        $isolator = new NullScanIsolator();
        $expected = extension_loaded('phar') && Phar::running(false) !== '';

        self::assertSame($expected, $isolator->supports());
    }

    public function testExecuteMarksCurrentProcessAsScanned(): void
    {
        $isolator = new NullScanIsolator();
        $scanned = $isolator->execute(static fn (): null => null);

        self::assertTrue($scanned->isScanned());
    }
}
