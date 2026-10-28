# super-kernel/scan-isolate

[![PHP ~8.4.0](https://img.shields.io/badge/PHP-~8.4.0-777BB4.svg?logo=php&logoColor=white)](#requirements)
[![License: MIT](https://img.shields.io/badge/License-MIT-22c55e.svg)](LICENSE)
[![GitHub Repository](https://img.shields.io/badge/GitHub-super--kernel%2Fscan--isolate-181717.svg?logo=github&logoColor=white)](https://github.com/super-kernel/scan-isolate)
[![GitHub Stars](https://img.shields.io/github/stars/super-kernel/scan-isolate?logo=github&color=f5b301)](https://github.com/super-kernel/scan-isolate/stargazers)

Process scan isolators for PHP 8.4.

Packagist package and source repository: `super-kernel/scan-isolate`.

This package provides a small `ScanIsolatorInterface` for scan work that may need to run in an isolated process while
keeping the public result model minimal.

## Features

- explicit scan isolator contract
- lightweight `ScannedInterface` result
- `NullScanIsolator` for already-isolated PHAR runtimes
- `PcntlScanIsolator` for fork-based isolation
- `ProcScanIsolator` for self-bootstrap isolation through `proc_open()`
- stdout and stderr forwarding in proc mode
- no custom worker entry script

## Requirements

- PHP `~8.4.0`
- `ext-json`
- `ext-pcntl` for `PcntlScanIsolator`
- CLI and `proc_open()` for `ProcScanIsolator`

## Installation

```bash
composer require super-kernel/scan-isolate
```

## Quick Start

```php
use SuperKernel\ScanIsolate\Executor\ProcScanIsolator;

$isolator = new ProcScanIsolator();

if (!$isolator->supports()) {
    throw new RuntimeException('Proc scan isolation is not available.');
}

$scanned = $isolator->execute(static function (): void {
    // scan logic runs in the isolated child process
});

assert($scanned->isScanned());

// current process continues only after the isolated scan completed successfully
```

## Contract

```php
namespace SuperKernel\ScanIsolate\Contract;

interface ScanIsolatorInterface
{
    public function supports(): bool;

    public function execute(callable $callback): ScannedInterface;
}
```

`ScannedInterface::isScanned()` tells you whether the scan workflow has completed successfully for the current call.

- `true`: the scan was completed successfully
- `false`: the scan has not been completed

## Choosing an Isolator

| Isolator            | Best fit                                                              | Child execution model                                 | Parent return    |
|---------------------|-----------------------------------------------------------------------|-------------------------------------------------------|------------------|
| `NullScanIsolator`  | Already running inside a PHAR-isolated context                        | no extra process                                      | `Scanned(true)` |
| `PcntlScanIsolator` | CLI runtime with `ext-pcntl` available                                | `pcntl_fork()`                                        | `Scanned(true)` |
| `ProcScanIsolator`  | CLI runtime where self-bootstrap via current entrypoint is acceptable | `proc_open()` + current `$_SERVER['SCRIPT_FILENAME']` | `Scanned(true)` |

## Isolators

### NullScanIsolator

Returns `new Scanned(true)` immediately.

Use it when the current runtime is already isolated, and you only want a consistent `ScanIsolatorInterface`.

### PcntlScanIsolator

Uses `pcntl_fork()` and `pcntl_waitpid()`.

- child process executes the callback and exits immediately
- parent process waits for child completion and returns `Scanned(true)`
- non-zero child exit raises `ScanIsolatorException`

### ProcScanIsolator

Starts a new PHP process with `PHP_BINARY` and the current `$_SERVER['SCRIPT_FILENAME']`.

The child process must reach the same `execute()` call naturally. There is no separate worker entry file.

- child process consumes the guard descriptor
- child process executes the callback
- child process writes a scan result token back to the parent
- child process exits immediately after the callback succeeds
- parent process forwards child stdout and stderr to the current output
- parent process returns `Scanned(true)` after the child exits successfully

If the current entry script does not reach the scan point, or if the child exits non-zero, `ScanIsolatorException` is
thrown.

## Usage

### PcntlScanIsolator

```php
use SuperKernel\ScanIsolate\Executor\PcntlScanIsolator;

$isolator = new PcntlScanIsolator();
$scanned = $isolator->execute(static function (): void {
    // scan in the forked child process
});

assert($scanned->isScanned());

// current process continues only after the child scan completed successfully
```

### ProcScanIsolator

```php
use SuperKernel\ScanIsolate\Executor\ProcScanIsolator;

$isolator = new ProcScanIsolator();
$scanned = $isolator->execute(static function (): void {
    // scan in the self-bootstrapped child process
});

assert($scanned->isScanned());

// current process continues only after the child scan completed successfully
```

## Notes

- `supports()` is a capability check, not a guarantee that `execute()` cannot fail at runtime.
- child processes do not return to the caller after a successful callback; they terminate.
- callback failures are surfaced as `ScanIsolatorException` in the parent process.
- `ProcScanIsolator` is designed for re-entering the current application entrypoint, not for launching a custom worker
  script.

## Testing

```bash
vendor/bin/phpunit
```
