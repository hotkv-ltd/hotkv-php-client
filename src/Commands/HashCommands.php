<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\Support\Args;
use HotKV\Support\Reply;

/**
 * Hash commands, including the HEXPIRE/HTTL field-expiry family. Requires
 * the composing class to implement HotKV\CommandDispatcher.
 */
trait HashCommands
{
    use NormalizesReplies;

    /** @param array<string, string> $fields @return int number of new fields added */
    public function hset(string $key, array $fields): mixed
    {
        $args = ['HSET', $key];
        foreach ($fields as $field => $value) {
            $args[] = (string) $field;
            $args[] = $value;
        }

        return $this->dispatch($args);
    }

    /** @return int 1 if set, 0 if the field already existed */
    public function hsetnx(string $key, string $field, string $value): mixed
    {
        return $this->dispatch(['HSETNX', $key, $field, $value]);
    }

    /** @return string|null */
    public function hget(string $key, string $field): mixed
    {
        return $this->dispatch(['HGET', $key, $field]);
    }

    /** @param list<string> $fields @return list<string|null> */
    public function hmget(string $key, array $fields): mixed
    {
        return $this->dispatch(array_merge(['HMGET', $key], $fields));
    }

    /** @param array<string, string> $fields @return string "OK" */
    public function hmset(string $key, array $fields): mixed
    {
        $args = ['HMSET', $key];
        foreach ($fields as $field => $value) {
            $args[] = (string) $field;
            $args[] = $value;
        }

        return $this->dispatch($args);
    }

    /** @return array<string, string> field => value */
    public function hgetall(string $key): mixed
    {
        return $this->normalized($this->dispatch(['HGETALL', $key]), Reply::toMap(...));
    }

    /** @param list<string> $fields @return int number of fields removed */
    public function hdel(string $key, array $fields): mixed
    {
        return $this->dispatch(array_merge(['HDEL', $key], $fields));
    }

    public function hlen(string $key): mixed
    {
        return $this->dispatch(['HLEN', $key]);
    }

    /** @return int 0/1 */
    public function hexists(string $key, string $field): mixed
    {
        return $this->dispatch(['HEXISTS', $key, $field]);
    }

    /** @return list<string> */
    public function hkeys(string $key): mixed
    {
        return $this->dispatch(['HKEYS', $key]);
    }

    /** @return list<string> */
    public function hvals(string $key): mixed
    {
        return $this->dispatch(['HVALS', $key]);
    }

    public function hincrby(string $key, string $field, int $by): mixed
    {
        return $this->dispatch(['HINCRBY', $key, $field, $by]);
    }

    /** @return string the new value, formatted by the server */
    public function hincrbyfloat(string $key, string $field, float $by): mixed
    {
        return $this->dispatch(['HINCRBYFLOAT', $key, $field, \HotKV\Support\Numbers::finite($by)]);
    }

    public function hstrlen(string $key, string $field): mixed
    {
        return $this->dispatch(['HSTRLEN', $key, $field]);
    }

    /**
     * @return string|list<string>|list<array{0: string, 1: string}>|null
     *     a single field, a list of fields, or (with $withValues) a list
     *     of [field, value] pairs
     */
    public function hrandfield(string $key, ?int $count = null, bool $withValues = false): mixed
    {
        if ($count === null) {
            return $this->dispatch(['HRANDFIELD', $key]);
        }
        $args = ['HRANDFIELD', $key, $count];
        if ($withValues) {
            $args[] = 'WITHVALUES';
        }
        $result = $this->dispatch($args);

        return $withValues ? $this->normalized($result, Reply::toPairList(...)) : $result;
    }

    /**
     * @param list<string> $fields
     * @return list<int> per field: -2 no such field, 0 condition not met, 1 set, 2 field deleted (ttl <= 0)
     */
    public function hexpire(string $key, int $seconds, array $fields, bool $nx = false, bool $xx = false, bool $gt = false, bool $lt = false): mixed
    {
        return $this->dispatch($this->hExpiryArgs('HEXPIRE', $key, $seconds, $fields, $nx, $xx, $gt, $lt));
    }

    /** @param list<string> $fields @return list<int> */
    public function hpexpire(string $key, int $milliseconds, array $fields, bool $nx = false, bool $xx = false, bool $gt = false, bool $lt = false): mixed
    {
        return $this->dispatch($this->hExpiryArgs('HPEXPIRE', $key, $milliseconds, $fields, $nx, $xx, $gt, $lt));
    }

