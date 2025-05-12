<?php

declare(strict_types=1);

namespace Unknot\Scanner;

use FFI;
use Throwable;

/** Applies an OS limit before a worker receives source. */
final class WorkerMemoryLimit
{
    private const LIMIT = 268435456;
    private $windows = null;
    private $job = null;

    public function apply(): bool
    {
        try {
            if (PHP_OS_FAMILY === 'Linux') {
                return $this->applyLinux();
            }
            if (PHP_OS_FAMILY === 'Windows') {
                return $this->applyWindows();
            }
        } catch (Throwable $error) {
            return false;
        }
        return false;
    }

    public function __destruct()
    {
        if ($this->windows !== null && $this->job !== null) {
            $this->windows->CloseHandle($this->job);
        }
    }

    private function applyLinux(): bool
    {
        if (!function_exists('posix_setrlimit') || !function_exists('posix_getrlimit') || !defined('POSIX_RLIMIT_AS')) {
            return false;
        }
        $current = posix_getrlimit();
        if (!is_array($current)) {
            return false;
        }
        $limit = self::LIMIT;
        foreach (['soft totalmem', 'hard totalmem'] as $key) {
            $value = $current[$key] ?? null;
            if ($value === 'unlimited') {
                continue;
            }
            if (!is_int($value) || $value < 0) {
                return false;
            }
            $limit = min($limit, $value);
        }
        // A worker must not raise a tighter limit inherited from its parent.
        if (!posix_setrlimit(POSIX_RLIMIT_AS, $limit, $limit)) {
            return false;
        }
        $applied = posix_getrlimit();
        return is_array($applied) && ($applied['soft totalmem'] ?? null) === $limit
            && ($applied['hard totalmem'] ?? null) === $limit;
    }

    private function applyWindows(): bool
    {
        if (PHP_INT_SIZE !== 8 || !class_exists(FFI::class)) {
            return false;
        }
        $win = FFI::cdef(<<<'C'
typedef void *HANDLE;
typedef unsigned long DWORD;
typedef int BOOL;
typedef unsigned long long SIZE_T;
typedef long long LARGE_INTEGER;
typedef struct {
    LARGE_INTEGER PerProcessUserTimeLimit;
    LARGE_INTEGER PerJobUserTimeLimit;
    DWORD LimitFlags;
    SIZE_T MinimumWorkingSetSize;
    SIZE_T MaximumWorkingSetSize;
    DWORD ActiveProcessLimit;
    SIZE_T Affinity;
    DWORD PriorityClass;
    DWORD SchedulingClass;
} BASIC_LIMIT;
typedef struct {
    unsigned long long ReadOperationCount;
    unsigned long long WriteOperationCount;
    unsigned long long OtherOperationCount;
    unsigned long long ReadTransferCount;
    unsigned long long WriteTransferCount;
    unsigned long long OtherTransferCount;
} IO_COUNTERS;
typedef struct {
    BASIC_LIMIT BasicLimitInformation;
    IO_COUNTERS IoInfo;
    SIZE_T ProcessMemoryLimit;
    SIZE_T JobMemoryLimit;
    SIZE_T PeakProcessMemoryUsed;
    SIZE_T PeakJobMemoryUsed;
} EXTENDED_LIMIT;
HANDLE CreateJobObjectW(void *attributes, const unsigned short *name);
BOOL SetInformationJobObject(HANDLE job, int infoClass, void *info, DWORD length);
BOOL QueryInformationJobObject(HANDLE job, int infoClass, void *info, DWORD length, DWORD *returnedLength);
BOOL AssignProcessToJobObject(HANDLE job, HANDLE process);
HANDLE GetCurrentProcess(void);
BOOL CloseHandle(HANDLE handle);
C, 'kernel32.dll');
        $job = $win->CreateJobObjectW(null, null);
        if ($job === null || FFI::isNull($job)) {
            return false;
        }
        $this->windows = $win;
        $this->job = $job;
        $info = $win->new('EXTENDED_LIMIT');
        if (FFI::sizeof($info) !== 144) {
            return false;
        }
        $info->BasicLimitInformation->LimitFlags = 0x100; // JOB_OBJECT_LIMIT_PROCESS_MEMORY
        $info->ProcessMemoryLimit = self::LIMIT;
        if (!$win->SetInformationJobObject($job, 9, FFI::addr($info), FFI::sizeof($info))
            || !$win->AssignProcessToJobObject($job, $win->GetCurrentProcess())) {
            return false;
        }
        $verified = $win->new('EXTENDED_LIMIT');
        return (bool) $win->QueryInformationJobObject($job, 9, FFI::addr($verified), FFI::sizeof($verified), null)
            && ($verified->BasicLimitInformation->LimitFlags & 0x100) !== 0
            && $verified->ProcessMemoryLimit === self::LIMIT;
    }
}
