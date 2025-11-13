<?php

declare(strict_types=1);

namespace Ylog;

/**
 * Immutable value object for profiling statistics.
 */
final class ProfileStats
{
    /**
     * @var array<string, int>
     */
    private array $functionCalls = [];

    /**
     * @var array<string, int>
     */
    private array $fileCalls = [];

    /**
     * @var array<string, float>
     */
    private array $durations = [];

    /**
     * @var list<array<string, float>>
     */
    private array $timeSteps = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $nestedStats = [];

    /**
     * @var array<string, int>
     */
    private array $fieldWidths = [];

    public function incrementFunctionCall(string $key): void
    {
        $this->functionCalls[$key] = ($this->functionCalls[$key] ?? 0) + 1;
    }

    public function incrementFileCall(string $key): void
    {
        $this->fileCalls[$key] = ($this->fileCalls[$key] ?? 0) + 1;
    }

    public function recordDuration(string $method, float $duration): void
    {
        $this->durations[$method] = ($this->durations[$method] ?? 0.0) + $duration;
        $this->timeSteps[] = [$method => $duration];
    }

    public function getFieldWidth(string $field, string $value): int
    {
        $len = mb_strlen($value);
        if (!isset($this->fieldWidths[$field]) || $this->fieldWidths[$field] < $len) {
            $this->fieldWidths[$field] = $len;
        }
        return $this->fieldWidths[$field];
    }

    public function clearFieldWidths(): void
    {
        $this->fieldWidths = [];
    }

    /**
     * @return array<string, int>
     */
    public function getFunctionStats(): array
    {
        return $this->functionCalls;
    }

    /**
     * @return array<string, int>
     */
    public function getFileStats(): array
    {
        return $this->fileCalls;
    }

    /**
     * @return array<string, float>
     */
    public function getDurations(): array
    {
        return $this->durations;
    }

    public function getTotalDuration(string $method): float
    {
        return $this->durations[$method] ?? 0.0;
    }

    /**
     * @return list<array<string, float>>
     */
    public function getTimeSteps(): array
    {
        return $this->timeSteps;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getNestedStats(): array
    {
        return $this->nestedStats;
    }

    /**
     * Extend nested stats (not implemented fully — placeholder for future).
     * In real project: build tree via stack + backtrace keys.
     */
    public function extendNested(array $path, string $key, mixed $value = null): void
    {
        // Simplified — real version would walk &$ref through $path
        $this->nestedStats[implode(' → ', $path)] = [$key => $value];
    }
}
