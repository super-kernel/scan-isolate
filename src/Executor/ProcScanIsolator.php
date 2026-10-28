<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolate\Executor;

use SuperKernel\ScanIsolate\Contract\ScanIsolatorInterface;
use SuperKernel\ScanIsolate\Contract\ScannedInterface;
use SuperKernel\ScanIsolate\Exception\ScanIsolatorException;
use SuperKernel\ScanIsolate\Internal\Scanned;
use Throwable;
use function array_is_list;
use function array_slice;
use function fclose;
use function fopen;
use function fread;
use function function_exists;
use function fwrite;
use function getcwd;
use function is_array;
use function is_file;
use function is_readable;
use function is_resource;
use function is_string;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function stream_set_blocking;
use function usleep;
use const PHP_BINARY;
use const PHP_SAPI;

final readonly class ProcScanIsolator implements ScanIsolatorInterface
{
    private const int GUARD_DESCRIPTOR_INDEX = 5;
    private const int RESULT_DESCRIPTOR_INDEX = 6;
    private const string GUARD_TOKEN = "1\n";
    private const string RESULT_TOKEN = "1\n";

    private string $phpBinary;

    public function __construct()
    {
        $this->phpBinary = PHP_BINARY;
    }

    public function supports(): bool
    {
        if (PHP_SAPI !== 'cli') {
            return false;
        }

        if (!function_exists('proc_open')) {
            return false;
        }

        $entryFile = $_SERVER['SCRIPT_FILENAME'] ?? null;

        if (!is_string($entryFile) || $entryFile === '') {
            return false;
        }

        if (!is_file($entryFile)) {
            return false;
        }

        return is_readable($entryFile);
    }

    public function execute(callable $callback): ScannedInterface
    {
        if ($this->isChildProcessActive()) {
            $this->runChildCallbackAndExit($callback);
        }

        if (PHP_SAPI !== 'cli') {
            throw new ScanIsolatorException(sprintf(
                '%s requires CLI execution.',
                self::class,
            ));
        }

        if (!function_exists('proc_open')) {
            throw new ScanIsolatorException('The proc_open function is required for ProcScanIsolator.');
        }

        $entryFile = $this->resolveEntryFile();
        $command = $this->buildCommand($entryFile);
        $descriptorSpecification = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
            self::GUARD_DESCRIPTOR_INDEX => ['pipe', 'r'],
            self::RESULT_DESCRIPTOR_INDEX => ['pipe', 'w'],
        ];

        $process = proc_open(
            command: $command,
            descriptor_spec: $descriptorSpecification,
            pipes: $pipes,
            cwd: getcwd() ?: null,
        );

        if (!is_resource($process)) {
            throw new ScanIsolatorException('Unable to start proc isolate worker process.');
        }

        $stdoutStream = fopen('php://stdout', 'wb');
        $stderrStream = fopen('php://stderr', 'wb');

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        stream_set_blocking($pipes[self::RESULT_DESCRIPTOR_INDEX], false);
        fwrite($pipes[self::GUARD_DESCRIPTOR_INDEX], self::GUARD_TOKEN);
        fclose($pipes[self::GUARD_DESCRIPTOR_INDEX]);

        $resultToken = '';

        while (true) {
            $status = proc_get_status($process);
            $this->pumpStream($pipes[1], $stdoutStream);
            $this->pumpStream($pipes[2], $stderrStream);
            $resultToken .= stream_get_contents($pipes[self::RESULT_DESCRIPTOR_INDEX]) ?: '';

            if (!$status['running']) {
                break;
            }

            usleep(10_000);
        }

        $this->pumpStream($pipes[1], $stdoutStream);
        $this->pumpStream($pipes[2], $stderrStream);
        $resultToken .= stream_get_contents($pipes[self::RESULT_DESCRIPTOR_INDEX]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);
        fclose($pipes[self::RESULT_DESCRIPTOR_INDEX]);

        if (is_resource($stdoutStream)) {
            fclose($stdoutStream);
        }

        if (is_resource($stderrStream)) {
            fclose($stderrStream);
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new ScanIsolatorException(sprintf(
                'Isolate worker process exited with code %d.',
                $exitCode,
            ));
        }

        if ($resultToken !== self::RESULT_TOKEN) {
            throw new ScanIsolatorException('Current entry script did not produce a proc scan result token.');
        }

        return new Scanned(true);
    }

    private function runChildCallbackAndExit(callable $callback): never
    {
        try {
            $callback();
            $this->writeResultToken();
            exit(0);
        } catch (Throwable $throwable) {
            throw new ScanIsolatorException(
                'Isolate worker callback execution failed.',
                previous: $throwable,
            );
        }
    }

    private function resolveEntryFile(): string
    {
        $entryFile = $_SERVER['SCRIPT_FILENAME'] ?? null;

        if (!is_string($entryFile) || $entryFile === '') {
            throw new ScanIsolatorException(sprintf(
                '%s requires $_SERVER[\'SCRIPT_FILENAME\'] to resolve the current entry file.',
                self::class,
            ));
        }

        if (!is_file($entryFile)) {
            throw new ScanIsolatorException(sprintf(
                'Current script file "%s" does not exist.',
                $entryFile,
            ));
        }

        if (!is_readable($entryFile)) {
            throw new ScanIsolatorException(sprintf(
                'Current script file "%s" is not readable.',
                $entryFile,
            ));
        }

        return $entryFile;
    }

    /**
     * @return list<string>
     */
    private function buildCommand(string $entryFile): array
    {
        $command = [$this->phpBinary, $entryFile];
        $argv = $_SERVER['argv'] ?? [];

        if (is_array($argv) && array_is_list($argv)) {
            foreach (array_slice($argv, 1) as $argument) {
                if (is_string($argument) && $argument !== '') {
                    $command[] = $argument;
                }
            }
        }

        return $command;
    }

    /**
     * @param resource|false|null $source
     * @param resource|false $target
     */
    private function pumpStream(mixed $source, mixed $target): void
    {
        if (!is_resource($source) || !is_resource($target)) {
            return;
        }

        $chunk = stream_get_contents($source);

        if ($chunk === false || $chunk === '') {
            return;
        }

        fwrite($target, $chunk);
    }

    private function isChildProcessActive(): bool
    {
        $guardStream = @fopen('php://fd/' . self::GUARD_DESCRIPTOR_INDEX, 'rb');

        if (!is_resource($guardStream)) {
            return false;
        }

        $token = fread($guardStream, 2);
        fclose($guardStream);

        return $token === self::GUARD_TOKEN;
    }

    private function writeResultToken(): void
    {
        $resultStream = @fopen('php://fd/' . self::RESULT_DESCRIPTOR_INDEX, 'wb');

        if (!is_resource($resultStream)) {
            return;
        }

        fwrite($resultStream, self::RESULT_TOKEN);
        fclose($resultStream);
    }
}
