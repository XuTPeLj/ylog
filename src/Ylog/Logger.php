<?php

declare(strict_types=1);

namespace Ylog;

use Closure;
use DateTimeImmutable;
use ErrorException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * @psalm-type LogLevelType = 'emergency'|'alert'|'critical'|'error'|'warning'|'notice'|'info'|'debug'
 * @psalm-type FormatType = 'min'|'debug'|'proc'
 * @psalm-type DataFormatType = 'echo'|'easy'|'print_r'|'php'
 * @psalm-type LogFilterSpec = array{0: int, 1: int, 2: string}|array{0: int, 1: string}
 */
final class Logger implements LoggerInterface
{
    private const DEFAULT_DOCUMENT_ROOT = '/var/www/';
    private const LOG_FILE_EXTENSION = '.log';
    private const CSV_FILE_EXTENSION = '.csv';

    private Config $config;
    private ?string $currentSection = 'all';
    private array $logFiles = [];
    private array $logBuffers = [];
    private ?float $startTime = null;
    private ?float $stepTime = null;
    private int $stepCounter = 0;
    private ProfileStats $stats;
    private ?string $lastTrace = null;
    private string $argumentsString = '';
    private bool $isShutdownRegistered = false;
    private bool $isErrorHandlerRegistered = false;

    /**
     * @var array<string, mixed>
     */
    private array $testVars = [];

    /**
     * @var array<string, array{
     *     start?: string,
     *     success?: string,
     *     error?: string,
     *     startPhp?: Closure|null,
     *     endPhp?: (Closure(): bool)|null,
     *     replace?: (Closure(mixed): mixed)|null,
     *     not?: (Closure(mixed): bool)|null,
     *     text?: string
     * }>
     */
    private array $testCases = [];

