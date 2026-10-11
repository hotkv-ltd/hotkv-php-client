<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Support;

use HotKV\Support\Idempotency;
use PHPUnit\Framework\TestCase;

final class IdempotencyTest extends TestCase
{
    public function testPlainReadsAreRetryable(): void
    {
        self::assertTrue(Idempotency::isReadOnly(['GET', 'k']));
        self::assertTrue(Idempotency::isReadOnly(['HGETALL', 'k']));
        self::assertTrue(Idempotency::isReadOnly(['PING']));
    }

    public function testWritesAreNeverRetryable(): void
    {
        self::assertFalse(Idempotency::isReadOnly(['SET', 'k', 'v']));
        self::assertFalse(Idempotency::isReadOnly(['DEL', 'k']));
        self::assertFalse(Idempotency::isReadOnly(['INCR', 'k']));
        self::assertFalse(Idempotency::isReadOnly(['LPUSH', 'k', 'v']));
    }

    public function testEmptyArgsIsNotRetryable(): void
    {
        self::assertFalse(Idempotency::isReadOnly([]));
    }

    public function testConfigGetIsReadOnlyButConfigSetIsNot(): void
    {
        self::assertTrue(Idempotency::isReadOnly(['CONFIG', 'GET', 'maxmemory']));
        self::assertFalse(Idempotency::isReadOnly(['CONFIG', 'SET', 'maxmemory', '10mb']));
    }

    public function testClientGetnameIsReadOnlyButClientSetnameIsNot(): void
    {
        self::assertTrue(Idempotency::isReadOnly(['CLIENT', 'GETNAME']));
        self::assertFalse(Idempotency::isReadOnly(['CLIENT', 'SETNAME', 'x']));
    }

    public function testHotkeysTopIsReadOnlyButResetIsNot(): void
    {
        self::assertTrue(Idempotency::isReadOnly(['HOTKEYS', 'TOP', 10]));
        self::assertFalse(Idempotency::isReadOnly(['HOTKEYS', 'RESET']));
    }

    public function testObserveAndAlertsSubcommands(): void
    {
        self::assertTrue(Idempotency::isReadOnly(['OBSERVE', 'INFO']));
        self::assertTrue(Idempotency::isReadOnly(['ALERTS', 'INFO']));
        self::assertFalse(Idempotency::isReadOnly(['ALERTS', 'TEST']));
        self::assertFalse(Idempotency::isReadOnly(['ALERTS', 'SETURL', 'https://example.com']));
    }

    public function testHotkvFamilyReadCommandsAreRecognized(): void
    {
        self::assertTrue(Idempotency::isReadOnly(['PCACHE.GET', 'ns', 'prompt']));
        self::assertTrue(Idempotency::isReadOnly(['SCACHE.STATS', 'ns']));
        self::assertFalse(Idempotency::isReadOnly(['PCACHE.SET', 'ns', 'prompt', 'response']));
    }

    public function testCommandNameIsCaseInsensitive(): void
    {
        self::assertTrue(Idempotency::isReadOnly(['get', 'k']));
        self::assertFalse(Idempotency::isReadOnly(['set', 'k', 'v']));
    }

    public function testNewerReadOnlyCommandsAreRetryableButTheirWritingCousinsAreNot(): void
    {
        foreach (['LCS', 'DIGEST', 'ROLE', 'SORT_RO', 'EVAL_RO', 'EVALSHA_RO'] as $cmd) {
            self::assertTrue(Idempotency::isReadOnly([$cmd, 'x']), $cmd);
        }
        foreach (['SORT', 'LMPOP', 'ZMPOP', 'HGETDEL', 'HGETEX', 'HSETEX', 'MSETEX', 'DELEX', 'EVAL'] as $cmd) {
            self::assertFalse(Idempotency::isReadOnly([$cmd, 'x']), $cmd);
        }
    }
}
