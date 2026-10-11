<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\Support\Args;

/**
 * Set commands. Requires the composing class to implement
 * HotKV\CommandDispatcher.
 */
trait SetCommands
{
    /** @param list<string> $members @return int number of members added */
    public function sadd(string $key, array $members): mixed
    {
        return $this->dispatch(array_merge(['SADD', $key], $members));
    }

    /** @param list<string> $members @return int number of members removed */
    public function srem(string $key, array $members): mixed
    {
        return $this->dispatch(array_merge(['SREM', $key], $members));
    }

    /** @return list<string> */
    public function smembers(string $key): mixed
    {
        return $this->dispatch(['SMEMBERS', $key]);
    }

    /** @return int 0/1 */
    public function sismember(string $key, string $member): mixed
    {
        return $this->dispatch(['SISMEMBER', $key, $member]);
    }

    /** @param list<string> $members @return list<int> 0/1 per member */
    public function smismember(string $key, array $members): mixed
    {
        return $this->dispatch(array_merge(['SMISMEMBER', $key], $members));
    }

    public function scard(string $key): mixed
    {
        return $this->dispatch(['SCARD', $key]);
    }

    /** @return string|list<string>|null a single member, a list when $count is given, or null/empty if the set is empty */
    public function spop(string $key, ?int $count = null): mixed
    {
        $args = $count === null ? ['SPOP', $key] : ['SPOP', $key, $count];

        return $this->dispatch($args);
    }

    /** @return string|list<string>|null */
    public function srandmember(string $key, ?int $count = null): mixed
    {
        $args = $count === null ? ['SRANDMEMBER', $key] : ['SRANDMEMBER', $key, $count];

        return $this->dispatch($args);
    }

    /** @return int 1 if moved, 0 if $member was not in $source */
    public function smove(string $source, string $destination, string $member): mixed
    {
        return $this->dispatch(['SMOVE', $source, $destination, $member]);
    }

    /** @param list<string> $keys @return list<string> */
    public function sinter(array $keys): mixed
    {
        return $this->dispatch(array_merge(['SINTER'], $keys));
    }

    /** @param list<string> $keys @return int the resulting set's cardinality */
    public function sinterstore(string $destination, array $keys): mixed
    {
        return $this->dispatch(array_merge(['SINTERSTORE', $destination], $keys));
    }

    /** @param list<string> $keys @return int the intersection's cardinality, without storing it */
    public function sintercard(array $keys, ?int $limit = null): mixed
    {
        $args = Args::new('SINTERCARD', count($keys))->pushAll($keys)->opt('LIMIT', $limit);

        return $this->dispatch($args->toArray());
    }

    /** @param list<string> $keys @return list<string> */
    public function sunion(array $keys): mixed
    {
        return $this->dispatch(array_merge(['SUNION'], $keys));
    }

    /** @param list<string> $keys @return int */
    public function sunionstore(string $destination, array $keys): mixed
    {
        return $this->dispatch(array_merge(['SUNIONSTORE', $destination], $keys));
    }

    /** @param list<string> $keys @return list<string> */
    public function sdiff(array $keys): mixed
    {
        return $this->dispatch(array_merge(['SDIFF'], $keys));
    }

    /** @param list<string> $keys @return int */
    public function sdiffstore(string $destination, array $keys): mixed
    {
        return $this->dispatch(array_merge(['SDIFFSTORE', $destination], $keys));
    }
}
