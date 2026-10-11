<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit\Support;

use HotKV\Support\Args;
use PHPUnit\Framework\TestCase;

final class ArgsTest extends TestCase
{
    public function testBaseArguments(): void
    {
        self::assertSame(['SET', 'k', 'v'], Args::new('SET', 'k', 'v')->toArray());
    }

    public function testOptOnlyAppendsWhenNotNull(): void
    {
        $args = Args::new('SET', 'k')->opt('EX', 10)->opt('PX', null);
        self::assertSame(['SET', 'k', 'EX', 10], $args->toArray());
    }

    public function testFlagOnlyAppendsWhenTrue(): void
    {
        $args = Args::new('SET', 'k')->flag('NX', true)->flag('XX', false);
        self::assertSame(['SET', 'k', 'NX'], $args->toArray());
    }

    public function testPushAllAppendsEveryElement(): void
    {
        $args = Args::new('SADD', 'k')->pushAll(['a', 'b', 'c']);
        self::assertSame(['SADD', 'k', 'a', 'b', 'c'], $args->toArray());
    }

    public function testOptionsAreNeverInterleavedWithFixedPositionalArgs(): void
    {
        // Options must always trail the fixed positional args, in the
        // order the builder calls happened, matching the server's
        // left-to-right keyword scan.
        $args = Args::new('SCACHE.CREATE', 'ns', 3)->push('METRIC', 'cosine')->opt('THRESHOLD', 0.9);
        self::assertSame(['SCACHE.CREATE', 'ns', 3, 'METRIC', 'cosine', 'THRESHOLD', 0.9], $args->toArray());
    }
}
