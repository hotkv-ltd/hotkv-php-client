<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

/**
 * Live check that floats written as plain decimal text (never "1.0E-5")
 * are accepted by the real server and read back as the same value, for
 * the core commands that take floats.
 */
final class FloatFormattingTest extends IntegrationTestCase
{
    public function testZaddAcceptsTinyHugeAndNegativeScores(): void
    {
        $k = $this->key();
        $scores = [
            'tiny' => 1.0E-5,
            'tinier' => 1.5E-300,
            'huge' => 1.0E+25,
            'hugest' => 1.0E+300,
            'negative' => -2.5E-7,
            'neg-huge' => -1.0E+22,
            'whole' => 3.0,
        ];
        self::assertSame(count($scores), $this->client->zadd($k, $scores));
        foreach ($scores as $member => $score) {
            $stored = (float) $this->client->zscore($k, $member);
            self::assertSame($score, $stored, "score of {$member}");
        }
    }

    public function testZaddAcceptsInfiniteScores(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['up' => INF, 'down' => -INF, 'mid' => 0.0]);
        self::assertSame(['down', 'mid', 'up'], $this->client->zrange($k, '0', '-1'));
    }

    public function testZincrbyWithTinyIncrements(): void
    {
        $k = $this->key();
        $this->client->zadd($k, ['m' => 0.0]);
        $this->client->zincrby($k, 1.0E-5, 'm');
        $this->client->zincrby($k, 2.0E-5, 'm');
        $this->assertScore(3.0E-5, $this->client->zscore($k, 'm'), 1e-12);
    }

    public function testIncrbyfloatTinyHugeAndNegative(): void
    {
        $k = $this->key();
        self::assertSame('0.00001', (string) $this->client->incrbyfloat($k, 1.0E-5));
        self::assertSame('0.00003', (string) $this->client->incrbyfloat($k, 2.0E-5));
        self::assertSame('0.00002', (string) $this->client->incrbyfloat($k, -1.0E-5));

        $big = $this->key();
        $result = $this->client->incrbyfloat($big, 1.0E+25);
        self::assertEqualsWithDelta(1.0E+25, (float) $result, 1.0E+10);
        $neg = $this->key();
        self::assertEqualsWithDelta(-4.0E+20, (float) $this->client->incrbyfloat($neg, -4.0E+20), 1.0E+6);
    }

    public function testHincrbyfloatTinyAndNegative(): void
    {
        $k = $this->key();
        self::assertSame('0.00001', (string) $this->client->hincrbyfloat($k, 'f', 1.0E-5));
        self::assertSame('-0.00001', (string) $this->client->hincrbyfloat($k, 'f', -2.0E-5));
    }

    public function testGeoaddTinyAndNegativeCoordinates(): void
    {
        $k = $this->key();
        self::assertSame(3, $this->client->geoadd($k, [
            'tiny' => [1.0E-5, 2.0E-5],
            'neg' => [-1.0E-4, -3.0E-5],
            'plain' => [13.361389, 38.115556],
        ]));
        $pos = $this->client->geopos($k, ['tiny', 'neg', 'plain']);
        self::assertEqualsWithDelta(1.0E-5, (float) $pos[0][0], 1.0E-5);
        self::assertEqualsWithDelta(2.0E-5, (float) $pos[0][1], 1.0E-5);
        self::assertEqualsWithDelta(-1.0E-4, (float) $pos[1][0], 1.0E-5);
        self::assertEqualsWithDelta(13.361389, (float) $pos[2][0], 1.0E-5);

        // A tiny search origin and radius are also sent as plain text. The
        // 1e-5 km (1 cm) radius is below the geohash resolution, so it only
        // has to be accepted; the 50 cm one must find the member.
        self::assertIsArray($this->client->geosearch($k, fromLon: 1.0E-5, fromLat: 2.0E-5, byRadius: 1.0E-5, unit: 'km'));
        self::assertSame(['tiny'], $this->client->geosearch($k, fromLon: 1.0E-5, fromLat: 2.0E-5, byRadius: 5.0E-4, unit: 'km'));
    }

    public function testBlockingTimeoutsAcceptFractionalSeconds(): void
    {
        $k = $this->key();
        self::assertNull($this->client->blpop([$k], 0.05));
        self::assertNull($this->client->brpop([$k], 1.0E-1));
        self::assertNull($this->client->bzpopmin([$k], 0.05));
    }
}
