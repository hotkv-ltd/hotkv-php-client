<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

use HotKV\Transaction;

final class TransactionTest extends IntegrationTestCase
{
    public function testQueuedCommandsCommitTogether(): void
    {
        $k1 = $this->key();
        $k2 = $this->key();

        $results = $this->client->transaction(function (Transaction $tx) use ($k1, $k2): void {
            $tx->set($k1, 'a');
            $tx->set($k2, 'b');
            $tx->get($k1);
        });

        self::assertSame(['OK', 'OK', 'a'], $results);
        self::assertSame('a', $this->client->get($k1));
        self::assertSame('b', $this->client->get($k2));
    }

    public function testUnwatchedTransactionAlwaysCommits(): void
    {
        $k = $this->key();
        $result = $this->client->transaction(function (Transaction $tx) use ($k): void {
            $tx->incr($k);
        });
        self::assertSame([1], $result);
    }

    public function testWatchAbortsExecWhenKeyChangesBeforeTransaction(): void
    {
        $k = $this->key();
        $this->client->set($k, 'original');

        $this->client->watch($k);
        // A change from elsewhere (here: the same client, but any writer
        // would do) between WATCH and EXEC must abort the transaction.
        $this->client->set($k, 'changed-by-someone-else');

        $result = $this->client->transaction(function (Transaction $tx) use ($k): void {
            $tx->set($k, 'should-not-be-applied');
        });

        self::assertNull($result);
        self::assertSame('changed-by-someone-else', $this->client->get($k));
    }

    public function testWatchThenUnwatchAllowsExecToCommit(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v');
        $this->client->watch($k);
        $this->client->set($k, 'changed');
        $this->client->unwatch();

        $result = $this->client->transaction(function (Transaction $tx) use ($k): void {
            $tx->set($k, 'final');
        });

        self::assertSame(['OK'], $result);
        self::assertSame('final', $this->client->get($k));
    }

    public function testEmptyTransactionReturnsEmptyArray(): void
    {
        self::assertSame([], $this->client->transaction(function (): void {}));
    }

    public function testFailedQueuedCommandAppearsAsExceptionInResults(): void
    {
        $k = $this->key();
        $this->client->set($k, 'a-string');

        $result = $this->client->transaction(function (Transaction $tx) use ($k): void {
            $tx->set($k . ':ok', 'v');
            $tx->hset($k, ['f' => 'v']); // WRONGTYPE at EXEC time
        });

        self::assertSame('OK', $result[0]);
        self::assertInstanceOf(\HotKV\Exception\ServerException::class, $result[1]);
        self::assertSame('WRONGTYPE', $result[1]->getPrefix());

        $this->client->del([$k . ':ok']);
    }
}
