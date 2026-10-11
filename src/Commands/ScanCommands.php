<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\Support\Reply;

/**
 * SCAN family cursor iterators. Each round trip must complete before the
 * next one is issued, so unlike the rest of the command traits these call
 * execute() directly and are only mixed into Client, not Pipeline or
 * Transaction.
 */
trait ScanCommands
{
    /** @return \Generator<int, string> every key matching $match, fetched incrementally */
    public function scan(string $match = '*', int $count = 100, ?string $type = null): \Generator
    {
        $cursor = '0';
        do {
            $args = ['SCAN', $cursor, 'MATCH', $match, 'COUNT', $count];
            if ($type !== null) {
                $args[] = 'TYPE';
                $args[] = $type;
            }
            [$cursor, $keys] = $this->execute($args);
            foreach ($keys as $key) {
                yield $key;
            }
        } while ($cursor !== '0');
    }

    /** @return \Generator<int, string> every member of set $key matching $match */
    public function sscan(string $key, string $match = '*', int $count = 100): \Generator
    {
        $cursor = '0';
        do {
            [$cursor, $members] = $this->execute(['SSCAN', $key, $cursor, 'MATCH', $match, 'COUNT', $count]);
            foreach ($members as $member) {
                yield $member;
            }
        } while ($cursor !== '0');
    }

    /** @return \Generator<string, string> field => value, for every field of hash $key matching $match */
    public function hscan(string $key, string $match = '*', int $count = 100): \Generator
    {
        $cursor = '0';
        do {
            [$cursor, $flat] = $this->execute(['HSCAN', $key, $cursor, 'MATCH', $match, 'COUNT', $count]);
            foreach (Reply::toMap($flat) as $field => $value) {
                yield $field => $value;
            }
        } while ($cursor !== '0');
    }

    /** @return \Generator<string, string> member => score, for every member of sorted set $key matching $match */
    public function zscan(string $key, string $match = '*', int $count = 100): \Generator
    {
        $cursor = '0';
        do {
            [$cursor, $flat] = $this->execute(['ZSCAN', $key, $cursor, 'MATCH', $match, 'COUNT', $count]);
            foreach (Reply::toMap($flat) as $member => $score) {
                yield $member => $score;
            }
        } while ($cursor !== '0');
    }
}
