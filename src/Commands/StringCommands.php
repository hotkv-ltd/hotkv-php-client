<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\Support\Args;

/**
 * String commands. Requires the composing class to implement
 * HotKV\CommandDispatcher.
 */
trait StringCommands
{
    use NormalizesReplies;

    /** @return string|null the value, or null if $key does not exist */
    public function get(string $key): mixed
    {
        return $this->dispatch(['GET', $key]);
    }

    /**
     * @return string|bool|null "OK" (or true when $get is used and the
     *     set happened), the previous value when $get is true, or null
     *     when NX/XX prevented the set
     */
    public function set(
        string $key,
        string $value,
        ?int $ex = null,
        ?int $px = null,
        ?int $exat = null,
        ?int $pxat = null,
        bool $nx = false,
        bool $xx = false,
        bool $keepttl = false,
        bool $get = false,
    ): mixed {
        $args = Args::new('SET', $key, $value)
            ->opt('EX', $ex)
            ->opt('PX', $px)
            ->opt('EXAT', $exat)
            ->opt('PXAT', $pxat)
            ->flag('KEEPTTL', $keepttl)
            ->flag('NX', $nx)
            ->flag('XX', $xx)
            ->flag('GET', $get);

        return $this->dispatch($args->toArray());
    }

    /** @return int 1 if set, 0 if the key already existed */
    public function setnx(string $key, string $value): mixed
    {
        return $this->dispatch(['SETNX', $key, $value]);
    }

    public function setex(string $key, int $seconds, string $value): mixed
    {
        return $this->dispatch(['SETEX', $key, $seconds, $value]);
    }

    public function psetex(string $key, int $milliseconds, string $value): mixed
    {
        return $this->dispatch(['PSETEX', $key, $milliseconds, $value]);
    }

    /** @return string|null the previous value */
    public function getset(string $key, string $value): mixed
    {
        return $this->dispatch(['GETSET', $key, $value]);
    }

    /** @return string|null the value removed */
    public function getdel(string $key): mixed
    {
        return $this->dispatch(['GETDEL', $key]);
    }

    /** @return string|null the value; TTL options mirror SET's without touching the value */
    public function getex(
        string $key,
        ?int $ex = null,
        ?int $px = null,
        ?int $exat = null,
        ?int $pxat = null,
        bool $persist = false,
    ): mixed {
        $args = Args::new('GETEX', $key)
            ->opt('EX', $ex)
            ->opt('PX', $px)
            ->opt('EXAT', $exat)
            ->opt('PXAT', $pxat)
            ->flag('PERSIST', $persist);

        return $this->dispatch($args->toArray());
    }

    /** @return int the string length after appending */
    public function append(string $key, string $value): mixed
    {
        return $this->dispatch(['APPEND', $key, $value]);
    }

    public function strlen(string $key): mixed
    {
        return $this->dispatch(['STRLEN', $key]);
    }

    public function getrange(string $key, int $start, int $end): mixed
    {
        return $this->dispatch(['GETRANGE', $key, $start, $end]);
    }

    /** @return int the string length after the write */
    public function setrange(string $key, int $offset, string $value): mixed
    {
        return $this->dispatch(['SETRANGE', $key, $offset, $value]);
    }

    public function incr(string $key): mixed
    {
        return $this->dispatch(['INCR', $key]);
    }

    public function decr(string $key): mixed
    {
        return $this->dispatch(['DECR', $key]);
    }

    public function incrby(string $key, int $by): mixed
    {
        return $this->dispatch(['INCRBY', $key, $by]);
    }

    public function decrby(string $key, int $by): mixed
    {
        return $this->dispatch(['DECRBY', $key, $by]);
    }

    /** @return string the new value, formatted by the server */
    public function incrbyfloat(string $key, float $by): mixed
    {
        return $this->dispatch(['INCRBYFLOAT', $key, \HotKV\Support\Numbers::finite($by)]);
    }

    /**
     * @param list<string> $keys
     * @return list<string|null>
     */
    public function mget(array $keys): mixed
    {
        return $this->dispatch(array_merge(['MGET'], $keys));
    }

    /** @param array<string, string> $pairs */
    public function mset(array $pairs): mixed
    {
        $args = ['MSET'];
        foreach ($pairs as $k => $v) {
            $args[] = (string) $k;
            $args[] = $v;
        }

        return $this->dispatch($args);
    }

    /** @param array<string, string> $pairs @return int 1 if all keys were set, 0 if none were (at least one already existed) */
    public function msetnx(array $pairs): mixed
    {
        $args = ['MSETNX'];
        foreach ($pairs as $k => $v) {
            $args[] = (string) $k;
            $args[] = $v;
        }

        return $this->dispatch($args);
    }

    /**
     * LCS: the longest common subsequence of two string keys. Plain: the
     * subsequence (string). $len: its length (int). $idx: the match
     * positions as a map {matches: [...], len: int}; $minMatchLen and
     * $withMatchLen refine it.
     *
     * @return string|int|array<string, mixed>
     */
    public function lcs(string $key1, string $key2, bool $len = false, bool $idx = false, ?int $minMatchLen = null, bool $withMatchLen = false): mixed
    {
        $args = Args::new('LCS', $key1, $key2)->flag('LEN', $len)->flag('IDX', $idx)->opt('MINMATCHLEN', $minMatchLen)->flag('WITHMATCHLEN', $withMatchLen);
        $result = $this->dispatch($args->toArray());

        return $idx && is_array($result) ? $this->normalized($result, \HotKV\Support\Reply::toMap(...)) : $result;
    }

    /** @return string|null the key's XXH3-64 digest as 16 hex digits, or null if the key does not exist */
    public function digest(string $key): mixed
    {
        return $this->dispatch(['DIGEST', $key]);
    }

    /**
     * DELEX: delete a string key, optionally only when it matches a
     * condition. Pass at most one of $ifeq, $ifne (compare the value) or
     * $ifdeq, $ifdne (compare the DIGEST).
     *
     * @return int 1 if deleted, 0 otherwise
     */
    public function delex(string $key, ?string $ifeq = null, ?string $ifne = null, ?string $ifdeq = null, ?string $ifdne = null): mixed
    {
        $args = Args::new('DELEX', $key)->opt('IFEQ', $ifeq)->opt('IFNE', $ifne)->opt('IFDEQ', $ifdeq)->opt('IFDNE', $ifdne);

        return $this->dispatch($args->toArray());
    }

    /**
     * MSETEX: set several keys at once under one TTL (at most one of
     * $ex, $px, $exat, $pxat, $keepttl), optionally only if none ($nx) or
     * all ($xx) of them exist.
     *
     * @param array<string, string> $pairs key => value
     * @return int 1 if the keys were set, 0 when NX or XX prevented it
     */
    public function msetex(
        array $pairs,
        bool $nx = false,
        bool $xx = false,
        ?int $ex = null,
        ?int $px = null,
        ?int $exat = null,
        ?int $pxat = null,
        bool $keepttl = false,
    ): mixed {
        $args = Args::new('MSETEX', count($pairs));
        foreach ($pairs as $k => $v) {
            $args->push((string) $k, $v);
        }
        $args->flag('NX', $nx)->flag('XX', $xx)->opt('EX', $ex)->opt('PX', $px)->opt('EXAT', $exat)->opt('PXAT', $pxat)->flag('KEEPTTL', $keepttl);

        return $this->dispatch($args->toArray());
    }
}
