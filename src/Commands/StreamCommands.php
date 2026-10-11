<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\Support\Args;
use HotKV\Support\Reply;

/**
 * Stream commands. Requires the composing class to implement
 * HotKV\CommandDispatcher.
 */
trait StreamCommands
{
    use NormalizesReplies;

    /**
     * @param array<string, string> $fields field => value, in the given order
     * @return string the entry ID assigned (or supplied via $id)
     */
    public function xadd(
        string $key,
        array $fields,
        string $id = '*',
        bool $nomkstream = false,
        ?int $maxlen = null,
        ?string $minid = null,
        bool $approximate = false,
        ?int $limit = null,
    ): mixed {
        $args = Args::new('XADD', $key)->flag('NOMKSTREAM', $nomkstream);
        if ($maxlen !== null) {
            $args->push('MAXLEN', $approximate ? '~' : '=', $maxlen);
        } elseif ($minid !== null) {
            $args->push('MINID', $approximate ? '~' : '=', $minid);
        }
        $args->opt('LIMIT', $limit);
        $args->push($id);
        foreach ($fields as $field => $value) {
            $args->push((string) $field, $value);
        }

        return $this->dispatch($args->toArray());
    }

    /** @return list<array{0: string, 1: list<string>}> [id, [field, value, ...]] per entry */
    public function xrange(string $key, string $start = '-', string $end = '+', ?int $count = null): mixed
    {
        return $this->dispatch(Args::new('XRANGE', $key, $start, $end)->opt('COUNT', $count)->toArray());
    }

    /** @return list<array{0: string, 1: list<string>}> */
    public function xrevrange(string $key, string $end = '+', string $start = '-', ?int $count = null): mixed
    {
        return $this->dispatch(Args::new('XREVRANGE', $key, $end, $start)->opt('COUNT', $count)->toArray());
    }

    /**
     * @param array<string, string> $streams stream key => last seen ID ("$" for only new entries)
     * @return array<string, list<array{0: string, 1: list<string>}>>|null null on a BLOCK timeout
     */
    public function xread(array $streams, ?int $count = null, ?int $blockMillis = null): mixed
    {
        $args = Args::new('XREAD')->opt('COUNT', $count)->opt('BLOCK', $blockMillis)->push('STREAMS');
        foreach (array_keys($streams) as $key) {
            $args->push((string) $key);
        }
        foreach ($streams as $id) {
            $args->push($id);
        }

        return $this->normalized($this->dispatch($args->toArray()), self::normalizeXRead(...));
    }

    /**
     * @param array<string, string> $streams stream key => ID (">" for new, undelivered entries)
     * @return array<string, list<array{0: string, 1: list<string>}>>|null
     */
    public function xreadgroup(string $group, string $consumer, array $streams, ?int $count = null, ?int $blockMillis = null, bool $noack = false): mixed
    {
        $args = Args::new('XREADGROUP', 'GROUP', $group, $consumer)
            ->opt('COUNT', $count)->opt('BLOCK', $blockMillis)->flag('NOACK', $noack)->push('STREAMS');
        foreach (array_keys($streams) as $key) {
            $args->push((string) $key);
        }
        foreach ($streams as $id) {
            $args->push($id);
        }

        return $this->normalized($this->dispatch($args->toArray()), self::normalizeXRead(...));
    }

    /** @param list<string> $ids @return int entries acknowledged */
    public function xack(string $key, string $group, array $ids): mixed
    {
        return $this->dispatch(array_merge(['XACK', $key, $group], $ids));
    }

    /** @return string "OK" */
    public function xgroupCreate(string $key, string $group, string $id = '$', bool $mkstream = false): mixed
    {
        return $this->dispatch(Args::new('XGROUP', 'CREATE', $key, $group, $id)->flag('MKSTREAM', $mkstream)->toArray());
    }

    public function xgroupSetId(string $key, string $group, string $id = '$'): mixed
    {
        return $this->dispatch(['XGROUP', 'SETID', $key, $group, $id]);
    }

    /** @return int 1 */
    public function xgroupCreateConsumer(string $key, string $group, string $consumer): mixed
    {
        return $this->dispatch(['XGROUP', 'CREATECONSUMER', $key, $group, $consumer]);
    }

    /** @return int pending entries owned by the removed consumer */
    public function xgroupDelConsumer(string $key, string $group, string $consumer): mixed
    {
        return $this->dispatch(['XGROUP', 'DELCONSUMER', $key, $group, $consumer]);
    }

