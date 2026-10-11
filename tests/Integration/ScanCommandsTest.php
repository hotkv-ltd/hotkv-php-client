<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class ScanCommandsTest extends IntegrationTestCase
{
    public function testScanFindsAllMatchingKeys(): void
    {
        $prefix = 'hotkv-php-test:scan:' . bin2hex(random_bytes(4));
        $expected = [];
        for ($i = 0; $i < 20; $i++) {
            $k = "{$prefix}:{$i}";
            $this->client->set($k, 'v');
            $expected[] = $k;
        }

        $found = iterator_to_array($this->client->scan(match: "{$prefix}:*", count: 5), false);
        sort($found);
        sort($expected);
        self::assertSame($expected, $found);

        $this->client->del($expected);
    }

    public function testSscan(): void
    {
        $k = $this->key();
        $members = array_map(static fn ($i) => "m{$i}", range(1, 15));
        $this->client->sadd($k, $members);

        $found = iterator_to_array($this->client->sscan($k, count: 3), false);
        sort($found);
        sort($members);
        self::assertSame($members, $found);
    }

    public function testHscan(): void
    {
        $k = $this->key();
        $fields = [];
        for ($i = 0; $i < 15; $i++) {
            $fields["f{$i}"] = "v{$i}";
        }
        $this->client->hset($k, $fields);

        $found = [];
        foreach ($this->client->hscan($k, count: 3) as $field => $value) {
            $found[$field] = $value;
        }
        ksort($found);
        ksort($fields);
        self::assertSame($fields, $found);
    }

    public function testZscan(): void
    {
        $k = $this->key();
        $members = [];
        for ($i = 0; $i < 15; $i++) {
            $members["m{$i}"] = (float) $i;
        }
        $this->client->zadd($k, $members);

        $found = [];
        foreach ($this->client->zscan($k, count: 3) as $member => $score) {
            $found[$member] = (float) $score;
        }
        ksort($found);
        ksort($members);
        self::assertEquals($members, $found);
    }

    public function testScanWithTypeFilter(): void
    {
        $strKey = $this->key('type-str');
        $listKey = $this->key('type-list');
        $this->client->set($strKey, 'v');
        $this->client->rpush($listKey, ['v']);

        $found = iterator_to_array($this->client->scan(match: 'hotkv-php-test:*type-*', count: 100, type: 'string'), false);
        self::assertContains($strKey, $found);
        self::assertNotContains($listKey, $found);
    }
}
