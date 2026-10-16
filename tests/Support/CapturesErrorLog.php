<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Support;

/**
 * Redirects `error_log()` into a temp file for the duration of a callable, so a
 * test can assert what was reported (and keeps the test output clean).
 */
trait CapturesErrorLog
{
    /** Runs the callable with `error_log()` redirected to a temp file and returns what was written. */
    private function captureErrorLog(callable $run): string
    {
        $file = sys_get_temp_dir() . '/jardis-kernel-errorlog-' . uniqid('', true) . '.log';
        $previous = ini_set('error_log', $file);

        try {
            $run();
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $output = is_file($file) ? (string) file_get_contents($file) : '';
        @unlink($file);

        return $output;
    }
}
