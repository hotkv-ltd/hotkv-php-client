<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit;

use HotKV\CommandDispatcher;
use HotKV\Commands\HashCommands;
use HotKV\Commands\KeyCommands;
use HotKV\Commands\ListCommands;
use HotKV\Commands\ScriptingCommands;
use HotKV\Commands\ServerCommands;
use HotKV\Commands\SortedSetCommands;
use HotKV\Commands\StringCommands;
use PHPUnit\Framework\TestCase;

/** Exact argument lists of the typed commands added after the first release (checked against the server's command documentation). */
final class ExtendedCommandsWireTest extends TestCase
{
    private function dispatcher(): object
    {
        return new class implements CommandDispatcher {
            use StringCommands;
            use HashCommands;
            use ListCommands;
            use SortedSetCommands;
            use KeyCommands;
            use ServerCommands;
            use ScriptingCommands;

            /** @var list<array<int, mixed>> */
            public array $calls = [];

            public function dispatch(array $args): mixed
            {
                $this->calls[] = $args;

                return $this;
            }
        };
    }

    public function testPopFamilies(): void
    {
        $d = $this->dispatcher();
        $d->lmpop(['a', 'b'], true, 3);
        $d->lmpop(['a'], false);
        $d->blmpop(0.5, ['a'], false, 2);
        $d->zmpop(['z1', 'z2']);
        $d->zmpop(['z'], true, 4);
        $d->bzmpop(1.0E-5, ['z'], true);
        self::assertSame(['LMPOP', 2, 'a', 'b', 'LEFT', 'COUNT', 3], $d->calls[0]);
        self::assertSame(['LMPOP', 1, 'a', 'RIGHT'], $d->calls[1]);
        self::assertSame(['BLMPOP', '0.5', 1, 'a', 'RIGHT', 'COUNT', 2], $d->calls[2]);
        self::assertSame(['ZMPOP', 2, 'z1', 'z2', 'MIN'], $d->calls[3]);
        self::assertSame(['ZMPOP', 1, 'z', 'MAX', 'COUNT', 4], $d->calls[4]);
        self::assertSame(['BZMPOP', '0.00001', 1, 'z', 'MAX'], $d->calls[5]);
    }

    public function testStringFamily(): void
    {
        $d = $this->dispatcher();
        $d->lcs('a', 'b');
        $d->lcs('a', 'b', len: true);
        $d->lcs('a', 'b', idx: true, minMatchLen: 4, withMatchLen: true);
        $d->digest('k');
        $d->delex('k', ifeq: 'v');
        $d->delex('k', ifdne: 'abc');
        $d->msetex(['a' => '1', 'b' => '2'], nx: true, ex: 100);
        $d->msetex(['a' => '1'], xx: true, keepttl: true);
        self::assertSame(['LCS', 'a', 'b'], $d->calls[0]);
        self::assertSame(['LCS', 'a', 'b', 'LEN'], $d->calls[1]);
        self::assertSame(['LCS', 'a', 'b', 'IDX', 'MINMATCHLEN', 4, 'WITHMATCHLEN'], $d->calls[2]);
        self::assertSame(['DIGEST', 'k'], $d->calls[3]);
        self::assertSame(['DELEX', 'k', 'IFEQ', 'v'], $d->calls[4]);
        self::assertSame(['DELEX', 'k', 'IFDNE', 'abc'], $d->calls[5]);
        self::assertSame(['MSETEX', 2, 'a', '1', 'b', '2', 'NX', 'EX', 100], $d->calls[6]);
        self::assertSame(['MSETEX', 1, 'a', '1', 'XX', 'KEEPTTL'], $d->calls[7]);
    }

    public function testHashFamily(): void
    {
        $d = $this->dispatcher();
        $d->hgetdel('h', ['a', 'b']);
        $d->hgetex('h', ['a'], ex: 10);
        $d->hgetex('h', ['a', 'b'], persist: true);
        $d->hsetex('h', ['f' => 'v', 'g' => 'w'], fnx: true, px: 500);
        $d->hsetex('h', ['f' => 'v'], keepttl: true);
        self::assertSame(['HGETDEL', 'h', 'FIELDS', 2, 'a', 'b'], $d->calls[0]);
        self::assertSame(['HGETEX', 'h', 'EX', 10, 'FIELDS', 1, 'a'], $d->calls[1]);
        self::assertSame(['HGETEX', 'h', 'PERSIST', 'FIELDS', 2, 'a', 'b'], $d->calls[2]);
        self::assertSame(['HSETEX', 'h', 'FNX', 'PX', 500, 'FIELDS', 2, 'f', 'v', 'g', 'w'], $d->calls[3]);
        self::assertSame(['HSETEX', 'h', 'KEEPTTL', 'FIELDS', 1, 'f', 'v'], $d->calls[4]);
    }

    public function testSortAndServerAndScripting(): void
    {
        $d = $this->dispatcher();
        $d->sort('k', by: 'w_*', limitOffset: 0, limitCount: 2, get: ['#', 'o_*'], desc: true, alpha: true, store: 'dst');
        $d->sortRo('k');
        $d->role();
        $d->wait(1, 100);
        $d->slowlogGet();
        $d->slowlogGet(3);
        $d->slowlogLen();
        $d->slowlogReset();
        $d->memoryUsage('k');
        $d->memoryUsage('k', 5);
        $d->evalRo('return 1', ['k'], ['a']);
        $d->evalshaRo('abc', [], []);
        self::assertSame(['SORT', 'k', 'BY', 'w_*', 'LIMIT', 0, 2, 'GET', '#', 'GET', 'o_*', 'DESC', 'ALPHA', 'STORE', 'dst'], $d->calls[0]);
        self::assertSame(['SORT_RO', 'k'], $d->calls[1]);
        self::assertSame(['ROLE'], $d->calls[2]);
        self::assertSame(['WAIT', 1, 100], $d->calls[3]);
        self::assertSame(['SLOWLOG', 'GET'], $d->calls[4]);
        self::assertSame(['SLOWLOG', 'GET', 3], $d->calls[5]);
        self::assertSame(['SLOWLOG', 'LEN'], $d->calls[6]);
        self::assertSame(['SLOWLOG', 'RESET'], $d->calls[7]);
        self::assertSame(['MEMORY', 'USAGE', 'k'], $d->calls[8]);
        self::assertSame(['MEMORY', 'USAGE', 'k', 'SAMPLES', 5], $d->calls[9]);
        self::assertSame(['EVAL_RO', 'return 1', 1, 'k', 'a'], $d->calls[10]);
        self::assertSame(['EVALSHA_RO', 'abc', 0], $d->calls[11]);
    }
}
