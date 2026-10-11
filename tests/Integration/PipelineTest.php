<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

use HotKV\Exception\ServerException;
use HotKV\Pipeline;

final class PipelineTest extends IntegrationTestCase
{
    public function testQueuedCommandsRunInOneRoundTripAndReturnResultsInOrder(): void
    {
        $k1 = $this->key();
        $k2 = $this->key();

        $results = $this->client->pipeline(function (Pipeline $p) use ($k1, $k2): void {
            $p->set($k1, 'a');
            $p->set($k2, 'b');
            $p->get($k1);
            $p->incr($k2 . ':counter');
        });

        self::assertSame(['OK', 'OK', 'a', 1], $results);
    }

    public function testFluentChainingReturnsSameResultsAsSeparateCalls(): void
    {
        $k = $this->key();
        $results = $this->client->pipeline(function (Pipeline $p) use ($k): void {
            $p->set($k, 'v')->get($k)->strlen($k);
        });
        self::assertSame(['OK', 'v', 1], $results);
    }

    public function testPerCommandErrorDoesNotFailTheWholeBatch(): void
    {
        $k = $this->key();
        $this->client->set($k, 'not-a-hash');

        $results = $this->client->pipeline(function (Pipeline $p) use ($k): void {
            $p->set($k . ':ok', 'v');
            $p->hset($k, ['f' => 'v']); // WRONGTYPE: $k already holds a string
            $p->get($k . ':ok');
        });

        self::assertSame('OK', $results[0]);
        self::assertInstanceOf(ServerException::class, $results[1]);
        self::assertSame('WRONGTYPE', $results[1]->getPrefix());
        self::assertSame('v', $results[2]);

        $this->client->del([$k . ':ok']);
    }

    public function testEmptyPipelineReturnsEmptyArray(): void
    {
        self::assertSame([], $this->client->pipeline(function (): void {}));
    }

    public function testGenericCommandEscapeHatchWorksInsidePipeline(): void
    {
        $k = $this->key();
        $results = $this->client->pipeline(function (Pipeline $p) use ($k): void {
            $p->command('SET', $k, 'x');
            $p->command('GET', $k);
        });
        self::assertSame(['OK', 'x'], $results);
    }
}
