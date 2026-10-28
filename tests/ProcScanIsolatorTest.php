<?php

declare(strict_types=1);

namespace SuperKernelTests\ScanIsolate;

use PHPUnit\Framework\TestCase;
use SuperKernel\ScanIsolate\Exception\ScanIsolatorException;
use SuperKernel\ScanIsolate\Executor\ProcScanIsolator;

use function count;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getcwd;
use function getmypid;
use function hash;
use function is_array;
use function is_file;
use function is_readable;
use function is_string;
use function preg_quote;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const PHP_SAPI;

final class ProcScanIsolatorTest extends TestCase
{
    public function testSupportsReflectsCurrentEntrypointAvailability(): void
    {
        $isolator = new ProcScanIsolator();
        $entryFile = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $expected = PHP_SAPI === 'cli'
            && function_exists('proc_open')
            && is_string($entryFile)
            && $entryFile !== ''
            && is_file($entryFile)
            && is_readable($entryFile);

        self::assertSame($expected, $isolator->supports());
    }

    public function testItSelfBootstrapsUsingTheCurrentEntrypoint(): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('proc_open')) {
            self::markTestSkipped('ProcScanIsolator requires CLI execution with proc_open().');
        }

        $isolator = new ProcScanIsolator();
        $markerFile = $this->markerFileFor(__FUNCTION__);
        $shouldCleanup = true;

        if (is_file($markerFile)) {
            unlink($markerFile);
        }

        $originalArgv = $_SERVER['argv'] ?? null;
        $originalArgc = $_SERVER['argc'] ?? null;

        try {
            $this->restrictChildPhpUnitCommandToCurrentTest(__FUNCTION__);

            $parentPid = getmypid();
            $executedByCurrentProcess = false;
            $scanned = $isolator->execute(static function () use ($markerFile, &$executedByCurrentProcess, &$shouldCleanup): void {
                $executedByCurrentProcess = true;
                $shouldCleanup = false;
                file_put_contents($markerFile, (string) getmypid());
            });

            self::assertFalse($executedByCurrentProcess);
            $childPid = file_get_contents($markerFile);
            self::assertNotFalse($childPid);
            self::assertTrue($scanned->isScanned());
            self::assertNotSame((string) $parentPid, trim($childPid));
        } finally {
            $this->restorePhpUnitCommand($originalArgv, $originalArgc);

            if ($shouldCleanup && is_file($markerFile)) {
                unlink($markerFile);
            }
        }
    }

    public function testItSurfacesNonZeroChildExitAsParentFailure(): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('proc_open')) {
            self::markTestSkipped('ProcScanIsolator requires CLI execution with proc_open().');
        }

        $isolator = new ProcScanIsolator();
        $originalArgv = $_SERVER['argv'] ?? null;
        $originalArgc = $_SERVER['argc'] ?? null;

        try {
            $this->restrictChildPhpUnitCommandToCurrentTest(__FUNCTION__);

            $this->expectException(ScanIsolatorException::class);
            $this->expectExceptionMessage('Isolate worker process exited with code 17.');

            $isolator->execute(static function (): void {
                exit(17);
            });
        } finally {
            $this->restorePhpUnitCommand($originalArgv, $originalArgc);
        }
    }

    private function restrictChildPhpUnitCommandToCurrentTest(string $methodName): void
    {
        $entryFile = $_SERVER['SCRIPT_FILENAME'] ?? null;
        self::assertIsString($entryFile);
        self::assertNotSame('', $entryFile);

        $cwd = getcwd();
        self::assertNotFalse($cwd);

        $filter = '/^' . preg_quote(self::class . '::' . $methodName, '/') . '$/';
        $_SERVER['argv'] = [
            $entryFile,
            '--configuration',
            $cwd . '/phpunit.xml.dist',
            '--filter',
            $filter,
            __FILE__,
        ];
        $_SERVER['argc'] = count($_SERVER['argv']);
    }

    private function restorePhpUnitCommand(mixed $argv, mixed $argc): void
    {
        if (is_array($argv)) {
            $_SERVER['argv'] = $argv;
        } else {
            unset($_SERVER['argv']);
        }

        if ($argc !== null) {
            $_SERVER['argc'] = $argc;
        } else {
            unset($_SERVER['argc']);
        }
    }

    private function markerFileFor(string $methodName): string
    {
        return sys_get_temp_dir() . '/super-kernel-isolate-' . hash('sha256', self::class . '::' . $methodName) . '.tmp';
    }
}
