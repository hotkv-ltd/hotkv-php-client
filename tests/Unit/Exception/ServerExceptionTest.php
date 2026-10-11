<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Exception;

use HotKV\Exception\AskException;
use HotKV\Exception\MovedException;
use HotKV\Exception\NoAuthException;
use HotKV\Exception\NotLicensedException;
use HotKV\Exception\ReadOnlyException;
use HotKV\Exception\ServerException;
use HotKV\Exception\WrongTypeException;
use PHPUnit\Framework\TestCase;

final class ServerExceptionTest extends TestCase
{
    public function testParsesGenericErrorPrefix(): void
    {
        $e = ServerException::fromRaw('ERR wrong number of arguments for \'get\' command');
        self::assertSame(ServerException::class, get_class($e));
        self::assertSame('ERR', $e->getPrefix());
        self::assertSame('ERR wrong number of arguments for \'get\' command', $e->getRaw());
        self::assertSame('ERR wrong number of arguments for \'get\' command', $e->getMessage());
    }

    public function testMapsNolicenseToTypedException(): void
    {
        $e = ServerException::fromRaw("NOLICENSE enterprise feature 'prompt-cache' requires a valid license");
        self::assertInstanceOf(NotLicensedException::class, $e);
        self::assertSame('NOLICENSE', $e->getPrefix());
    }

    public function testMapsWrongtypeNoauthAndReadonly(): void
    {
        self::assertInstanceOf(WrongTypeException::class, ServerException::fromRaw('WRONGTYPE Operation against a key holding the wrong kind of value'));
        self::assertInstanceOf(NoAuthException::class, ServerException::fromRaw('NOAUTH Authentication required.'));
        self::assertInstanceOf(ReadOnlyException::class, ServerException::fromRaw('READONLY You can\'t write against a read only replica.'));
    }

    public function testMapsMovedWithSlotAndTarget(): void
    {
        $e = ServerException::fromRaw('MOVED 3999 127.0.0.1:7001');
        self::assertInstanceOf(MovedException::class, $e);
        self::assertSame(3999, $e->getSlot());
        self::assertSame('127.0.0.1', $e->getHost());
        self::assertSame(7001, $e->getPort());
    }

    public function testMapsAskWithSlotAndTarget(): void
    {
        $e = ServerException::fromRaw('ASK 3999 127.0.0.1:7002');
        self::assertInstanceOf(AskException::class, $e);
        self::assertSame(3999, $e->getSlot());
        self::assertSame('127.0.0.1', $e->getHost());
        self::assertSame(7002, $e->getPort());
    }

    public function testUnknownPrefixStillParses(): void
    {
        $e = ServerException::fromRaw('SOMETHINGWEIRD without a space');
        self::assertSame('SOMETHINGWEIRD', $e->getPrefix());
    }
}