    /**
     * @param Config|null $config
     */
    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
        $this->stats = new ProfileStats();
    }

    /**
     * Bootstrap error handler, shutdown handler, and initial log section.
     * Must be called explicitly — no side effects on file inclusion.
     */
    public function bootstrap(): self
    {
        if (!$this->isErrorHandlerRegistered && !$this->shouldBlock()) {
            $this->registerErrorHandler();
        }
        if (!$this->isShutdownRegistered && !$this->shouldBlock()) {
            register_shutdown_function([$this, 'shutdown']);
            $this->isShutdownRegistered = true;
        }

        if (!$this->config->deferServerInfo) {
            $this->section('public');
            $this->startServerInfo();
            $this->startTestLog($this->getRequestUri());
        }

        return $this;
    }

    /**
     * Disable all logging and output.
     */
    public function disable(): self
    {
        $this->config->enableWrite = false;
        $this->config->enableOutput = false;
        return $this;
    }

    /**
     * Enable writing to log files (default: on).
     */
    public function enableWrite(bool $enable = true): self
    {
        $this->config->enableWrite = $enable;
        return $this;
    }

    /**
     * Enable output to stdout (default: off).
     */
    public function enableOutput(bool $enable = true): self
    {
        $this->config->enableOutput = $enable;
        return $this;
    }

    /**
     * Set current log section (creates directory if needed).
     *
     * @param string $name Section name or directory name
     */
    public function section(string $name): self
    {
        if ($this->shouldBlock()) {
            return $this;
        }

        $this->currentSection = $name;

        if ($this->config->createNewSection) {
            $this->config->createNewSection = false;

            $basePath = $this->resolveBasePath();
            $logDir = $this->config->useNameAsDir
                ? "$basePath/$name"
                : "$basePath/" . $this->generateTimestampedDirName();

            $this->ensureDirectoryExists($logDir);
            $this->config->logDirectory = $logDir;
            $this->config->logFilePrefix = "$logDir/";

            if (!$this->config->useNameAsDir) {
                $symlinkPath = "$basePath/$name";
                if (file_exists($symlinkPath)) {
                    unlink($symlinkPath);
                }
                // Do not force symlink — optional & safe
                if (is_link($symlinkPath) === false && function_exists('symlink')) {
                    @symlink($logDir, $symlinkPath);
                }
            }
        }

        $this->ensureSectionRegistered($name);
        return $this;
    }

    /**
     * Add a log file (without switching current section).
     */
    public function addFile(string $fileName): self
    {
        $this->ensureSectionRegistered($fileName);
        return $this;
    }

    /**
     * Remove a log file.
     */
    public function removeFile(string $fileName): self
    {
        unset($this->logFiles[$fileName]);
        return $this;
    }

    /**
     * Log a message with optional context.
     *
     * @param LogLevelType $level
     * @param string|Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->write([$level, $message, $context]);
    }

    /**
     * Log info message.
     *
     * @param string|Stringable $message
     * @param array<mixed> $context
     */
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * Log debug message.
     *
     * @param string|Stringable $message
     * @param array<mixed> $context
     */
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Log error message.
     *
     * @param string|Stringable $message
     * @param array<mixed> $context
     */
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * Generic write method — entry point for `_w`, `_wp`, `_aw`, etc.
     *
     * @param list<mixed> $args
     * @return mixed Last argument (for chaining)
     */
    public function write(array $args)
    {
        if ($this->shouldBlock()) {
            return $this->getLastArgument($args);
        }

        $this->captureStackTrace();
        $this->argumentsString = $this->formatData($args);

        $this->stepCounter++;
        $now = microtime(true);

        if ($this->startTime === null) {
            $this->startTime = $now;
        }
        if ($this->stepTime === null) {
            $this->stepTime = $now;
        }

        $stepDuration = $now - $this->stepTime;
        $totalDuration = $now - $this->startTime;

        if ($this->config->enableTimeByName && $this->config->currentMethod !== null) {
            $this->stats->recordDuration($this->config->currentMethod, $stepDuration);
        }

        $formatted = $this->formatMessage(
            $this->argumentsString,
            $this->lastTrace,
            $this->formatDuration($stepDuration),
            $this->formatDuration($totalDuration),
            $stepDuration,
            $totalDuration,
            ['methodName' => $this->config->currentMethod]
        );

        $this->writeToFiles($formatted . "\n");
        $this->stepTime = $now;

        return $this->getLastArgument($args);
    }

    /**
     * Write to log files (buffered).
     *
     * @param string $content
     */
    private function writeToFiles(string $content): void
    {
        foreach (array_keys($this->logFiles) as $section) {
            $key = $this->getLogFilePath($section);
            if (!isset($this->logBuffers[$key])) {
                $this->logBuffers[$key] = '';
            }
            $this->logBuffers[$key] .= $content;
        }

        if ($this->config->enableOutput) {
            echo $content;
        }
    }

    /**
     * Flush all buffered logs to disk.
     */
    private function flushBuffers(): void
    {
        foreach ($this->logBuffers as $filePath => $content) {
            if ($content === '') {
                continue;
            }
            $dir = dirname($filePath);
            $this->ensureDirectoryExists($dir);
            file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX);
            @chmod($filePath, 0644);
        }
        $this->logBuffers = [];
    }

    /**
     * Capture current stack trace and update stats.
     */
    private function captureStackTrace(): void
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->config->stackDepth + 5);
        $lines = [];

        $this->stats->clearFieldWidths();

        foreach ($backtrace as $index => $frame) {
            if ($index < $this->config->stackDepth) {
                continue;
            }

            $file = $frame['file'] ?? '[unknown]';
            $line = $frame['line'] ?? 0;
            $func = $frame['function'] ?? '';
            $type = $frame['type'] ?? '';

            $fileClean = $this->cleanFilePath($file);
            $fileName = basename($file);

            if ($this->config->skipVendor && str_starts_with($fileClean, 'vendor/')) {
                continue;
            }

            $this->stats->incrementFunctionCall("[$func]$fileClean");
            $this->stats->incrementFileCall($fileClean);

            $nextFrame = $backtrace[$index + 1] ?? [];
            $callerFunc = $nextFrame['function'] ?? '';
            $callerType = $nextFrame['type'] ?? '';

            $data = [
                'nfile' => $fileName,
                ':' => ':',
                'n' => (string)$line,
                '[' => '[',
                'type' => $callerType,
                'function' => $callerFunc,
                ']' => ']',
                'file' => "$fileClean:$line",
            ];

            $lines[] = $this->formatFields($data, [
                'nfile' => 'right',
                'n' => 'left',
                'type' => 'right',
                'function' => 'left',
                'file' => 'left',
            ]);
        }

        $this->lastTrace = implode("\n", $lines);
    }

    /**
     * Format message according to current format type.
     *
     * @param string $argsStr
     * @param string|null $trace
     * @param string $stepTimeStr
     * @param string $totalTimeStr
     * @param float $stepTime
     * @param float $totalTime
     * @param array<string, mixed> $params
     * @return string
     */
    private function formatMessage(
        string $argsStr,
        ?string $trace,
        string $stepTimeStr,
        string $totalTimeStr,
        float $stepTime,
        float $totalTime,
        array $params
    ): string {
        $methodName = $params['methodName'] ?? '';

        return match ($this->config->formatType) {
            'debug' => "[$methodName][$argsStr][$trace]",
            'min' => "[$methodName][{$this->extractFileAndLine($trace)}]\n$argsStr",
            'proc' => $this->config->enableTimeByName && $methodName
                ? "[$stepTimeStr][{$this->formatDuration($this->stats->getTotalDuration($methodName))}][$argsStr]"
                : "[proc_" . getmypid() . "_][$stepTimeStr][$totalTimeStr]$trace[$argsStr]",
            default => "[$methodName][{$this->extractFileAndLine($trace)}][$stepTimeStr][$totalTimeStr][$argsStr]",
        };
    }

    /**
     * Extract `file:line` from trace (for 'min' format).
     */
    private function extractFileAndLine(?string $trace): string
    {
        if (!$trace) {
            return '?:0';
        }
        $firstLine = strtok($trace, "\n");
        if (preg_match('/^([^:]+):(\d+)/', $firstLine, $m)) {
            return "{$m[1]}:{$m[2]}";
        }
        return '?:0';
    }

    /**
     * Format data using current strategy.
     *
     * @param list<mixed> $data
     * @return string
     */
    private function formatData(array $data): string
    {
        return match ($this->config->dataFormat) {
            'echo' => $this->formatEcho($data),
            'easy' => $this->formatArrayKey($data),
            'print_r' => $this->formatPrintR($data),
            'php' => $this->formatPhp($data),
            default => $this->formatEcho($data),
        };
    }

    /**
     * @param list<mixed> $data
     * @return string
     */
    private function formatEcho(array $data): string
    {
        return implode(', ', array_map([$this, 'valueToString'], $data));
    }

    /**
     * @param list<mixed> $data
     * @return string
     */
    private function formatPrintR(array $data): string
    {
        return print_r($data, true);
    }

    /**
     * @param list<mixed> $data
     * @return string
     */
    private function formatArrayKey(array $data, string $indent = ''): string
    {
        $parts = [];
        foreach ($data as $key => $value) {
            $keyStr = is_int($key) ? '' : $this->valueToString($key) . ' => ';
            $valStr = $this->valueToString($value, $indent . '    ');
            $parts[] = "$indent    {$keyStr}{$valStr}";
        }
        return "array(\n" . implode(",\n", $parts) . ($parts ? "\n$indent" : '') . ')';
    }

    /**
     * @param list<mixed> $data
     * @return string
     */
    private function formatPhp(array $data): string
    {
        $parts = [];
        foreach ($data as $key => $value) {
            $keyStr = is_int($key) ? '' : $this->valueToPhp($key) . ' => ';
            $valStr = $this->valueToPhp($value);
            $parts[] = "{$keyStr}{$valStr}";
        }
        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * Convert value to string for echo-style output.
     *
     * @param mixed $value
     * @param string $indent
     * @return string
     */
    private function valueToString(mixed $value, string $indent = ''): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_string($value) => $value,
            is_numeric($value) => (string)$value,
            is_array($value) => '[' . implode(', ', array_map(fn ($v) => $this->valueToString($v, $indent), $value)) . ']',
            is_object($value) => $value instanceof DateTimeImmutable
                ? $value->format('Y-m-d H:i:s.uP')
                : 'object(' . get_class($value) . ')',
            default => gettype($value),
        };
    }

    /**
     * Convert value to PHP-literal representation.
     *
     * @param mixed $value
     * @return string
     */
    private function valueToPhp(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_string($value) => "'" . $this->escapeString($value) . "'",
            is_float($value) => str_replace(',', '.', (string)$value),
            is_int($value) => (string)$value,
            is_array($value) => $this->formatPhp($value),
            is_object($value) => $value instanceof DateTimeImmutable
                ? 'new \DateTimeImmutable(\'' . $value->format('Y-m-d H:i:s.uP') . '\')'
                : '/* object */',
            default => '/* ' . gettype($value) . ' */',
        };
    }

    /**
     * Escape string for PHP literal.
     */
    private function escapeString(string $str): string
    {
        return str_replace(
            ["\\", "'", "\0"],
            ["\\\\", "\\'", '\\0'],
            $str
        );
    }

    /**
     * Format duration in human-readable form.
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $parts = [];
        $int = (int)$seconds;
        $frac = $seconds - $int;

        $years = intdiv($int, 31536000);
        $int %= 31536000;
        $months = intdiv($int, 2592000);
        $int %= 2592000;
        $days = intdiv($int, 86400);
        $int %= 86400;
        $hours = intdiv($int, 3600);
        $int %= 3600;
        $minutes = intdiv($int, 60);
        $secondsInt = $int;

        if ($years) $parts[] = "{$years}y";
        if ($months) $parts[] = "{$months}mo";
        if ($days) $parts[] = "{$days}d";
        if ($hours) $parts[] = "{$hours}h";
        if ($minutes) $parts[] = "{$minutes}m";
        if ($secondsInt) $parts[] = "{$secondsInt}s";
        if ($frac > 0 && count($parts) < 2) {
            $parts[] = sprintf('%.3fms', $frac * 1000);
        }

        return implode(' ', $parts) ?: '0s';
    }

    /**
     * Format fields with alignment.
     *
     * @param array<string, string> $values
     * @param array<string, 'left'|'right'> $directions
     * @return string
     */
    private function formatFields(array $values, array $directions): string
    {
        $result = '';
        $keys = array_keys($values);
        $lastKey = end($keys);

        foreach ($values as $key => $value) {
            if ($key === $lastKey && ($directions[$key] ?? null) === 'left') {
                $result .= $value;
            } else {
                $width = $this->stats->getFieldWidth($key, $value);
                $padding = str_repeat(' ', max(0, $width - mb_strlen($value)));
                $result .= ($directions[$key] ?? 'right') === 'right'
                    ? $padding . $value
                    : $value . $padding;
            }
        }

        return $result;
    }

    /**
     * Write test log (appends to `log_test_y.log`).
     *
     * @param string $message
     */
    public function testLog(string $message): void
    {
        $path = __DIR__ . '/../log_test_y.log';
        $dir = dirname($path);
        $this->ensureDirectoryExists($dir);
        file_put_contents($path, $message . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Start test logging for given URI.
     */
    public function startTestLog(string $uri): void
    {
        $this->loadTestCases();
        $test = $this->findTestCase($uri);

        if (!$test) {
            return;
        }

        $this->testCases['_current'] = $test;

        if (isset($test['startPhp']) && $test['startPhp'] instanceof Closure) {
            $test['startPhp']();
        }

        if (isset($test['start'])) {
            $this->testLog($this->replaceTestVars($test['start']));
        }
    }

    /**
     * End test logging.
     */
    public function endTestLog(): void
    {
        $test = $this->testCases['_current'] ?? null;
        if (!$test) {
            return;
        }

        if (isset($test['endPhp']) && $test['endPhp'] instanceof Closure) {
            $success = $test['endPhp']();
            $text = $success && isset($test['success']) ? $test['success'] : ($test['error'] ?? '');
            if ($text) {
                $this->testLog($this->replaceTestVars($text));
            }
        }
    }

    /**
     * Set variable for test interpolation.
     *
     * @param string $name
     * @param mixed $value
     */
    public function setTestVar(string $name, mixed $value): self
    {
        if (is_array($value)) {
            $value = array_diff_key($value, array_flip([
                'oauth_token', 'token', 'ip', 'expires', 'expires_at', 'created_at',
            ]));
        }
        $this->testVars[$name] = $this->valueToString($value);
        return $this;
    }

    /**
     * Generic test logging by code.
     *
     * @param string $code
     * @param mixed $message
     */
    public function test(string $code, mixed $message = null): void
    {
        $this->loadTestCases();

        if (!isset($this->testCases[$code])) {
            return;
        }

        $test = $this->testCases[$code];

        if (isset($test['replace']) && $test['replace'] instanceof Closure) {
            $message = $test['replace']($message);
        }

        if (isset($test['not']) && $test['not'] instanceof Closure && $test['not']($message)) {
            return;
        }

        $text = $test['text'] ?? $test;
        $this->testLog($this->replaceTestVars(
            is_string($text) ? $text : '',
            ['message' => $this->valueToString($message)]
        ));
    }

    /**
     * Replace {{var}} placeholders.
     *
     * @param string $template
     * @param array<string, mixed> $extra
     * @return string
     */
    private function replaceTestVars(string $template, array $extra = []): string
    {
        $vars = array_merge($this->testVars, $extra);
        foreach ($vars as $key => $value) {
            $template = str_replace("{{$key}}", (string)$value, $template);
        }
        return $template;
    }

    /**
     * Load test cases from `log_test_y.php`.
     */
    private function loadTestCases(): void
    {
        if ($this->testCases !== []) {
            return;
        }

        $path = __DIR__ . '/../log_test_y.php';
        if (!file_exists($path)) {
            return;
        }

        $cases = (include $path);
        if (!is_array($cases)) {
            return;
        }

        $this->testCases = $cases;
    }

    /**
     * Find matching test case by URI.
     *
     * @param string $uri
     * @return array|null
     */
    private function findTestCase(string $uri): ?array
    {
        foreach ($this->testCases as $pattern => $test) {
            if (!is_string($pattern) || !is_array($test)) {
                continue;
            }

            if ($pattern === '') {
                continue;
            }

            $firstChar = $pattern[0];

            if ($firstChar === '/') {
                if ($uri === $pattern) {
                    return $test;
                }
            } elseif (in_array($firstChar, ['#', '|', '(', '[', '^'], true)) {
                $regex = $firstChar === '#' ? $pattern : "#{$pattern}#";
                if (preg_match($regex, $uri)) {
                    return $test;
                }
            } else {
                // prefix match (but correct condition — was `!== 1`)
                if (str_starts_with($uri, $pattern)) {
                    return $test;
                }
            }
        }

        return null;
    }

    /**
     * Generate timestamped directory name.
     */
    private function generateTimestampedDirName(): string
    {
        $now = new \DateTimeImmutable();
        $datePart = $now->format('Y_m_d__H');
        $timePart = $now->format('i_s');
        $counter = (6000 - (int)$now->format('is')) % 10000;
        $uriSafe = $this->sanitizeFileName($this->getRequestUri());

        return (string)(2025123124 - (int)$now->format('YmdH'))
            . "_{$datePart}/"
            . sprintf('%04d', $counter)
            . "__{$timePart}_{$uriSafe}";
    }

    /**
     * Sanitize file/dir name.
     */
    private function sanitizeFileName(string $name, int $limit = 120): string
    {
        $clean = preg_replace('/[^a-z0-9_.-]/i', '_', $name);
        $clean = trim($clean, '_');

        if (mb_strlen($clean) <= $limit) {
            return $clean;
        }

        $head = mb_substr($clean, 0, $limit - 15);
        $tail = mb_substr($clean, -14);
        return $head . '.' . $tail;
    }

    /**
     * Get current request URI (HTTP or CLI).
     */
    private function getRequestUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri === '') {
            $uri = $_SERVER['SCRIPT_FILENAME'] ?? 'cli';
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $action = $_REQUEST['action'] ?? '';
        $argv1 = $_SERVER['argv'][1] ?? '';
        $argv2 = $_SERVER['argv'][2] ?? '';
        $argv3 = $_SERVER['argv'][3] ?? '';

        return $uri . '_' . $method . $action . $argv1 . $argv2 . $argv3;
    }

    /**
     * Clean file path (remove DOCUMENT_ROOT).
     */
    private function cleanFilePath(string $path): string
    {
        $docRoot = $this->config->documentRoot;
        if (str_starts_with($path, $docRoot)) {
            $path = substr($path, strlen($docRoot));
        }
        if (($_SERVER['DOCUMENT_ROOT'] ?? '') && str_starts_with($path, $_SERVER['DOCUMENT_ROOT'])) {
            $path = substr($path, strlen($_SERVER['DOCUMENT_ROOT']));
        }
        return ltrim($path, '/');
    }

    /**
     * Ensure log section is registered.
     */
    private function ensureSectionRegistered(string $section): void
    {
        if (!isset($this->logFiles[$section])) {
            $this->logFiles[$section] = true;
        }
    }

    /**
     * Get full log file path for section.
     */
    private function getLogFilePath(string $section): string
    {
        $ext = str_ends_with($section, '.csv') ? '' : self::LOG_FILE_EXTENSION;
        return $this->config->logFilePrefix . $section . $ext;
    }

    /**
     * Ensure directory exists (safe mkdir).
     */
    private function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create directory: $dir");
            }
        }
    }

    /**
     * Resolve base path (absolute).
     */
    private function resolveBasePath(): string
    {
        $dir = $this->config->basePath;
        if ($dir === '') {
            $dir = __DIR__ . '/logs';
        } elseif ($dir[0] !== '/') {
            $dir = __DIR__ . '/' . $dir;
        }
        return $dir;
    }

    /**
     * Write server info at start.
     */
    public function startServerInfo(): void
    {
        $this->writeFile('start_requestURI', $this->getRequestUri() . "\n");
        $this->writeFile('start_requests', print_r($_REQUEST, true) . "\n");
        $this->writeFile('start_server', print_r($_SERVER, true) . "\n");
    }

    /**
     * Write raw content to file (immediate).
     *
     * @param string $name
     * @param string $content
     * @param string $suffix
     */
    public function writeFile(string $name, string $content, string $suffix = ''): void
    {
        if ($this->shouldBlock()) {
            return;
        }

        $path = $this->config->logFilePrefix . $name . $suffix;
        $dir = dirname($path);
        $this->ensureDirectoryExists($dir);
        file_put_contents($path, $content, FILE_APPEND | LOCK_EX);
        @chmod($path, 0644);
    }

    /**
     * Shutdown handler — flush, stats, env dump.
     */
    public function shutdown(): void
    {
        $this->flushBuffers();
        $this->writeStats();
        $this->endTestLog();

        $totalTime = microtime(true) - ($this->startTime ?? 0);
        $this->writeFile('end', json_encode([
                'time_all_sys' => $totalTime,
                'time_all_str' => $this->formatDuration($totalTime),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Write profiling stats.
     */
    private function writeStats(): void
    {
        usort($this->stats->getTimeSteps(), static fn ($a, $b) => current($b) <=> current($a));

        $this->writeFile('timeStepSave', "[\n", '.js');
        foreach ($this->stats->getTimeSteps() as $item) {
            $step = key($item);
            $time = current($item);
            $jsStep = str_replace('`', '\`', str_replace('\\', '\\\\', $step));
            $this->writeFile('timeStepSave', "{time:'" . $this->formatDuration($time) . "',step:`$jsStep`},\n", '.js');
        }
        $this->writeFile('timeStepSave', "]\n", '.js');

        foreach ($this->stats->getFunctionStats() as $func => $count) {
            $this->writeFile('stats1', sprintf("%5d=%s\n", $count, $func));
        }

        foreach ($this->stats->getFileStats() as $file => $count) {
            $this->writeFile('stats2', sprintf("%5d=%s\n", $count, $file));
        }

        $this->writeFile('stats3', $this->serializeStats3($this->stats->getNestedStats(), '') . "\n");

        $included = get_included_files();
        foreach ($included as &$f) {
            $f = $this->cleanFilePath($f);
        }
        unset($f);
        if ($this->config->skipVendor) {
            $included = array_filter($included, fn ($f) => !str_starts_with($f, 'vendor/'));
        }
        $this->writeFile('allIncluded', print_r($included, true));
    }

    /**
     * Recursively serialize nested stats (for stats3).
     *
     * @param array<string, mixed> $stats
     * @param string $prefix
     * @return string
     */
    private function serializeStats3(array $stats, string $prefix): string
    {
        $out = '';
        foreach ($stats as $key => $value) {
            $line = $prefix . '_' . $key . "\n";
            $out .= $line;
            if (is_array($value)) {
                $out .= $this->serializeStats3($value, $prefix . '[*]');
            }
        }
        return $out;
    }

    /**
     * CSV export (supports nested data).
     *
     * @param string $name
     * @param array<mixed> $data
     */
    public function exportCsv(string $name, array $data): void
    {
        $this->writeCsvHeaders($name, $data);
        $this->writeCsvRows($name, $data);
    }

    /**
     * @param string $name
     * @param array<mixed> $data
     */
    private function writeCsvHeaders(string $name, array $data): void
    {
        static $written = [];

        if (isset($written[$name])) {
            return;
        }
        $written[$name] = true;

        $headers = $this->extractCsvKeys($data);
        $line = implode(';', array_map([$this, 'csvEscape'], $headers)) . "\n";
        $this->writeFile($name, $line, self::CSV_FILE_EXTENSION);
    }

    /**
     * @param string $name
     * @param array<mixed> $data
     */
    private function writeCsvRows(string $name, array $data): void
    {
        $values = $this->extractCsvValues($data);
        if ($values === []) {
            return;
        }
        $line = implode(';', array_map([$this, 'csvEscape'], $values)) . "\n";
        $this->writeFile($name, $line, self::CSV_FILE_EXTENSION);
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private function extractCsvKeys(array $data): array
    {
        $keys = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $keys = array_merge($keys, $this->extractCsvKeys($item));
            } elseif (!is_int($item)) {
                $keys[] = (string)$item;
            }
        }
        return $keys;
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private function extractCsvValues(array $data): array
    {
        $values = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $values = array_merge($values, $this->extractCsvValues($item));
            } else {
                $values[] = $this->valueToString($item);
            }
        }
        return $values;
    }

    /**
     * Escape value for CSV (UTF-8 → windows-1251 safe).
     */
    private function csvEscape(string $value): string
    {
        $clean = str_replace('"', '""', $value);
        $win1251 = @mb_convert_encoding($clean, 'Windows-1251', 'UTF-8');
        if ($win1251 === false) {
            $win1251 = $clean; // fallback
        }
        return '"' . $win1251 . '"';
    }

    /**
     * SQL interpolation (safe string replacement).
     *
     * @param string $query
     * @param array<string, mixed> $params
     * @param array<string, string> $types
     * @return string
     */
    public function interpolateSql(string $query, array $params = [], array $types = []): string
    {
        foreach ($params as $name => $value) {
            $type = $types[$name] ?? null;
            $replacement = match ($type) {
                'text_array', 'int_array' => 'ARRAY' . $this->valueToPhp($value),
                'date_immutable', 'datetime_immutable', 'datetimetz_immutable' => $value instanceof DateTimeImmutable
                    ? $this->valueToPhp($value)
                    : $this->valueToPhp($value),
                'json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                default => $this->valueToPhp($value),
            };

            $query = str_replace(":$name", $replacement, $query);
        }

        return str_replace('regexp_escape', 'public.regexp_escape', $query);
    }

    /**
     * Get last argument (for method chaining).
     *
     * @template T
     * @param list<T> $args
     * @return T|null
     */
    private function getLastArgument(array $args): mixed
    {
        return $args === [] ? null : $args[array_key_last($args)];
    }

    /**
     * Check if logging should be blocked.
     */
    private function shouldBlock(): bool
    {
        if ($this->config->disabled || isset($GLOBALS['y_disable'])) {
            return true;
        }

        // Memory safety guard
        if (memory_get_usage() > 256 * 1024 * 1024) {
            return true;
        }

        // URI-based disable list
        $uri = $_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_FILENAME'] ?? '';
        foreach ($this->config->disableUris as $pattern) {
            if (str_starts_with($uri, $pattern)) {
                $this->disable();
                return true;
            }
        }

        return false;
    }

    /**
     * Register error handler.
     */
    private function registerErrorHandler(): void
    {
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
            if (!(error_reporting() & $errno)) {
                return false;
            }

            $this->enableWrite();

            $section = $this->currentSection;
            $this->addFile("error")->addFile("error_$section")->addFile('error_wp')->addFile("error_wp_$section");

            $message = match (true) {
                $errno === E_USER_ERROR => "[ERROR][$errno] $errstr\n  Фатальная ошибка in $errfile:$errline\n  Завершение работы...\n",
                $errno === E_USER_WARNING || $errno === E_WARNING => "[WARNING][$errno] $errstr in $errfile:$errline\n",
                $errno === E_USER_NOTICE => "[NOTICE][$errno] $errstr in $errfile:$errline\n",
                default => "[error][$errno] $errstr in $errfile:$errline\n",
            };

            $this->write([$message]);

            $this->removeFile("error")->removeFile("error_$section")->removeFile('error_wp')->removeFile("error_wp_$section");

            if ($this->config->throwErrors && !in_array($errno, [E_USER_NOTICE, E_NOTICE], true)) {
                throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
            }

            return false; // propagate to native handler if any
        }, E_ALL);

        $this->isErrorHandlerRegistered = true;
    }
}