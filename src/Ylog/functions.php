<?php

declare(strict_types=1);

use Ylog\Logger;

if (!function_exists('ylog')) {
    /**
     * @return Logger
     */
    function ylog(): Logger
    {
        static $instance;
        return $instance ??= (new Logger())->bootstrap();
    }
}

if (!function_exists('vard')) {
    /**
     * @param mixed $value
     * @param int $maxDepth
     * @param int $maxElements
     * @param int $maxTotal
     */
    function vard(mixed $value, int $maxDepth = 3, int $maxElements = 10, int $maxTotal = 100): void
    {
        if ($maxDepth <= 0) {
            var_dump($value);
            return;
        }

        echo "{\n";
        $indent = str_repeat('  ', 3 - min(3, $maxDepth));

        if (is_array($value) || is_object($value)) {
            $items = is_array($value) ? $value : (array)$value;
            $count = 0;
            foreach ($items as $k => $v) {
                if ($count >= $maxElements || $maxTotal <= 0) break;
                echo "$indent$k => ";
                vard($v, $maxDepth - 1, $maxElements, $maxTotal - 1);
                $count++;
            }
        } else {
            var_dump($value);
        }

        echo "$indent}\n";
    }
}

if (!function_exists('dump')) {
    function dump(mixed ...$args): void
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $frame = $bt[0] ?? [];
        $file = str_replace('/var/www/html/my/', '/', $frame['file'] ?? '?');
        $line = $frame['line'] ?? '?';
        $func = $frame['function'] ?? '';

        echo "[$func] $file:$line\n";
        foreach ($args as $arg) {
            var_dump($arg);
        }
    }
}

if (!function_exists('dd')) {
    function dd(mixed ...$args): never
    {
        echo "<pre>";
        dump(...$args);
        exit(1);
    }
}
