<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\Support\Args;

/**
 * Generic key, expiry and HotKV nanosecond-TTL commands. Requires the
 * composing class to implement HotKV\CommandDispatcher.
 */
trait KeyCommands
{
    /** @param list<string> $keys @return int number of keys removed */
    public function del(array $keys): mixed
    {
        return $this->dispatch(array_merge(['DEL'], $keys));
    }

    /** @param list<string> $keys @return int number of keys removed (non-blocking) */
    public function unlink(array $keys): mixed
    {
        return $this->dispatch(array_merge(['UNLINK'], $keys));
    }

    /** @param list<string> $keys @return int number of the given keys that exist */
    public function exists(array $keys): mixed
    {
        return $this->dispatch(array_merge(['EXISTS'], $keys));
    }

    /** @return string one of "string", "list", "set", "zset", "hash", "stream" or "none" */
    public function type(string $key): mixed
    {
        return $this->dispatch(['TYPE', $key]);
    }

    /** @return int 1 if the TTL was set, 0 otherwise */
    public function expire(string $key, int $seconds, bool $nx = false, bool $xx = false, bool $gt = false, bool $lt = false): mixed
    {
        $args = Args::new('EXPIRE', $key, $seconds)->flag('NX', $nx)->flag('XX', $xx)->flag('GT', $gt)->flag('LT', $lt);

        return $this->dispatch($args->toArray());
    }

    public function pexpire(string $key, int $milliseconds, bool $nx = false, bool $xx = false, bool $gt = false, bool $lt = false): mixed
    {
        $args = Args::new('PEXPIRE', $key, $milliseconds)->flag('NX', $nx)->flag('XX', $xx)->flag('GT', $gt)->flag('LT', $lt);

        return $this->dispatch($args->toArray());
    }

    public function expireat(string $key, int $unixSeconds, bool $nx = false, bool $xx = false, bool $gt = false, bool $lt = false): mixed
    {
        $args = Args::new('EXPIREAT', $key, $unixSeconds)->flag('NX', $nx)->flag('XX', $xx)->flag('GT', $gt)->flag('LT', $lt);

        return $this->dispatch($args->toArray());
    }

    public function pexpireat(string $key, int $unixMillis, bool $nx = false, bool $xx = false, bool $gt = false, bool $lt = false): mixed
    {
        $args = Args::new('PEXPIREAT', $key, $unixMillis)->flag('NX', $nx)->flag('XX', $xx)->flag('GT', $gt)->flag('LT', $lt);

        return $this->dispatch($args->toArray());
    }

    /** @return int seconds remaining, -1 if no TTL, -2 if the key does not exist */
    public function ttl(string $key): mixed
    {
        return $this->dispatch(['TTL', $key]);
    }

    /** @return int milliseconds remaining, -1 if no TTL, -2 if the key does not exist */
    public function pttl(string $key): mixed
    {
        return $this->dispatch(['PTTL', $key]);
    }

    /** @return int 1 if the TTL was removed, 0 if the key has none or does not exist */
    public function persist(string $key): mixed
    {
        return $this->dispatch(['PERSIST', $key]);
    }

    public function expiretime(string $key): mixed
    {
        return $this->dispatch(['EXPIRETIME', $key]);
    }

    public function pexpiretime(string $key): mixed
    {
        return $this->dispatch(['PEXPIRETIME', $key]);
    }

    /** @return string "OK" */
    public function rename(string $key, string $newKey): mixed
    {
        return $this->dispatch(['RENAME', $key, $newKey]);
    }

    /** @return int 1 if renamed, 0 if $newKey already exists */
    public function renamenx(string $key, string $newKey): mixed
    {
        return $this->dispatch(['RENAMENX', $key, $newKey]);
    }

    /** @return int 1 if copied, 0 otherwise */
    public function copy(string $source, string $destination, ?int $db = null, bool $replace = false): mixed
    {
        $args = Args::new('COPY', $source, $destination)->opt('DB', $db)->flag('REPLACE', $replace);

        return $this->dispatch($args->toArray());
    }

    /** @return int 1 if moved, 0 otherwise */
    public function move(string $key, int $db): mixed
    {
        return $this->dispatch(['MOVE', $key, $db]);
    }

    /** @return string|null a random key, or null if the keyspace is empty */
    public function randomkey(): mixed
    {
        return $this->dispatch(['RANDOMKEY']);
    }

