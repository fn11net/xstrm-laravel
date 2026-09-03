<?php

namespace Xstrm\Xstrm\Errors;

use Throwable;

/**
 * Turns a stack trace into frames, innermost last, with in-app frames flagged
 * and a few lines of source around each one.
 *
 * Source context is read from disk here, in the customer's app, because it is
 * the only place the code exists — by the time an error reaches the dashboard
 * the file is on a machine that has never seen it.
 */
class Frames
{
    /** Reading context for every frame of a deep trace is not worth the IO. */
    private const CONTEXT_LINES = 3;

    private const MAX_FRAMES = 50;

    public function __construct(protected ?string $basePath = null) {}

    public function for(Throwable $e): array
    {
        $frames = [$this->frame($e->getFile(), $e->getLine(), null, null)];

        foreach (array_slice($e->getTrace(), 0, self::MAX_FRAMES) as $trace) {
            $frames[] = $this->frame(
                $trace['file'] ?? null,
                $trace['line'] ?? null,
                $trace['function'] ?? null,
                $trace['class'] ?? null,
            );
        }

        // Innermost last is the order every stack-trace reader expects: you
        // read down to the line that actually threw.
        return array_reverse($frames);
    }

    protected function frame(?string $file, ?int $line, ?string $function, ?string $class): array
    {
        $inApp = $this->isInApp($file);

        return array_filter([
            'file' => $this->relative($file),
            'line' => $line,
            'function' => $class ? $class.'::'.$function : $function,
            'in_app' => $inApp,
            // Only in-app frames get source context. Nobody debugs by reading
            // the inside of a vendor package, and it doubles the payload.
            'context' => $inApp ? $this->context($file, $line) : null,
        ], fn ($value) => $value !== null);
    }

    protected function isInApp(?string $file): bool
    {
        if ($file === null || $this->base() === null) {
            return false;
        }

        return str_starts_with($file, $this->base())
            && ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);
    }

    /** @return array<int, string>|null line number => source, for display */
    protected function context(?string $file, ?int $line): ?array
    {
        if ($file === null || $line === null || ! is_readable($file)) {
            return null;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return null;
        }

        $context = [];
        $from = max(1, $line - self::CONTEXT_LINES);
        $to = min(count($lines), $line + self::CONTEXT_LINES);

        for ($i = $from; $i <= $to; $i++) {
            // Long minified or generated lines would otherwise dominate the
            // payload and render unreadably.
            $context[$i] = mb_substr((string) $lines[$i - 1], 0, 300);
        }

        return $context;
    }

    /** Paths are relative so they read the same across deploys and machines. */
    protected function relative(?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $base = $this->base();

        return $base && str_starts_with($file, $base)
            ? substr($file, strlen($base))
            : $file;
    }

    protected function base(): ?string
    {
        if ($this->basePath !== null) {
            return rtrim($this->basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        }

        try {
            return rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        } catch (Throwable) {
            return null;
        }
    }
}
