<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Internal;

/**
 * Turns a Throwable into Sentry-shaped exception values (spec section 4):
 * frames oldest first, the error itself last in `exception.values`, its
 * `getPrevious()` causes before it.
 *
 * @internal
 */
final class Stacktrace
{
    private const MAX_FRAMES = 100;
    private const CONTEXT_LINES = 5;
    private const MAX_SOURCE_BYTES = 1_048_576;

    /** @var array<string,list<string>|null> */
    private array $sources = [];

    /**
     * @param list<string> $inAppExclude path fragments that mark a frame as not the app's
     */
    public function __construct(
        private readonly ?string $projectRoot,
        private readonly array $inAppExclude,
        private readonly bool $sourceContext = true,
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function exceptionValues(\Throwable $error): array
    {
        $out = [];
        $seen = [];
        for ($cur = $error, $i = 0; $cur !== null && $i < 5; $cur = $cur->getPrevious(), $i++) {
            $id = spl_object_id($cur);
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            $value = ['type' => get_class($cur), 'value' => $cur->getMessage()];
            $frames = $this->frames($cur->getTrace(), $cur->getFile(), $cur->getLine());
            if ($frames !== []) {
                $value['stacktrace'] = ['frames' => $frames];
            }
            array_unshift($out, $value);
        }

        return $out;
    }

    /**
     * PHP's trace lists, for each frame, the function that was *called* at
     * file:line. A Sentry frame is the function *containing* file:line, so the
     * function name comes from the next entry down.
     *
     * @param array<int,array<string,mixed>> $trace newest first, as getTrace() returns it
     * @return list<array<string,mixed>> oldest first
     */
    public function frames(array $trace, string $file, int $line): array
    {
        $frames = [];
        $count = count($trace);
        for ($i = 0; $i <= $count; $i++) {
            $fn = $trace[$i] ?? null;
            $function = $fn === null ? '{main}' : self::functionName($fn);
            $frames[] = $this->frame($file, $line, $function);
            if ($fn === null || count($frames) >= self::MAX_FRAMES) {
                break;
            }
            $file = is_string($fn['file'] ?? null) ? $fn['file'] : '[internal]';
            $line = is_int($fn['line'] ?? null) ? $fn['line'] : 0;
        }

        return array_reverse($frames);
    }

    /** @param array<string,mixed> $fn */
    private static function functionName(array $fn): string
    {
        $name = is_string($fn['function'] ?? null) ? $fn['function'] : '';
        if (isset($fn['class']) && is_string($fn['class'])) {
            $type = is_string($fn['type'] ?? null) ? $fn['type'] : '::';

            return $fn['class'] . $type . $name;
        }

        return $name;
    }

    /** @return array<string,mixed> */
    private function frame(string $abs, int $line, string $function): array
    {
        $frame = [
            'filename' => $this->relative($abs),
            'abs_path' => $abs,
            'function' => $function,
            'lineno' => $line,
            'in_app' => $this->inApp($abs),
        ];
        if (str_contains($function, '::') || str_contains($function, '->')) {
            $frame['module'] = (string) preg_replace('/(::|->).*$/', '', $function);
        }
        if ($this->sourceContext && $line > 0) {
            $lines = $this->source($abs);
            if ($lines !== null && isset($lines[$line - 1])) {
                $start = max(0, $line - 1 - self::CONTEXT_LINES);
                $frame['pre_context'] = array_map([self::class, 'clean'], array_slice($lines, $start, $line - 1 - $start));
                $frame['context_line'] = self::clean($lines[$line - 1]);
                $frame['post_context'] = array_map([self::class, 'clean'], array_slice($lines, $line, self::CONTEXT_LINES));
            }
        }

        return $frame;
    }

    private static function clean(string $line): string
    {
        // Source lines can hold secrets as easily as variables can.
        $line = rtrim($line, "\r\n");
        if (strlen($line) > 300) {
            $line = substr($line, 0, 297) . '...';
        }

        return Scrubber::string($line);
    }

    /** @return list<string>|null */
    private function source(string $abs): ?array
    {
        if (array_key_exists($abs, $this->sources)) {
            return $this->sources[$abs];
        }
        if (count($this->sources) > 50) {
            $this->sources = [];
        }
        $lines = null;
        if ($abs !== '' && $abs[0] !== '[' && @is_file($abs) && @is_readable($abs)) {
            $size = @filesize($abs);
            if ($size !== false && $size <= self::MAX_SOURCE_BYTES) {
                $read = @file($abs);
                $lines = $read === false ? null : $read;
            }
        }

        return $this->sources[$abs] = $lines;
    }

    private function relative(string $abs): string
    {
        if ($this->projectRoot !== null && $this->projectRoot !== '' && str_starts_with($abs, $this->projectRoot)) {
            return ltrim(substr($abs, strlen($this->projectRoot)), '/\\');
        }

        return $abs;
    }

    private function inApp(string $abs): bool
    {
        if ($abs === '' || $abs[0] === '[') {
            return false;
        }
        $norm = str_replace('\\', '/', $abs);
        foreach ($this->inAppExclude as $fragment) {
            if ($fragment !== '' && str_contains($norm, $fragment)) {
                return false;
            }
        }
        if ($this->projectRoot !== null && $this->projectRoot !== '') {
            return str_starts_with($abs, $this->projectRoot);
        }

        return true;
    }
}
