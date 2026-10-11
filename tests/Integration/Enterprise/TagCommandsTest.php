<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;

final class TagCommandsTest extends IntegrationTestCase
{
    public function testSetGetRemCountExists(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v');

        self::assertSame(2, $this->client->tag()->set($k, ['red', 'blue']));
        self::assertEqualsCanonicalizing(['red', 'blue'], $this->client->tag()->get($k));
        self::assertSame(2, $this->client->tag()->count($k));
        self::assertSame(1, $this->client->tag()->exists($k, 'red'));
        self::assertSame(0, $this->client->tag()->exists($k, 'green'));

        self::assertSame(1, $this->client->tag()->rem($k, ['red']));
        self::assertSame(['blue'], $this->client->tag()->get($k));
    }

    public function testSetOnMissingKeyErrors(): void
    {
        $this->expectException(\HotKV\Exception\ServerException::class);
        $this->client->tag()->set($this->key(), ['x']);
    }

    public function testMembersAndInter(): void
    {
        // Tag names are their own namespace on the server (not cleaned
        // up by DEL on the tagged key), so use run-unique tag names too,
        // not just run-unique keys.
        $shared = $this->ns('shared-tag');
        $only1 = $this->ns('only1-tag');
        $only2 = $this->ns('only2-tag');
        $k1 = $this->key();
        $k2 = $this->key();
        $this->client->set($k1, 'v');
        $this->client->set($k2, 'v');
        $this->client->tag()->set($k1, [$shared, $only1]);
        $this->client->tag()->set($k2, [$shared, $only2]);

        self::assertEqualsCanonicalizing([$k1, $k2], $this->client->tag()->members($shared));
        self::assertEqualsCanonicalizing([$k1], $this->client->tag()->members($only1));
        self::assertEqualsCanonicalizing([$k1, $k2], $this->client->tag()->inter([$shared]));
    }
}
