<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Commands;

use HotKV\Protocol\Writer;
use HotKV\Support\Args;
use HotKV\Support\Reply;

/**
 * Sorted set commands. Requires the composing class to implement
 * HotKV\CommandDispatcher.
 */
trait SortedSetCommands
{
    use NormalizesReplies;
    /**
     * @param array<string, float> $members member => score
     * @return int|float|string number of new members added, or (with $incr) the new score
     */
    public function zadd(
        string $key,
        array $members,
        bool $nx = false,
        bool $xx = false,
        bool $gt = false,
        bool $lt = false,
        bool $ch = false,
        bool $incr = false,
    ): mixed {
        $args = Args::new('ZADD', $key)->flag('NX', $nx)->flag('XX', $xx)->flag('GT', $gt)->flag('LT', $lt)
            ->flag('CH', $ch)->flag('INCR', $incr);
        foreach ($members as $member => $score) {
            $args->push(Writer::formatFloat($score), (string) $member);
        }

        return $this->dispatch($args->toArray());
    }

    /** @param list<string> $members @return int number removed */
    public function zrem(string $key, array $members): mixed
    {
        return $this->dispatch(array_merge(['ZREM', $key], $members));
    }

    /** @return int number of members removed, by 0-based rank range (negative indexes count from the end) */
    public function zremrangebyrank(string $key, int $start, int $stop): mixed
    {
        return $this->dispatch(['ZREMRANGEBYRANK', $key, $start, $stop]);
    }

    /**
     * @param string $min @param string $max inclusive/exclusive score bounds, e.g. "1", "(1", "-inf", "+inf"
     * @return int number of members removed
     */
    public function zremrangebyscore(string $key, string $min, string $max): mixed
    {
        return $this->dispatch(['ZREMRANGEBYSCORE', $key, $min, $max]);
    }

    /**
     * @param string $min @param string $max lexicographic bounds, e.g. "[a", "(z", "-", "+"
     * @return int number of members removed
     */
    public function zremrangebylex(string $key, string $min, string $max): mixed
    {
        return $this->dispatch(['ZREMRANGEBYLEX', $key, $min, $max]);
    }

    /** @return int|float|string|null the score: a RESP3 double under HELLO 3, a bulk string under RESP2 */
    public function zscore(string $key, string $member): mixed
    {
        return $this->dispatch(['ZSCORE', $key, $member]);
    }

    /** @param list<string> $members @return list<int|float|string|null> */
    public function zmscore(string $key, array $members): mixed
    {
        return $this->dispatch(array_merge(['ZMSCORE', $key], $members));
    }

    /** @return int|float|string the new score */
    public function zincrby(string $key, float $by, string $member): mixed
    {
        return $this->dispatch(['ZINCRBY', $key, Writer::formatFloat($by), $member]);
    }

    public function zcard(string $key): mixed
    {
        return $this->dispatch(['ZCARD', $key]);
    }

    /** @param string $min @param string $max inclusive/exclusive range bounds, e.g. "1", "(1", "-inf", "+inf" */
    public function zcount(string $key, string $min, string $max): mixed
    {
        return $this->dispatch(['ZCOUNT', $key, $min, $max]);
    }

    /** @param string $min @param string $max lexicographic bounds, e.g. "[a", "(z", "-", "+" */
    public function zlexcount(string $key, string $min, string $max): mixed
    {
        return $this->dispatch(['ZLEXCOUNT', $key, $min, $max]);
    }

    /**
     * The unified ZRANGE: pass $byScore or $byLex to change how $start/$stop
     * are interpreted, exactly like the server (index range by default).
     *
     * @return list<string>|array<string, int|float|string> a list of members, or member => score (int|float|string) when $withScores is true
     */
    public function zrange(
        string $key,
        string $start,
        string $stop,
        bool $byScore = false,
        bool $byLex = false,
        bool $rev = false,
        ?int $limitOffset = null,
        ?int $limitCount = null,
        bool $withScores = false,
    ): mixed {
        $args = Args::new('ZRANGE', $key, $start, $stop)->flag('BYSCORE', $byScore)->flag('BYLEX', $byLex)->flag('REV', $rev);
        if ($limitOffset !== null && $limitCount !== null) {
            $args->push('LIMIT', $limitOffset, $limitCount);
        }
        $args->flag('WITHSCORES', $withScores);

        $result = $this->dispatch($args->toArray());

        return $withScores ? $this->normalized($result, Reply::toPairMap(...)) : $result;
    }

    /** @return list<string>|array<string, int|float|string> */
    public function zrangebyscore(string $key, string $min, string $max, bool $withScores = false, ?int $limitOffset = null, ?int $limitCount = null): mixed
    {
        $args = Args::new('ZRANGEBYSCORE', $key, $min, $max)->flag('WITHSCORES', $withScores);
        if ($limitOffset !== null && $limitCount !== null) {
            $args->push('LIMIT', $limitOffset, $limitCount);
        }

        $result = $this->dispatch($args->toArray());

        return $withScores ? $this->normalized($result, Reply::toPairMap(...)) : $result;
    }

