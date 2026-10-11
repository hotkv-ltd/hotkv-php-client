<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class GeoCommandsTest extends IntegrationTestCase
{
    public function testAddPosAndDist(): void
    {
        $k = $this->key();
        self::assertSame(2, $this->client->geoadd($k, [
            'Palermo' => [13.361389, 38.115556],
            'Catania' => [15.087269, 37.502669],
        ]));

        $pos = $this->client->geopos($k, ['Palermo', 'missing']);
        self::assertNull($pos[1]);
        $this->assertScore(13.361389, $pos[0][0], 1e-4);
        $this->assertScore(38.115556, $pos[0][1], 1e-4);

        $dist = $this->client->geodist($k, 'Palermo', 'Catania', 'km');
        self::assertEqualsWithDelta(166.2742, (float) $dist, 0.01);
    }

    public function testGeohash(): void
    {
        $k = $this->key();
        $this->client->geoadd($k, ['Palermo' => [13.361389, 38.115556]]);
        $hashes = $this->client->geohash($k, ['Palermo']);
        self::assertIsString($hashes[0]);
        self::assertStringStartsWith('sqc8b4', $hashes[0]);
    }

    public function testGeoaddNxAndXx(): void
    {
        $k = $this->key();
        $this->client->geoadd($k, ['a' => [1.0, 1.0]]);
        self::assertSame(0, $this->client->geoadd($k, ['a' => [2.0, 2.0]], nx: true));
        $pos = $this->client->geopos($k, ['a']);
        self::assertEqualsWithDelta(1.0, (float) $pos[0][0], 1e-3);
    }

    public function testGeosearchByRadiusWithDist(): void
    {
        $k = $this->key();
        $this->client->geoadd($k, [
            'Palermo' => [13.361389, 38.115556],
            'Catania' => [15.087269, 37.502669],
        ]);

        $results = $this->client->geosearch($k, fromMember: 'Palermo', byRadius: 200.0, unit: 'km', asc: true, withDist: true);
        self::assertCount(2, $results);
        self::assertSame('Palermo', $results[0][0]);
        self::assertSame('Catania', $results[1][0]);
    }

    public function testGeosearchByBox(): void
    {
        $k = $this->key();
        $this->client->geoadd($k, [
            'Palermo' => [13.361389, 38.115556],
            'Catania' => [15.087269, 37.502669],
        ]);

        $results = $this->client->geosearch($k, fromLon: 15.0, fromLat: 37.5, byBoxWidth: 400.0, byBoxHeight: 400.0, unit: 'km');
        self::assertContains('Catania', $results);
    }

    public function testGeosearchstore(): void
    {
        $k = $this->key();
        $dest = $this->key();
        $this->client->geoadd($k, [
            'Palermo' => [13.361389, 38.115556],
            'Catania' => [15.087269, 37.502669],
        ]);
        self::assertSame(2, $this->client->geosearchstore($dest, $k, fromMember: 'Palermo', byRadius: 200.0, unit: 'km'));
    }

    public function testGeoradiusLegacy(): void
    {
        $k = $this->key();
        $this->client->geoadd($k, ['Palermo' => [13.361389, 38.115556]]);
        $results = $this->client->georadius($k, 13.361389, 38.115556, 10.0, 'km');
        self::assertSame(['Palermo'], $results);
    }

    public function testGeoradiusbymemberLegacy(): void
    {
        $k = $this->key();
        $this->client->geoadd($k, [
            'Palermo' => [13.361389, 38.115556],
            'Catania' => [15.087269, 37.502669],
        ]);
        $results = $this->client->georadiusbymember($k, 'Palermo', 200.0, 'km');
        self::assertContains('Catania', $results);
    }
}