    /** @return int 1/0 */
    public function xgroupDestroy(string $key, string $group): mixed
    {
        return $this->dispatch(['XGROUP', 'DESTROY', $key, $group]);
    }

    /** Summary form. @return list<mixed> [count, smallestId, greatestId, [[consumer, count], ...]] */
    public function xpending(string $key, string $group): mixed
    {
        return $this->dispatch(['XPENDING', $key, $group]);
    }

    /** Extended form. @return list<array{0: string, 1: string, 2: int, 3: int}> [id, consumer, idleMs, deliveryCount] */
    public function xpendingExtended(
        string $key,
        string $group,
        string $start,
        string $end,
        int $count,
        ?string $consumer = null,
        ?int $idleMillis = null,
    ): mixed {
        $args = Args::new('XPENDING', $key, $group)->opt('IDLE', $idleMillis)->push($start, $end, $count);
        if ($consumer !== null) {
            $args->push($consumer);
        }

        return $this->dispatch($args->toArray());
    }

    /** @param list<string> $ids @return list<array{0: string, 1: list<string>}>|list<string> claimed entries, or bare IDs if $justId */
    public function xclaim(
        string $key,
        string $group,
        string $consumer,
        int $minIdleMillis,
        array $ids,
        ?int $idle = null,
        ?int $time = null,
        ?int $retryCount = null,
        bool $force = false,
        bool $justId = false,
    ): mixed {
        $args = Args::new('XCLAIM', $key, $group, $consumer, $minIdleMillis)->pushAll($ids)
            ->opt('IDLE', $idle)->opt('TIME', $time)->opt('RETRYCOUNT', $retryCount)
            ->flag('FORCE', $force)->flag('JUSTID', $justId);

        return $this->dispatch($args->toArray());
    }

    /** @return array{0: string, 1: list<array{0: string, 1: list<string>}>, 2: list<string>} [nextCursor, claimedEntries, deletedIds] */
    public function xautoclaim(
        string $key,
        string $group,
        string $consumer,
        int $minIdleMillis,
        string $start,
        ?int $count = null,
        bool $justId = false,
    ): mixed {
        $args = Args::new('XAUTOCLAIM', $key, $group, $consumer, $minIdleMillis, $start)->opt('COUNT', $count)->flag('JUSTID', $justId);

        return $this->dispatch($args->toArray());
    }

    /** @return int entries removed */
    public function xtrim(string $key, ?int $maxlen = null, ?string $minid = null, bool $approximate = false, ?int $limit = null): mixed
    {
        $args = Args::new('XTRIM', $key);
        if ($maxlen !== null) {
            $args->push('MAXLEN', $approximate ? '~' : '=', $maxlen);
        } elseif ($minid !== null) {
            $args->push('MINID', $approximate ? '~' : '=', $minid);
        }
        $args->opt('LIMIT', $limit);

        return $this->dispatch($args->toArray());
    }

    public function xlen(string $key): mixed
    {
        return $this->dispatch(['XLEN', $key]);
    }

    /** @param list<string> $ids @return int entries deleted */
    public function xdel(string $key, array $ids): mixed
    {
        return $this->dispatch(array_merge(['XDEL', $key], $ids));
    }

    /** @return string "OK" */
    public function xsetid(string $key, string $id, ?int $entriesAdded = null, ?int $maxDeletedId = null): mixed
    {
        $args = Args::new('XSETID', $key, $id)->opt('ENTRIESADDED', $entriesAdded)->opt('MAXDELETEDID', $maxDeletedId);

        return $this->dispatch($args->toArray());
    }

    /** @return array<string, mixed> stream summary fields (length, last-generated-id, ...) */
    public function xinfoStream(string $key): mixed
    {
        return $this->normalized($this->dispatch(['XINFO', 'STREAM', $key]), Reply::toMap(...));
    }

    /** @return list<array<string, mixed>> */
    public function xinfoGroups(string $key): mixed
    {
        return $this->normalized(
            $this->dispatch(['XINFO', 'GROUPS', $key]),
            static fn (?array $v) => array_map(Reply::toMap(...), $v ?? []),
        );
    }

    /** @return list<array<string, mixed>> */
    public function xinfoConsumers(string $key, string $group): mixed
    {
        return $this->normalized(
            $this->dispatch(['XINFO', 'CONSUMERS', $key, $group]),
            static fn (?array $v) => array_map(Reply::toMap(...), $v ?? []),
        );
    }

    private static function normalizeXRead(mixed $value): mixed
    {
        if ($value === null || !is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            return $value;
        }

        return Reply::pairsToMap($value);
    }
}
