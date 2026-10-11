<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\Support\Args;

/**
 * List commands. Requires the composing class to implement
 * HotKV\CommandDispatcher.
 */
trait ListCommands
{
    /** @param list<string> $values @return int the list length after the push */
    public function lpush(string $key, array $values): mixed
    {
        return $this->dispatch(array_merge(['LPUSH', $key], $values));
    }

    /** @param list<string> $values @return int the list length after the push */
    public function rpush(string $key, array $values): mixed
    {
        return $this->dispatch(array_merge(['RPUSH', $key], $values));
    }

    /** @return int 0 if $key does not exist, the list length after the push otherwise */
    public function lpushx(string $key, array $values): mixed
    {
        return $this->dispatch(array_merge(['LPUSHX', $key], $values));
    }

    public function rpushx(string $key, array $values): mixed
    {
        return $this->dispatch(array_merge(['RPUSHX', $key], $values));
    }

    /** @return string|list<string>|null a single value, a list when $count is given, or null/empty when nothing to pop */
    public function lpop(string $key, ?int $count = null): mixed
    {
        $args = $count === null ? ['LPOP', $key] : ['LPOP', $key, $count];

        return $this->dispatch($args);
    }

    public function rpop(string $key, ?int $count = null): mixed
    {
        $args = $count === null ? ['RPOP', $key] : ['RPOP', $key, $count];

        return $this->dispatch($args);
    }

    public function llen(string $key): mixed
    {
        return $this->dispatch(['LLEN', $key]);
    }

    /** @return list<string> */
    public function lrange(string $key, int $start, int $stop): mixed
    {
        return $this->dispatch(['LRANGE', $key, $start, $stop]);
    }

    /** @return string|null */
    public function lindex(string $key, int $index): mixed
    {
        return $this->dispatch(['LINDEX', $key, $index]);
    }

    /** @return string "OK" */
    public function lset(string $key, int $index, string $value): mixed
    {
        return $this->dispatch(['LSET', $key, $index, $value]);
    }

    /** @return int the list length after the insert, or -1 if $pivot was not found */
    public function linsert(string $key, bool $before, string $pivot, string $value): mixed
    {
        return $this->dispatch(['LINSERT', $key, $before ? 'BEFORE' : 'AFTER', $pivot, $value]);
    }

    /** @return int the number of removed elements */
    public function lrem(string $key, int $count, string $value): mixed
    {
        return $this->dispatch(['LREM', $key, $count, $value]);
    }

    /** @return string "OK" */
    public function ltrim(string $key, int $start, int $stop): mixed
    {
        return $this->dispatch(['LTRIM', $key, $start, $stop]);
    }

    /** @return string|null the element moved */
    public function rpoplpush(string $source, string $destination): mixed
    {
        return $this->dispatch(['RPOPLPUSH', $source, $destination]);
    }

    /** @return string|null the element moved */
    public function lmove(string $source, string $destination, bool $fromLeft, bool $toLeft): mixed
    {
        return $this->dispatch([
            'LMOVE', $source, $destination,
            $fromLeft ? 'LEFT' : 'RIGHT',
            $toLeft ? 'LEFT' : 'RIGHT',
        ]);
    }

    /** @return int|list<int>|null the index (or indexes with $count), or null/empty if not found */
    public function lpos(string $key, string $element, ?int $rank = null, ?int $count = null, ?int $maxlen = null): mixed
    {
        $args = Args::new('LPOS', $key, $element)->opt('RANK', $rank)->opt('COUNT', $count)->opt('MAXLEN', $maxlen);

        return $this->dispatch($args->toArray());
    }

    /** @param list<string> $keys @return array{0: string, 1: string}|null [key, value], or null on timeout */
    public function blpop(array $keys, float $timeoutSeconds): mixed
    {
        return $this->dispatch(array_merge(['BLPOP'], $keys, [\HotKV\Support\Numbers::finite($timeoutSeconds)]));
    }

    /** @param list<string> $keys @return array{0: string, 1: string}|null */
    public function brpop(array $keys, float $timeoutSeconds): mixed
    {
        return $this->dispatch(array_merge(['BRPOP'], $keys, [\HotKV\Support\Numbers::finite($timeoutSeconds)]));
    }

    /** @return string|null the element moved, or null on timeout */
    public function blmove(string $source, string $destination, bool $fromLeft, bool $toLeft, float $timeoutSeconds): mixed
    {
        return $this->dispatch([
            'BLMOVE', $source, $destination,
            $fromLeft ? 'LEFT' : 'RIGHT',
            $toLeft ? 'LEFT' : 'RIGHT',
            \HotKV\Support\Numbers::finite($timeoutSeconds),
        ]);
    }

    /** @return string|null the element moved, or null on timeout */
    public function brpoplpush(string $source, string $destination, float $timeoutSeconds): mixed
    {
        return $this->dispatch(['BRPOPLPUSH', $source, $destination, \HotKV\Support\Numbers::finite($timeoutSeconds)]);
    }

    /**
     * LMPOP: pops from the first non-empty list.
     *
     * @param list<string> $keys
     * @return array{0: string, 1: list<string>}|null [key, elements], or null when every list is empty
     */
    public function lmpop(array $keys, bool $left = true, ?int $count = null): mixed
    {
        $args = Args::new('LMPOP', count($keys))->pushAll($keys)->push($left ? 'LEFT' : 'RIGHT')->opt('COUNT', $count);

        return $this->dispatch($args->toArray());
    }

    /**
     * BLMPOP: LMPOP that waits up to $timeoutSeconds (0 = forever) for an element.
     *
     * @param list<string> $keys
     * @return array{0: string, 1: list<string>}|null
     */
    public function blmpop(float $timeoutSeconds, array $keys, bool $left = true, ?int $count = null): mixed
    {
        $args = Args::new('BLMPOP', \HotKV\Support\Numbers::finite($timeoutSeconds), count($keys))->pushAll($keys)
            ->push($left ? 'LEFT' : 'RIGHT')->opt('COUNT', $count);

        return $this->dispatch($args->toArray());
    }
}
