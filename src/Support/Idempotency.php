<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Support;

/**
 * Decides whether a command is a pure read that Client::execute() may
 * retry once, automatically, after a connection loss. Only commands that
 * cannot change server state are listed: a write is never retried blindly
 * even when it happens to be idempotent in effect (e.g. SET), because the
 * client cannot tell whether the original write reached the server before
 * the connection dropped.
 */
final class Idempotency
{
    /** @var array<string, true> */
    private const READ_ONLY = [
        'PING' => true, 'ECHO' => true, 'TIME' => true, 'DBSIZE' => true, 'INFO' => true,
        'LASTSAVE' => true, 'RANDOMKEY' => true, 'TYPE' => true, 'TTL' => true, 'PTTL' => true,
        'EXISTS' => true, 'KEYS' => true, 'SCAN' => true, 'DUMP' => true, 'OBJECT' => true,
        'MEMORY' => true, 'EXPIRETIME' => true, 'PEXPIRETIME' => true,
        'GET' => true, 'MGET' => true, 'STRLEN' => true, 'GETRANGE' => true, 'SUBSTR' => true,
        'HGET' => true, 'HMGET' => true, 'HGETALL' => true, 'HKEYS' => true, 'HVALS' => true,
        'HLEN' => true, 'HEXISTS' => true, 'HSTRLEN' => true, 'HRANDFIELD' => true, 'HSCAN' => true,
        'HTTL' => true, 'HPTTL' => true, 'HEXPIRETIME' => true, 'HPEXPIRETIME' => true,
        'LRANGE' => true, 'LLEN' => true, 'LINDEX' => true, 'LPOS' => true,
        'SMEMBERS' => true, 'SISMEMBER' => true, 'SMISMEMBER' => true, 'SCARD' => true,
        'SINTER' => true, 'SUNION' => true, 'SDIFF' => true, 'SRANDMEMBER' => true, 'SSCAN' => true,
        'ZRANGE' => true, 'ZRANGEBYSCORE' => true, 'ZRANGEBYLEX' => true, 'ZREVRANGE' => true,
        'ZREVRANGEBYSCORE' => true, 'ZREVRANGEBYLEX' => true, 'ZSCORE' => true, 'ZMSCORE' => true,
        'ZCARD' => true, 'ZCOUNT' => true, 'ZLEXCOUNT' => true, 'ZRANK' => true, 'ZREVRANK' => true,
        'ZRANDMEMBER' => true, 'ZSCAN' => true, 'ZDIFF' => true, 'ZINTER' => true, 'ZUNION' => true,
        'ZINTERCARD' => true,
        'GETBIT' => true, 'BITCOUNT' => true, 'BITPOS' => true, 'BITFIELD_RO' => true,
        'PFCOUNT' => true,
        'GEOPOS' => true, 'GEODIST' => true, 'GEOHASH' => true, 'GEOSEARCH' => true,
        'GEORADIUS_RO' => true, 'GEORADIUSBYMEMBER_RO' => true,
        'XRANGE' => true, 'XREVRANGE' => true, 'XLEN' => true, 'XREAD' => true,
        'XPENDING' => true, 'XINFO' => true,
        'SORT_RO' => true, 'SCRIPT' => true, 'LCS' => true, 'DIGEST' => true, 'ROLE' => true,
        'EVAL_RO' => true, 'EVALSHA_RO' => true,
        // Read only groups of the HotKV specific families (single dotted command tokens).
        'TAG.GET' => true, 'TAG.MEMBERS' => true, 'TAG.INTER' => true, 'TAG.COUNT' => true, 'TAG.EXISTS' => true,
        'NTTL' => true,
        'VSIM.GET' => true, 'VSIM.SEARCH' => true, 'VSIM.INFO' => true, 'VSIM.LIST' => true,
        'SCACHE.GET' => true, 'SCACHE.STATS' => true, 'SCACHE.LIST' => true,
        'PCACHE.GET' => true, 'PCACHE.GETEX' => true, 'PCACHE.STATS' => true, 'PCACHE.LIST' => true,
        'TOOL.CACHE.GET' => true, 'TOOL.CACHE.STATS' => true, 'TOOL.CACHE.LIST' => true,
        'NCACHE.GET' => true, 'NCACHE.STATS' => true, 'NCACHE.LIST' => true,
        'GUARD.GET' => true, 'GUARD.STATS' => true, 'GUARD.LIST' => true,
        'RERANK.GET' => true, 'RERANK.STATS' => true, 'RERANK.LIST' => true,
        'LLMSTATS.GET' => true, 'LLMSTATS.LIST' => true,
        'LLMBUDGET.STATUS' => true, 'LLMBUDGET.LIST' => true,
        'PROMPT.GET' => true, 'PROMPT.LATEST' => true, 'PROMPT.VERSIONS' => true, 'PROMPT.LIST' => true,
        'EMBED.DEDUP.GET' => true, 'EMBED.DEDUP.STATS' => true, 'EMBED.DEDUP.LIST' => true,
        'AMEM.CONV.GET' => true, 'AMEM.CONV.WINDOW' => true,
        'AMEM.SEM.RECALL' => true, 'AMEM.SEM.LIST' => true,
        'AMEM.WORK.GET' => true, 'AMEM.WORK.LIST' => true,
        'AMEM.EPIS.QUERY' => true, 'AMEM.EPIS.STATS' => true,
        'AMEM.SEARCH' => true,
        'AMEM.SUMMARY.GET' => true, 'AMEM.SUMMARY.LIST' => true,
        'RAG.SEARCH' => true, 'RAG.STATS' => true, 'RAG.LIST' => true,
        'RATELIMIT.PEEK' => true, 'RATELIMIT.STATS' => true, 'RATELIMIT.LIST' => true,
        'TS.RANGE' => true, 'TS.AGG' => true, 'TS.INFO' => true, 'TS.LIST' => true,
        'FEATURE.GET' => true, 'FEATURE.BATCH' => true, 'FEATURE.SCHEMA' => true,
        'FEATURE.LIST' => true, 'FEATURE.STATS' => true,
        'CLUSTER' => true,
    ];

    /** @param list<mixed> $args */
    public static function isReadOnly(array $args): bool
    {
        if ($args === []) {
            return false;
        }
        $cmd = strtoupper((string) $args[0]);

        if (isset(self::READ_ONLY[$cmd])) {
            return true;
        }

        $sub = isset($args[1]) ? strtoupper((string) $args[1]) : '';

        return match ($cmd) {
            'CONFIG' => $sub === 'GET',
            'CLIENT' => in_array($sub, ['GETNAME', 'ID', 'INFO', 'LIST'], true),
            'HOTKEYS' => $sub === 'TOP',
            'OBSERVE' => in_array($sub, ['INFO', 'METRICS'], true),
            'ALERTS' => $sub === 'INFO',
            'MEMORY' => true,
            default => false,
        };
    }
}
