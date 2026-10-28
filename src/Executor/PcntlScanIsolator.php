<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolate\Executor;

use SuperKernel\ScanIsolate\Contract\ScanIsolatorInterface;
use SuperKernel\ScanIsolate\Contract\ScannedInterface;
use SuperKernel\ScanIsolate\Exception\ScanIsolatorException;
use SuperKernel\ScanIsolate\Internal\Scanned;
use Throwable;

use function extension_loaded;
use function function_exists;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function sprintf;

final readonly class PcntlScanIsolator implements ScanIsolatorInterface
{
    public function supports(): bool
    {
        if (!extension_loaded('pcntl')) {
            return false;
        }

        if (!function_exists('pcntl_fork')) {
            return false;
        }

        if (!function_exists('pcntl_waitpid')) {
            return false;
        }

        return true;
    }

    public function execute(callable $callback): ScannedInterface
    {
        if (!extension_loaded('pcntl')) {
            throw new ScanIsolatorException('The pcntl extension is required for PcntlScanIsolator.');
        }

        if (!function_exists('pcntl_fork')) {
            throw new ScanIsolatorException('The pcntl_fork function is required for PcntlScanIsolator.');
        }

        if (!function_exists('pcntl_waitpid')) {
            throw new ScanIsolatorException('The pcntl_waitpid function is required for PcntlScanIsolator.');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new ScanIsolatorException('Unable to fork isolate worker process.');
        }

        if ($pid > 0) {
            $status = 0;
            $waitResult = pcntl_waitpid($pid, $status);

            if ($waitResult === -1) {
                throw new ScanIsolatorException('Unable to wait for isolate worker process.');
            }

            if (pcntl_wifexited($status)) {
                $exitCode = pcntl_wexitstatus($status);

                if ($exitCode !== 0) {
                    throw new ScanIsolatorException(sprintf(
                        'Isolate worker process exited with code %d.',
                        $exitCode,
                    ));
                }

                return new Scanned(true);
            }

            if (pcntl_wifsignaled($status)) {
                $signal = pcntl_wtermsig($status);
                throw new ScanIsolatorException(sprintf(
                    'Isolate worker process was terminated by signal %d.',
                    $signal,
                ));
            }

            throw new ScanIsolatorException('Isolate worker process ended unexpectedly.');
        }

        $this->runChildCallbackAndExit($callback);
    }

    private function runChildCallbackAndExit(callable $callback): never
    {
        try {
            $callback();
            exit(0);
        } catch (Throwable $throwable) {
            throw new ScanIsolatorException(
                'Isolate worker callback execution failed.',
                previous: $throwable,
            );
        }
    }
}
