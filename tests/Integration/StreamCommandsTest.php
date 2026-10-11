<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class StreamCommandsTest extends IntegrationTestCase
{
    public function testAddRangeLenDel(): void
    {
        $k = $this->key();
        $id1 = $this->client->xadd($k, ['field' => 'v1']);
        self::assertIsString($id1);
        $id2 = $this->client->xadd($k, ['field' => 'v2']);

        self::assertSame(2, $this->client->xlen($k));

        $entries = $this->client->xrange($k);
        self::assertCount(2, $entries);
        self::assertSame($id1, $entries[0][0]);
        self::assertSame(['field', 'v1'], $entries[0][1]);

        $rev = $this->client->xrevrange($k);
        self::assertSame($id2, $rev[0][0]);

        self::assertSame(1, $this->client->xdel($k, [$id1]));
        self::assertSame(1, $this->client->xlen($k));
    }

    public function testMaxlenTrim(): void
    {
        $k = $this->key();
        for ($i = 0; $i < 5; $i++) {
            $this->client->xadd($k, ['n' => (string) $i], maxlen: 3);
        }
        self::assertSame(3, $this->client->xlen($k));

        $this->client->xadd($k, ['n' => 'last']);
        self::assertSame(4, $this->client->xlen($k));
        self::assertSame(3, $this->client->xtrim($k, maxlen: 1));
        self::assertSame(1, $this->client->xlen($k));
    }

    public function testXreadNormalizesToMapKeyedByStream(): void
    {
        $k = $this->key();
        $this->client->xadd($k, ['a' => '1']);

        $result = $this->client->xread([$k => '0']);
        self::assertArrayHasKey($k, $result);
        self::assertCount(1, $result[$k]);
        self::assertSame(['a', '1'], $result[$k][0][1]);
    }

    public function testXreadBlockTimesOutToNull(): void
    {
        $k = $this->key();
        $this->client->xadd($k, ['a' => '1']);
        $result = $this->client->xread([$k => '$'], blockMillis: 100);
        self::assertNull($result);
    }

    public function testConsumerGroupLifecycle(): void
    {
        $k = $this->key();
        $this->client->xadd($k, ['a' => '1']);
        self::assertSame('OK', $this->client->xgroupCreate($k, 'grp', '0'));

        $read = $this->client->xreadgroup('grp', 'consumer-1', [$k => '>']);
        self::assertArrayHasKey($k, $read);
        $id = $read[$k][0][0];

        $pending = $this->client->xpending($k, 'grp');
        self::assertSame(1, $pending[0]);

        $extended = $this->client->xpendingExtended($k, 'grp', '-', '+', 10);
        self::assertSame($id, $extended[0][0]);
        self::assertSame('consumer-1', $extended[0][1]);

        self::assertSame(1, $this->client->xack($k, 'grp', [$id]));

        self::assertSame(1, $this->client->xgroupCreateConsumer($k, 'grp', 'consumer-2'));
        self::assertSame(0, $this->client->xgroupDelConsumer($k, 'grp', 'consumer-2'));
        self::assertSame(1, $this->client->xgroupDestroy($k, 'grp'));
    }

    public function testClaimAndAutoclaim(): void
    {
        $k = $this->key();
        $this->client->xadd($k, ['a' => '1']);
        $this->client->xgroupCreate($k, 'grp', '0');
        $read = $this->client->xreadgroup('grp', 'c1', [$k => '>']);
        $id = $read[$k][0][0];

        $claimed = $this->client->xclaim($k, 'grp', 'c2', 0, [$id]);
        self::assertSame($id, $claimed[0][0]);

        $auto = $this->client->xautoclaim($k, 'grp', 'c3', 0, '0');
        self::assertSame($id, $auto[1][0][0]);
    }

    public function testXsetidAndXinfoStream(): void
    {
        $k = $this->key();
        $this->client->xadd($k, ['a' => '1'], id: '5-1');
        self::assertSame('OK', $this->client->xsetid($k, '100-0'));

        $info = $this->client->xinfoStream($k);
        self::assertSame(1, $info['length']);
    }

    public function testXinfoGroupsAndConsumers(): void
    {
        $k = $this->key();
        $this->client->xadd($k, ['a' => '1']);
        $this->client->xgroupCreate($k, 'grp', '0');
        $this->client->xreadgroup('grp', 'c1', [$k => '>']);

        $groups = $this->client->xinfoGroups($k);
        self::assertSame('grp', $groups[0]['name']);

        $consumers = $this->client->xinfoConsumers($k, 'grp');
        self::assertSame('c1', $consumers[0]['name']);
    }
}
