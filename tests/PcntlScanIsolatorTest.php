<?php

declare(strict_types=1);

namespace SuperKernelTests\ScanIsolate;

use PHPUnit\Framework\TestCase;
use SuperKernel\ScanIsolate\Exception\ScanIsolatorException;
use SuperKernel\ScanIsolate\Executor\PcntlScanIsolator;

use function extension_loaded;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getmypid;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

final class PcntlScanIsolatorTest extends TestCase
{
    public function testSupportsReflectsPcntlAvailability(): void
    {
        $isolator = new PcntlScanIsolator();
        $expected = extension_loaded('pcntl')
            && function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid');

        self::assertSame($expected, $isolator->supports());
    }

    public function testExecuteRunsTheCallbackInTheForkedProcess(): void
    {
        if (!extension_loaded('pcntl') || !function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('The pcntl extension is not available.');
        }

        $isolator = new PcntlScanIsolator();
        $markerFile = tempnam(sys_get_temp_dir(), 'pcntl-scan-');
        $shouldCleanup = true;

        self::assertNotFalse($markerFile);

        try {
            $currentPid = getmypid();
            $executedByCurrentProcess = false;
            $scanned = $isolator->execute(static function () use ($markerFile, &$executedByCurrentProcess, &$shouldCleanup): void {
                $executedByCurrentProcess = true;
                $shouldCleanup = false;
                file_put_contents($markerFile, (string) getmypid());
            });

            self::assertFalse($executedByCurrentProcess);
            $forkedPid = file_get_contents($markerFile);
            self::assertNotFalse($forkedPid);
            self::assertTrue($scanned->isScanned());
            self::assertNotSame((string) $currentPid, trim($forkedPid));
        } finally {
            if ($shouldCleanup && $markerFile !== false && is_file($markerFile)) {
                unlink($markerFile);
            }
        }
    }

    public function testExecuteThrowsWhenTheForkedProcessExitsNonZero(): void
    {
        if (!extension_loaded('pcntl') || !function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('The pcntl extension is not available.');
        }

        $isolator = new PcntlScanIsolator();

        $this->expectException(ScanIsolatorException::class);
        $this->expectExceptionMessage('Isolate worker process exited with code 17.');

        $isolator->execute(static function (): void {
            exit(17);
        });
    }
}
