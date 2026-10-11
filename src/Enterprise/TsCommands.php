<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;
use HotKV\Support\Args;
use HotKV\Support\Numbers;
use HotKV\Support\Reply;

/**
 * TS.* : time series (needs a server license that includes the
 * `time-series` feature). Reach this via `$client->ts()`.
 */
final class TsCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /**
     * Put RETENTION before $labels if both are used: the server reads
     * LABELS values until the RETENTION keyword or end of args.
     *
     * @param array<string, string> $labels
     * @return string "OK"
     */
    public function create(string $key, ?int $retentionMs = null, array $labels = []): mixed
    {
        $args = Args::new('TS.CREATE', $key)->opt('RETENTION', $retentionMs);
        if ($labels !== []) {
            $args->push('LABELS');
            foreach ($labels as $label => $value) {
                $args->push((string) $label, $value);
            }
        }

        return $this->client->execute($args->toArray());
    }

    /** @param int|string $timestamp a millisecond timestamp, or "*" for the server's current time @return int the timestamp (ms) used */
    public function add(string $key, int|string $timestamp, float $value): mixed
    {
        return $this->client->execute(['TS.ADD', $key, (string) $timestamp, Numbers::finite($value)]);
    }

    /** @param string $fromMs @param string $toMs a millisecond timestamp, or the literals "-" (0) / "+" (max) @return list<array{0: int, 1: string}> [timestamp_ms, value] */
    public function range(string $key, string $fromMs, string $toMs, ?int $count = null): mixed
    {
        return $this->client->execute(Args::new('TS.RANGE', $key, $fromMs, $toMs)->opt('COUNT', $count)->toArray());
    }

    /** @param string $aggType one of "avg", "min", "max", "sum", "count" (case insensitive) @return list<array{0: int, 1: string}> bucketed [timestamp_ms, value] */
    public function agg(string $key, string $fromMs, string $toMs, int $bucketMs, string $aggType): mixed
    {
        return $this->client->execute(['TS.AGG', $key, $fromMs, $toMs, $bucketMs, $aggType]);
    }

    /**
     * @return array{key: string, totalSamples: int, firstTimestamp: int,
     *     lastTimestamp: int, lastValue: string, retentionMs: int,
     *     memoryBytes: int, labels: array<string, string>, rules: int}
     */
    public function info(string $key): mixed
    {
        $map = Reply::toMap($this->client->execute(['TS.INFO', $key]));
        if (isset($map['labels']) && is_array($map['labels'])) {
            $map['labels'] = Reply::pairsToMap($map['labels']);
        }

        return $map;
    }

    /** @return string "OK" */
    public function delete(string $key): mixed
    {
        return $this->client->execute(['TS.DELETE', $key]);
    }

    /** @return string "OK" */
    public function retention(string $key, int $retentionMs): mixed
    {
        return $this->client->execute(['TS.RETENTION', $key, $retentionMs]);
    }

    /** Adds a live downsample rule from $srcKey into $destKey (both must already exist). @return string "OK" */
    public function downsample(string $srcKey, string $destKey, string $aggType, int $bucketMs): mixed
    {
        return $this->client->execute(['TS.DOWNSAMPLE', $srcKey, $destKey, $aggType, $bucketMs]);
    }

    /** @return list<string> series keys, optionally filtered to series carrying $labelName => $labelValue */
    public function list(?string $labelName = null, ?string $labelValue = null): mixed
    {
        $args = Args::new('TS.LIST');
        if ($labelName !== null && $labelValue !== null) {
            $args->push('LABEL', $labelName, $labelValue);
        }

        return $this->client->execute($args->toArray());
    }
}