    /** @return list<string>|array<string, int|float|string> */
    public function zrevrangebyscore(string $key, string $max, string $min, bool $withScores = false, ?int $limitOffset = null, ?int $limitCount = null): mixed
    {
        $args = Args::new('ZREVRANGEBYSCORE', $key, $max, $min)->flag('WITHSCORES', $withScores);
        if ($limitOffset !== null && $limitCount !== null) {
            $args->push('LIMIT', $limitOffset, $limitCount);
        }

        $result = $this->dispatch($args->toArray());

        return $withScores ? $this->normalized($result, Reply::toPairMap(...)) : $result;
    }

    /** @return list<string> */
    public function zrangebylex(string $key, string $min, string $max, ?int $limitOffset = null, ?int $limitCount = null): mixed
    {
        $args = Args::new('ZRANGEBYLEX', $key, $min, $max);
        if ($limitOffset !== null && $limitCount !== null) {
            $args->push('LIMIT', $limitOffset, $limitCount);
        }

        return $this->dispatch($args->toArray());
    }

    /** @return list<string> */
    public function zrevrangebylex(string $key, string $max, string $min, ?int $limitOffset = null, ?int $limitCount = null): mixed
    {
        $args = Args::new('ZREVRANGEBYLEX', $key, $max, $min);
        if ($limitOffset !== null && $limitCount !== null) {
            $args->push('LIMIT', $limitOffset, $limitCount);
        }

        return $this->dispatch($args->toArray());
    }

    /** @return list<string>|array<string, int|float|string> */
    public function zrevrange(string $key, int $start, int $stop, bool $withScores = false): mixed
    {
        $args = Args::new('ZREVRANGE', $key, $start, $stop)->flag('WITHSCORES', $withScores);

        $result = $this->dispatch($args->toArray());

        return $withScores ? $this->normalized($result, Reply::toPairMap(...)) : $result;
    }

    /** @return int the number of elements stored in $destination */
    public function zrangestore(
        string $destination,
        string $source,
        string $start,
        string $stop,
        bool $byScore = false,
        bool $byLex = false,
        bool $rev = false,
        ?int $limitOffset = null,
        ?int $limitCount = null,
    ): mixed {
        $args = Args::new('ZRANGESTORE', $destination, $source, $start, $stop)
            ->flag('BYSCORE', $byScore)->flag('BYLEX', $byLex)->flag('REV', $rev);
        if ($limitOffset !== null && $limitCount !== null) {
            $args->push('LIMIT', $limitOffset, $limitCount);
        }

        return $this->dispatch($args->toArray());
    }

    /** @return int|array{0: int, 1: int|float|string}|null the rank, or (with $withScore) [rank, score]; null if the member is missing */
    public function zrank(string $key, string $member, bool $withScore = false): mixed
    {
        $args = $withScore ? ['ZRANK', $key, $member, 'WITHSCORE'] : ['ZRANK', $key, $member];

        return $this->dispatch($args);
    }

    public function zrevrank(string $key, string $member, bool $withScore = false): mixed
    {
        $args = $withScore ? ['ZREVRANK', $key, $member, 'WITHSCORE'] : ['ZREVRANK', $key, $member];

        return $this->dispatch($args);
    }

    /** @return list<int|float|string>|list<array{0: string, 1: int|float|string}> [member, score] when $count is null, else a list of [member, score] pairs */
    public function zpopmin(string $key, ?int $count = null): mixed
    {
        if ($count === null) {
            return $this->dispatch(['ZPOPMIN', $key]);
        }

        return $this->normalized($this->dispatch(['ZPOPMIN', $key, $count]), Reply::toPairList(...));
    }

    /** @return list<int|float|string>|list<array{0: string, 1: int|float|string}> [member, score] when $count is null, else a list of [member, score] pairs */
    public function zpopmax(string $key, ?int $count = null): mixed
    {
        if ($count === null) {
            return $this->dispatch(['ZPOPMAX', $key]);
        }

        return $this->normalized($this->dispatch(['ZPOPMAX', $key, $count]), Reply::toPairList(...));
    }

    /** @param list<string> $keys @return list<string>|null [key, member, score (int|float|string)], or null on timeout */
    public function bzpopmin(array $keys, float $timeoutSeconds): mixed
    {
        return $this->dispatch(array_merge(['BZPOPMIN'], $keys, [Writer::formatFloat($timeoutSeconds)]));
    }

    /** @param list<string> $keys @return list<string>|null */
    public function bzpopmax(array $keys, float $timeoutSeconds): mixed
    {
        return $this->dispatch(array_merge(['BZPOPMAX'], $keys, [Writer::formatFloat($timeoutSeconds)]));
    }

