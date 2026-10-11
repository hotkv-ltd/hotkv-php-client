<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Support;

/**
 * Shape-normalizing helpers for replies that HELLO 3 turns into a RESP3
 * map/set/double, but that arrive from a RESP2 connection as a flat array
 * of alternating name/value elements. Typed command wrappers call these so
 * the caller gets the same PHP shape from either protocol.
 */
final class Reply
{
    /**
     * Turns a flat "name, value, name, value, ..." list (the RESP2 shape,
     * also how several HotKV commands document their reply even under
     * RESP3) into an associative array. Already-associative input (a
     * decoded RESP3 map) is returned unchanged.
     *
     * @return array<string, mixed>
     */
    public static function toMap(?array $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!array_is_list($value)) {
            return $value;
        }
        $map = [];
        $count = count($value);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $map[(string) $value[$i]] = $value[$i + 1];
        }

        return $map;
    }

    /**
     * Turns a list of [key, value] tuples (a nested, not flattened, pair
     * list such as TS.INFO's "labels") into an associative array.
     *
     * @param list<array{0: mixed, 1: mixed}> $pairs
     * @return array<string, mixed>
     */
    public static function pairsToMap(array $pairs): array
    {
        $map = [];
        foreach ($pairs as $pair) {
            $map[(string) $pair[0]] = $pair[1];
        }

        return $map;
    }

    /**
     * Normalizes a "member, score" style reply that can arrive in three
     * shapes depending on protocol and server version: a RESP2 flat list
     * ([m1, s1, m2, s2, ...]), a RESP3 array of pairs ([[m1, s1], [m2,
     * s2], ...], e.g. ZRANGE ... WITHSCORES), or a true RESP3 map
     * (already an associative array). All three become one PHP
     * associative array.
     *
     * @return array<string, mixed>
     */
    public static function toPairMap(?array $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!array_is_list($value)) {
            return $value;
        }
        if (is_array($value[0] ?? null)) {
            return self::pairsToMap($value);
        }

        return self::toMap($value);
    }

    /**
     * Normalizes a "member, score" (or "field, value") style reply that
     * must stay a list of pairs, not become an associative array, because
     * duplicates are meaningful (e.g. HRANDFIELD with a negative count
     * can repeat a field). Accepts a RESP2 flat list ([m1, s1, m2, s2,
     * ...]) or a RESP3 array of pairs ([[m1, s1], [m2, s2], ...]) and
     * returns a uniform list of 2-element [key, value] pairs either way.
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    public static function toPairList(?array $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (is_array($value[0] ?? null)) {
            return $value;
        }

        return self::chunks($value, 2);
    }

    /**
     * Splits a flat list into fixed-size chunks, for replies that are a
     * flat array of same-shaped records (e.g. VSIM.SEARCH's "key,
     * distance, key, distance, ...").
     *
     * @return list<list<mixed>>
     */
    public static function chunks(array $value, int $size): array
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Chunk size must be at least 1.');
        }

        return array_chunk($value, $size);
    }

    /** Converts a RESP integer reply (0/1) to a PHP bool. */
    public static function toBool(int $value): bool
    {
        return $value !== 0;
    }
}
