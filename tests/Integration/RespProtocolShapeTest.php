<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

use HotKV\Client;
use HotKV\ClientOptions;

/**
 * Checks that HGETALL, HRANDFIELD WITHVALUES, SMEMBERS, ZSCORE, ZRANGE
 * WITHSCORES, ZPOPMIN with a count, ZRANDMEMBER WITHSCORES, XREAD, XINFO
 * STREAM, CONFIG GET and INFO decode to the same shape whether the
 * connection negotiated RESP3 (the default) or RESP2.
 */
final class RespProtocolShapeTest extends IntegrationTestCase
{
    public function testResp3(): void
    {
        $this->runChecks(3, 'resp3');
    }

    public function testResp2(): void
    {
        $this->runChecks(2, 'resp2');
    }

    private function runChecks(int $protocol, string $label): void
    {
        $url = getenv('HOTKV_TEST_URL');
        self::assertIsString($url);
        $client = new Client(ClientOptions::fromUrl($url, ['protocol' => $protocol]));

        $hkey = $this->key("conform-{$label}-hash");
        $skey = $this->key("conform-{$label}-set");
        $zkey = $this->key("conform-{$label}-zset");
        $xkey = $this->key("conform-{$label}-stream");

        $client->hset($hkey, ['f1' => 'v1', 'f2' => 'v2']);
        self::assertSame(['f1' => 'v1', 'f2' => 'v2'], $client->hgetall($hkey));

        $fvs = $client->hrandfield($hkey, 2, withValues: true);
        self::assertCount(2, $fvs);
        foreach ($fvs as [$field, $value]) {
            self::assertSame($field === 'f1' ? 'v1' : 'v2', $value);
        }

        $client->sadd($skey, ['a', 'b', 'c']);
        $members = $client->smembers($skey);
        sort($members);
        self::assertSame(['a', 'b', 'c'], $members);

        $client->zadd($zkey, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0]);
        $this->assertScore(2.0, $client->zscore($zkey, 'b'));

        $withScores = $client->zrange($zkey, '0', '-1', withScores: true);
        self::assertSame(['a', 'b', 'c'], array_keys($withScores));
        $this->assertScore(1.0, $withScores['a']);
        $this->assertScore(2.0, $withScores['b']);
        $this->assertScore(3.0, $withScores['c']);

        $popped = $client->zpopmin($zkey, 2);
        self::assertSame(['a', 'b'], [$popped[0][0], $popped[1][0]]);
        $this->assertScore(1.0, $popped[0][1]);
        $this->assertScore(2.0, $popped[1][1]);

        $client->zadd($zkey, ['a' => 1.0, 'b' => 2.0]);
        $randScored = $client->zrandmember($zkey, 3, withScores: true);
        self::assertCount(3, $randScored);

        $id1 = $client->xadd($xkey, ['field1' => 'v1']);
        $id2 = $client->xadd($xkey, ['field2' => 'v2']);
        $streams = $client->xread([$xkey => '0']);
        self::assertCount(1, $streams);
        $entries = $streams[$xkey];
        self::assertCount(2, $entries);
        self::assertSame($id1, $entries[0][0]);
        self::assertSame(['field1', 'v1'], $entries[0][1]);
        self::assertSame($id2, $entries[1][0]);

        $info = $client->xinfoStream($xkey);
        self::assertSame(2, $info['length']);
        self::assertSame($id2, $info['last-generated-id']);

        $cfg = $client->configGet('maxmemory');
        self::assertArrayHasKey('maxmemory', $cfg);

        $infoStr = $client->info();
        self::assertIsString($infoStr);
        self::assertStringStartsNotWith('txt:', $infoStr);
        self::assertStringStartsNotWith('mkd:', $infoStr);
        self::assertStringContainsString('hotkv_version', $infoStr);

        $client->close();
    }
}