    /** @return string|list<string>|list<array{0: string, 1: int|float|string}>|null a single member, a list of members, or (with $withScores) a list of [member, score] pairs */
    public function zrandmember(string $key, ?int $count = null, bool $withScores = false): mixed
    {
        if ($count === null) {
            return $this->dispatch(['ZRANDMEMBER', $key]);
        }
        $args = Args::new('ZRANDMEMBER', $key, $count)->flag('WITHSCORES', $withScores);
        $result = $this->dispatch($args->toArray());

        return $withScores ? $this->normalized($result, Reply::toPairList(...)) : $result;
    }

    /** @param list<string> $keys @return list<string>|array<string, int|float|string> */
    public function zdiff(array $keys, bool $withScores = false): mixed
    {
        $args = Args::new('ZDIFF', count($keys))->pushAll($keys)->flag('WITHSCORES', $withScores);

        $result = $this->dispatch($args->toArray());

        return $withScores ? $this->normalized($result, Reply::toPairMap(...)) : $result;
    }

    /** @param list<string> $keys @return int */
    public function zdiffstore(string $destination, array $keys): mixed
    {
        return $this->dispatch(array_merge(['ZDIFFSTORE', $destination, count($keys)], $keys));
    }

    /** @param list<string> $keys @param list<float>|null $weights */
    public function zinter(array $keys, ?array $weights = null, ?string $aggregate = null, bool $withScores = false): mixed
    {
        $args = $this->zSetOpArgs('ZINTER', $keys, $weights, $aggregate)->flag('WITHSCORES', $withScores);

        $result = $this->dispatch($args->toArray());

        return $withScores ? $this->normalized($result, Reply::toPairMap(...)) : $result;
    }

    /** @param list<string> $keys @param list<float>|null $weights @return int */
    public function zinterstore(string $destination, array $keys, ?array $weights = null, ?string $aggregate = null): mixed
    {
        $args = Args::new('ZINTERSTORE', $destination);
        $args = $this->zSetOpArgs('', $keys, $weights, $aggregate, $args);

        return $this->dispatch($args->toArray());
    }

    /** @param list<string> $keys @return int the size of the intersection, without storing it */
    public function zintercard(array $keys, ?int $limit = null): mixed
    {
        $args = Args::new('ZINTERCARD', count($keys))->pushAll($keys)->opt('LIMIT', $limit);

        return $this->dispatch($args->toArray());
    }

    /** @param list<string> $keys @param list<float>|null $weights */
    public function zunion(array $keys, ?array $weights = null, ?string $aggregate = null, bool $withScores = false): mixed
    {
        $args = $this->zSetOpArgs('ZUNION', $keys, $weights, $aggregate)->flag('WITHSCORES', $withScores);

        $result = $this->dispatch($args->toArray());

        return $withScores ? $this->normalized($result, Reply::toPairMap(...)) : $result;
    }

    /** @param list<string> $keys @param list<float>|null $weights @return int */
    public function zunionstore(string $destination, array $keys, ?array $weights = null, ?string $aggregate = null): mixed
    {
        $args = Args::new('ZUNIONSTORE', $destination);
        $args = $this->zSetOpArgs('', $keys, $weights, $aggregate, $args);

        return $this->dispatch($args->toArray());
    }

    /**
     * @param list<string> $keys
     * @param list<float>|null $weights
     */
    private function zSetOpArgs(string $cmd, array $keys, ?array $weights, ?string $aggregate, ?Args $args = null): Args
    {
        $args ??= Args::new($cmd);
        $args->push(count($keys))->pushAll($keys);
        if ($weights !== null) {
            $args->push('WEIGHTS');
            foreach ($weights as $w) {
                $args->push(Writer::formatFloat($w));
            }
        }
        if ($aggregate !== null) {
            $args->push('AGGREGATE', strtoupper($aggregate));
        }

        return $args;
    }

    /**
     * ZMPOP: pops the lowest ($max false) or highest ($max true) scored
     * members of the first non-empty sorted set.
     *
     * @param list<string> $keys
     * @return array{0: string, 1: list<array{0: string, 1: int|float|string}>}|null [key, [[member, score], ...]], or null
     */
    public function zmpop(array $keys, bool $max = false, ?int $count = null): mixed
    {
        $args = Args::new('ZMPOP', count($keys))->pushAll($keys)->push($max ? 'MAX' : 'MIN')->opt('COUNT', $count);

        return $this->dispatch($args->toArray());
    }

    /**
     * BZMPOP: ZMPOP that waits up to $timeoutSeconds (0 = forever).
     *
     * @param list<string> $keys
     * @return array{0: string, 1: list<array{0: string, 1: int|float|string}>}|null
     */
    public function bzmpop(float $timeoutSeconds, array $keys, bool $max = false, ?int $count = null): mixed
    {
        $args = Args::new('BZMPOP', \HotKV\Support\Numbers::finite($timeoutSeconds), count($keys))->pushAll($keys)
            ->push($max ? 'MAX' : 'MIN')->opt('COUNT', $count);

        return $this->dispatch($args->toArray());
    }
}
