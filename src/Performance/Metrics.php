<?php

namespace Xstrm\Xstrm\Performance;

/**
 * Counters accumulated during one request.
 *
 * Plain integers and floats incremented from event listeners — nothing here
 * allocates per query or per cache hit. A busy request can fire thousands of
 * these, so anything that built an array of records would cost more than the
 * work it is measuring.
 */
class Metrics
{
    public int $dbCount = 0;

    public float $dbMs = 0.0;

    public int $dbSlow = 0;

    public int $cacheHits = 0;

    public int $cacheMisses = 0;

    public int $httpCount = 0;

    public float $httpMs = 0.0;

    public function query(float $ms, int $slowThresholdMs): void
    {
        $this->dbCount++;
        $this->dbMs += $ms;

        if ($ms >= $slowThresholdMs) {
            $this->dbSlow++;
        }
    }

    public function cacheHit(): void
    {
        $this->cacheHits++;
    }

    public function cacheMiss(): void
    {
        $this->cacheMisses++;
    }

    public function http(float $ms): void
    {
        $this->httpCount++;
        $this->httpMs += $ms;
    }

    public function reset(): void
    {
        $this->dbCount = 0;
        $this->dbMs = 0.0;
        $this->dbSlow = 0;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->httpCount = 0;
        $this->httpMs = 0.0;
    }

    public function toArray(): array
    {
        return [
            'db' => [
                'count' => $this->dbCount,
                'ms' => round($this->dbMs, 1),
                'slow' => $this->dbSlow,
            ],
            'cache' => [
                'hits' => $this->cacheHits,
                'misses' => $this->cacheMisses,
            ],
            'http' => [
                'count' => $this->httpCount,
                'ms' => round($this->httpMs, 1),
            ],
        ];
    }
}