    /** @return list<string> keys matching $pattern; prefer the scan() iterator on a production keyspace */
    public function keys(string $pattern = '*'): mixed
    {
        return $this->dispatch(['KEYS', $pattern]);
    }

    /** @param list<string> $keys @return int number of keys touched */
    public function touch(array $keys): mixed
    {
        return $this->dispatch(array_merge(['TOUCH'], $keys));
    }

    /** @return string|null an opaque serialized representation, or null if $key does not exist */
    public function dump(string $key): mixed
    {
        return $this->dispatch(['DUMP', $key]);
    }

    /** @return string "OK" */
    public function restore(
        string $key,
        int $ttlMillis,
        string $serialized,
        bool $replace = false,
        bool $absttl = false,
        ?int $idletime = null,
        ?int $freq = null,
    ): mixed {
        $args = Args::new('RESTORE', $key, $ttlMillis, $serialized)
            ->flag('REPLACE', $replace)
            ->flag('ABSTTL', $absttl)
            ->opt('IDLETIME', $idletime)
            ->opt('FREQ', $freq);

        return $this->dispatch($args->toArray());
    }

    /** @return string|null the internal encoding name for $key's value */
    public function objectEncoding(string $key): mixed
    {
        return $this->dispatch(['OBJECT', 'ENCODING', $key]);
    }

    public function objectRefcount(string $key): mixed
    {
        return $this->dispatch(['OBJECT', 'REFCOUNT', $key]);
    }

    public function objectIdletime(string $key): mixed
    {
        return $this->dispatch(['OBJECT', 'IDLETIME', $key]);
    }

    public function objectFreq(string $key): mixed
    {
        return $this->dispatch(['OBJECT', 'FREQ', $key]);
    }

    // -- HotKV nanosecond-resolution TTL (works without a license) --

    /**
     * Sets a relative, nanosecond-resolution TTL on $key.
     *
     * @return int 1 if the TTL was set, 0 if $key does not exist
     */
    public function nexpire(string $key, int $nanoseconds): mixed
    {
        return $this->dispatch(['NEXPIRE', $key, $nanoseconds]);
    }

    /** @return int remaining TTL in nanoseconds; -2 if $key does not exist; -1 if it has no expiry */
    public function nttl(string $key): mixed
    {
        return $this->dispatch(['NTTL', $key]);
    }

    /** Sets an absolute, nanosecond-resolution Unix timestamp expiry on $key. @return int 1/0 */
    public function nexpireat(string $key, int $nanosecondUnixTimestamp): mixed
    {
        return $this->dispatch(['NEXPIREAT', $key, $nanosecondUnixTimestamp]);
    }

    /**
     * SORT: sorts a list, set or sorted set. $get patterns are repeated
     * GET options; with $store the result is stored there and its length
     * returned.
     *
     * @param list<string> $get
     * @return list<string|null>|int the sorted elements, or the stored length with $store
     */
    public function sort(
        string $key,
        ?string $by = null,
        ?int $limitOffset = null,
        ?int $limitCount = null,
        array $get = [],
        bool $desc = false,
        bool $alpha = false,
        ?string $store = null,
    ): mixed {
        return $this->dispatch($this->sortArgs('SORT', $key, $by, $limitOffset, $limitCount, $get, $desc, $alpha, $store));
    }

    /**
     * SORT_RO: SORT without STORE.
     *
     * @param list<string> $get
     * @return list<string|null>
     */
    public function sortRo(
        string $key,
        ?string $by = null,
        ?int $limitOffset = null,
        ?int $limitCount = null,
        array $get = [],
        bool $desc = false,
        bool $alpha = false,
    ): mixed {
        return $this->dispatch($this->sortArgs('SORT_RO', $key, $by, $limitOffset, $limitCount, $get, $desc, $alpha, null));
    }

    /** @param list<string> $get @return list<string|int> */
    private function sortArgs(string $cmd, string $key, ?string $by, ?int $offset, ?int $count, array $get, bool $desc, bool $alpha, ?string $store): array
    {
        $args = Args::new($cmd, $key)->opt('BY', $by);
        if ($offset !== null && $count !== null) {
            $args->push('LIMIT', $offset, $count);
        }
        foreach ($get as $pattern) {
            $args->push('GET', $pattern);
        }
        $args->flag('DESC', $desc)->flag('ALPHA', $alpha)->opt('STORE', $store);

        return $args->toArray();
    }
}
