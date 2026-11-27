<?php

namespace Cinderwatch\WordPress\Errors;

use Cinderwatch\WordPress\Cinder;
use Cinderwatch\WordPress\EntryPoint;
use Cinderwatch\WordPress\ScheduleMonitor\WpCronMonitor;
use ErrorException;
use Throwable;

class ErrorHandler
{
    private const FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    private mixed $previousExceptionHandler = null;

    private mixed $previousErrorHandler = null;

    private bool $registered = false;

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        $this->previousExceptionHandler = set_exception_handler($this->handleException(...));
        $this->previousErrorHandler = set_error_handler($this->handleError(...));

        register_shutdown_function($this->handleShutdown(...));
    }

    public function handleException(Throwable $throwable): void
    {
        $this->report($throwable);

        if (is_callable($this->previousExceptionHandler)) {
            ($this->previousExceptionHandler)($throwable);
        }
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            return false;
        }

        $this->handleException(new ErrorException($message, 0, $severity, $file, $line));

        if (is_callable($this->previousErrorHandler)) {
            return (bool) ($this->previousErrorHandler)($severity, $message, $file, $line);
        }

        return false;
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || ! in_array($error['type'], self::FATAL_ERRORS, true)) {
            return;
        }

        $this->report(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
    }

    private function report(Throwable $throwable): void
    {
        if (Cinder::client()->config()->key === '') {
            return;
        }

        Cinder::report($throwable);

        if (EntryPoint::isCron()) {
            WpCronMonitor::instance()->reportCurrentHookFailed($throwable->getMessage());
        }
    }
}