    /** @param list<string> $fields @return list<int> */
    public function hexpireat(string $key, int $unixSeconds, array $fields, bool $nx = false, bool $xx = false, bool $gt = false, bool $lt = false): mixed
    {
        return $this->dispatch($this->hExpiryArgs('HEXPIREAT', $key, $unixSeconds, $fields, $nx, $xx, $gt, $lt));
    }

    /** @param list<string> $fields @return list<int> */
    public function hpexpireat(string $key, int $unixMillis, array $fields, bool $nx = false, bool $xx = false, bool $gt = false, bool $lt = false): mixed
    {
        return $this->dispatch($this->hExpiryArgs('HPEXPIREAT', $key, $unixMillis, $fields, $nx, $xx, $gt, $lt));
    }

    /** @param list<string> $fields @return list<int> -2 no such field, 1 removed, -1 no ttl to remove */
    public function hpersist(string $key, array $fields): mixed
    {
        return $this->dispatch(array_merge(['HPERSIST', $key, 'FIELDS', count($fields)], $fields));
    }

    /** @param list<string> $fields @return list<int> seconds remaining per field (-2/-1 as usual) */
    public function httl(string $key, array $fields): mixed
    {
        return $this->dispatch(array_merge(['HTTL', $key, 'FIELDS', count($fields)], $fields));
    }

    /** @param list<string> $fields @return list<int> */
    public function hpttl(string $key, array $fields): mixed
    {
        return $this->dispatch(array_merge(['HPTTL', $key, 'FIELDS', count($fields)], $fields));
    }

    /** @param list<string> $fields @return list<int> */
    public function hexpiretime(string $key, array $fields): mixed
    {
        return $this->dispatch(array_merge(['HEXPIRETIME', $key, 'FIELDS', count($fields)], $fields));
    }

    /** @param list<string> $fields @return list<int> */
    public function hpexpiretime(string $key, array $fields): mixed
    {
        return $this->dispatch(array_merge(['HPEXPIRETIME', $key, 'FIELDS', count($fields)], $fields));
    }

    /** @param list<string> $fields */
    private function hExpiryArgs(string $cmd, string $key, int $ttl, array $fields, bool $nx, bool $xx, bool $gt, bool $lt): array
    {
        $args = [$cmd, $key, $ttl];
        if ($nx) {
            $args[] = 'NX';
        } elseif ($xx) {
            $args[] = 'XX';
        } elseif ($gt) {
            $args[] = 'GT';
        } elseif ($lt) {
            $args[] = 'LT';
        }
        $args[] = 'FIELDS';
        $args[] = count($fields);

        return array_merge($args, $fields);
    }

    /**
     * HGETDEL: returns the values of the given fields and deletes them.
     *
     * @param list<string> $fields
     * @return list<string|null> one value per field, null for a missing field
     */
    public function hgetdel(string $key, array $fields): mixed
    {
        return $this->dispatch(array_merge(['HGETDEL', $key, 'FIELDS', count($fields)], $fields));
    }

    /**
     * HGETEX: returns the values of the given fields and sets their TTL
     * (at most one of $ex, $px, $exat, $pxat) or removes it ($persist).
     *
     * @param list<string> $fields
     * @return list<string|null>
     */
    public function hgetex(string $key, array $fields, ?int $ex = null, ?int $px = null, ?int $exat = null, ?int $pxat = null, bool $persist = false): mixed
    {
        $args = Args::new('HGETEX', $key)->opt('EX', $ex)->opt('PX', $px)->opt('EXAT', $exat)->opt('PXAT', $pxat)->flag('PERSIST', $persist)
            ->push('FIELDS', count($fields))->pushAll($fields);

        return $this->dispatch($args->toArray());
    }

    /**
     * HSETEX: sets fields (all or none) with a TTL. $fnx sets them only if
     * none exist, $fxx only if all exist.
     *
     * @param array<string, string> $fields field => value
     * @return int 1 if set, 0 if a condition prevented it
     */
    public function hsetex(
        string $key,
        array $fields,
        bool $fnx = false,
        bool $fxx = false,
        ?int $ex = null,
        ?int $px = null,
        ?int $exat = null,
        ?int $pxat = null,
        bool $keepttl = false,
    ): mixed {
        $args = Args::new('HSETEX', $key)->flag('FNX', $fnx)->flag('FXX', $fxx)->opt('EX', $ex)->opt('PX', $px)->opt('EXAT', $exat)
            ->opt('PXAT', $pxat)->flag('KEEPTTL', $keepttl)->push('FIELDS', count($fields));
        foreach ($fields as $f => $v) {
            $args->push((string) $f, $v);
        }

        return $this->dispatch($args->toArray());
    }
}
