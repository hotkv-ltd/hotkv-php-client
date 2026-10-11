<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

final class StringCommandsTest extends IntegrationTestCase
{
    public function testSetAndGet(): void
    {
        $k = $this->key();
        self::assertSame('OK', $this->client->set($k, 'hello'));
        self::assertSame('hello', $this->client->get($k));
    }

    public function testGetOfMissingKeyIsNull(): void
    {
        self::assertNull($this->client->get($this->key()));
    }

    public function testSetNxAndXx(): void
    {
        $k = $this->key();
        self::assertSame('OK', $this->client->set($k, 'a', nx: true));
        self::assertNull($this->client->set($k, 'b', nx: true));
        self::assertSame('a', $this->client->get($k));

        self::assertSame('OK', $this->client->set($k, 'c', xx: true));
        self::assertSame('c', $this->client->get($k));

        $k2 = $this->key();
        self::assertNull($this->client->set($k2, 'x', xx: true));
    }

    public function testSetWithGetOption(): void
    {
        $k = $this->key();
        $this->client->set($k, 'old');
        self::assertSame('old', $this->client->set($k, 'new', get: true));
        self::assertSame('new', $this->client->get($k));
    }

    public function testSetWithExpiry(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v', ex: 100);
        $ttl = $this->client->ttl($k);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(100, $ttl);
    }

    public function testSetexAndPsetex(): void
    {
        $k = $this->key();
        self::assertSame('OK', $this->client->setex($k, 100, 'v'));
        self::assertGreaterThan(0, $this->client->ttl($k));

        $k2 = $this->key();
        self::assertSame('OK', $this->client->psetex($k2, 100000, 'v'));
        self::assertGreaterThan(0, $this->client->pttl($k2));
    }

    public function testGetsetAndGetdel(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v1');
        self::assertSame('v1', $this->client->getset($k, 'v2'));
        self::assertSame('v2', $this->client->getdel($k));
        self::assertNull($this->client->get($k));
    }

    public function testGetexPersist(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v', ex: 100);
        self::assertSame('v', $this->client->getex($k, persist: true));
        self::assertSame(-1, $this->client->ttl($k));
    }

    public function testAppendAndStrlen(): void
    {
        $k = $this->key();
        self::assertSame(5, $this->client->append($k, 'hello'));
        self::assertSame(11, $this->client->append($k, ' world'));
        self::assertSame(11, $this->client->strlen($k));
    }

    public function testGetrangeAndSetrange(): void
    {
        $k = $this->key();
        $this->client->set($k, 'Hello World');
        self::assertSame('Hello', $this->client->getrange($k, 0, 4));
        self::assertSame(11, $this->client->setrange($k, 6, 'HotKV'));
        self::assertSame('Hello HotKV', $this->client->get($k));
    }

    public function testIncrDecrFamily(): void
    {
        $k = $this->key();
        self::assertSame(1, $this->client->incr($k));
        self::assertSame(0, $this->client->decr($k));
        self::assertSame(10, $this->client->incrby($k, 10));
        self::assertSame(5, $this->client->decrby($k, 5));
    }

    public function testIncrbyfloat(): void
    {
        $k = $this->key();
        $this->client->set($k, '10.5');
        self::assertSame('10.6', $this->client->incrbyfloat($k, 0.1));
    }

    public function testMgetMsetMsetnx(): void
    {
        $k1 = $this->key();
        $k2 = $this->key();
        $k3 = $this->key();
        self::assertSame('OK', $this->client->mset([$k1 => 'a', $k2 => 'b']));
        self::assertSame(['a', 'b', null], $this->client->mget([$k1, $k2, $k3]));

        self::assertSame(0, $this->client->msetnx([$k1 => 'x', $k3 => 'y']));
        self::assertSame(1, $this->client->msetnx([$k3 => 'y']));
        self::assertSame('y', $this->client->get($k3));
    }
}
