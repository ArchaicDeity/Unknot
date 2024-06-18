<?php

declare(strict_types=1);

use LegacyLens\Scanner\WorkerMemoryLimit;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_OS_FAMILY === 'Linux' && ($argv[1] ?? '') === '--inherited') {
    $limit = 192 * 1024 * 1024;
    if (!posix_setrlimit(POSIX_RLIMIT_AS, $limit, $limit)) {
        throw new RuntimeException('Could not set the inherited test limit.');
    }
    $guard = new WorkerMemoryLimit();
    $applied = $guard->apply();
    $limits = posix_getrlimit();
    if (!$applied || ($limits['soft totalmem'] ?? null) !== $limit || ($limits['hard totalmem'] ?? null) !== $limit) {
        throw new RuntimeException('The worker must preserve a stricter inherited limit.');
    }
    echo "Inherited limit preserved.\n";
    exit(0);
}

if (PHP_OS_FAMILY === 'Windows') {
    $win = FFI::cdef('void *VirtualAlloc(void *address, unsigned long long size, unsigned long type, unsigned long protect); int VirtualFree(void *address, unsigned long long size, unsigned long type);', 'kernel32.dll');
    $baseline = $win->VirtualAlloc(null, 300 * 1024 * 1024, 0x3000, 0x04);
    if ($baseline === null || FFI::isNull($baseline)) {
        throw new RuntimeException('The unconstrained allocation probe could not establish a baseline.');
    }
    $win->VirtualFree($baseline, 0, 0x8000);
}
$guard = new WorkerMemoryLimit();
if (!$guard->apply()) {
    throw new RuntimeException('Could not establish the OS memory limit.');
}
if (PHP_OS_FAMILY === 'Linux') {
    echo "OS limit applied\n";
    $allocation = str_repeat('x', 300 * 1024 * 1024);
    throw new RuntimeException('Allocation unexpectedly succeeded: ' . strlen($allocation));
}
$allocation = $win->VirtualAlloc(null, 300 * 1024 * 1024, 0x3000, 0x04);
if ($allocation !== null && !FFI::isNull($allocation)) {
    $win->VirtualFree($allocation, 0, 0x8000);
    throw new RuntimeException('The job limit did not reject the native allocation.');
}
echo "Windows native allocation rejected.\n";
