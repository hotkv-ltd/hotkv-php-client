<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit;

use HotKV\CommandDispatcher;
use HotKV\Commands\GeoCommands;
use HotKV\Commands\HashCommands;
use HotKV\Commands\ListCommands;
use HotKV\Commands\SortedSetCommands;
use HotKV\Commands\StringCommands;
use HotKV\Protocol\Writer;
use HotKV\Enterprise\FeatureCommands;
use HotKV\Enterprise\LlmBudgetCommands;
use HotKV\Enterprise\LlmStatsCommands;
use HotKV\Enterprise\PcacheCommands;
use HotKV\Enterprise\RagCommands;
use HotKV\Enterprise\RatelimitCommands;
use HotKV\Enterprise\ScacheCommands;
use HotKV\Enterprise\TsCommands;
use HotKV\Enterprise\VsimCommands;
use HotKV\Tests\Unit\Support\RecordingExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Every float argument the SDK sends must reach the wire as plain decimal
 * text (never "1.0E-5"), whichever command it belongs to. These tests
 * run each float-taking method with a tiny value, and check the exact
 * argument text the Writer would put on the wire.
 */
final class FloatWireFormatTest extends TestCase
{
    private const TINY = 1.0E-5;
    private const HUGE = 1.0E+25;

    /** @param list<string|int|float|bool> $args @return list<string> */
    private static function wire(array $args): array
    {
        $writer = new Writer(fopen('php://memory', 'r+b'));

        return array_map(static fn ($a) => $writer->argToString($a), $args);
    }

    private function dispatcher(): object
    {
        return new class implements CommandDispatcher {
            use StringCommands;
            use HashCommands;
            use ListCommands;
            use SortedSetCommands;
            use GeoCommands;

            /** @var list<array<int, mixed>> */
            public array $calls = [];

            public function dispatch(array $args): mixed
            {
                $this->calls[] = $args;

                return $this;
            }
        };
    }

    public function testZaddScoresAreFormattedAsPlainDecimals(): void
    {
        $d = $this->dispatcher();
        $d->zadd('z', ['tiny' => self::TINY, 'huge' => self::HUGE, 'neg' => -2.5e-7, 'whole' => 3.0, 'inf' => INF, 'ninf' => -INF]);
        self::assertSame(
            ['ZADD', 'z', '0.00001', 'tiny', '10000000000000000000000000.0', 'huge', '-0.00000025', 'neg', '3.0', 'whole', 'inf', 'inf', '-inf', 'ninf'],
            self::wire($d->calls[0]),
        );
    }

    public function testZaddRejectsNan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dispatcher()->zadd('z', ['m' => NAN]);
    }

    public function testZincrbyAndIncrbyfloatAndHincrbyfloat(): void
    {
        $d = $this->dispatcher();
        $d->zincrby('z', self::TINY, 'm');
        $d->incrbyfloat('k', self::TINY);
        $d->hincrbyfloat('h', 'f', -self::TINY);
        self::assertSame(['ZINCRBY', 'z', '0.00001', 'm'], self::wire($d->calls[0]));
        self::assertSame(['INCRBYFLOAT', 'k', '0.00001'], self::wire($d->calls[1]));
        self::assertSame(['HINCRBYFLOAT', 'h', 'f', '-0.00001'], self::wire($d->calls[2]));
    }

    public function testIncrbyfloatRejectsInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dispatcher()->incrbyfloat('k', INF);
    }

    public function testGeoCoordinatesRadiiAndBoxes(): void
    {
        $d = $this->dispatcher();
        $d->geoadd('g', ['p' => [1.0E-5, -1.0E-6]]);
        $d->geosearch('g', fromLon: 1.0E-5, fromLat: 2.0E-5, byRadius: 3.0E-5, unit: 'km');
        $d->geosearch('g', fromLon: 1.0, fromLat: 2.0, byBoxWidth: 4.0E-5, byBoxHeight: 5.0E-5, unit: 'm');
        self::assertSame(['GEOADD', 'g', '0.00001', '-0.000001', 'p'], self::wire($d->calls[0]));
        self::assertContains('0.00001', self::wire($d->calls[1]));
        self::assertContains('0.00003', self::wire($d->calls[1]));
        self::assertContains('0.00004', self::wire($d->calls[2]));
        self::assertContains('0.00005', self::wire($d->calls[2]));
        foreach ([1, 2] as $i) {
            foreach (self::wire($d->calls[$i]) as $a) {
                self::assertDoesNotMatchRegularExpression('/\d[eE][+-]?\d/', $a);
            }
        }
    }

    public function testGeoRejectsInfiniteCoordinates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dispatcher()->geoadd('g', ['p' => [INF, 0.0]]);
    }

    public function testBlockingTimeouts(): void
    {
        $d = $this->dispatcher();
        $d->blpop(['l'], 0.5);
        $d->bzpopmin(['z'], 1.0E-5);
        self::assertSame(['BLPOP', 'l', '0.5'], self::wire($d->calls[0]));
        self::assertSame(['BZPOPMIN', 'z', '0.00001'], self::wire($d->calls[1]));
    }

    public function testZremrangeCommandsSendTheDocumentedArguments(): void
    {
        $d = $this->dispatcher();
        $d->zremrangebyrank('z', 0, -2);
        $d->zremrangebyscore('z', '(1', '+inf');
        $d->zremrangebylex('z', '[a', '(z');
        self::assertSame(['ZREMRANGEBYRANK', 'z', 0, -2], $d->calls[0]);
        self::assertSame(['ZREMRANGEBYSCORE', 'z', '(1', '+inf'], $d->calls[1]);
        self::assertSame(['ZREMRANGEBYLEX', 'z', '[a', '(z'], $d->calls[2]);
    }

    public function testLlmBudgetSetUsesTheServerFixedPointMoneyFormat(): void
    {
        $e = new RecordingExecutor(reply: 'OK');
        $b = new LlmBudgetCommands($e);
        $b->set('t', 1_000_000, 1.0E-5, 60);
        self::assertSame(['LLMBUDGET.SET', 't', 1_000_000, '0.00001', 60], $e->lastCall());
        $b->set('t', 1, 50.0, 60);
        self::assertSame(['LLMBUDGET.SET', 't', 1, '50', 60], $e->lastCall());
        $b->set('t', 1, 0.0000123456, 60);
        self::assertSame(['LLMBUDGET.SET', 't', 1, '0.000012', 60], $e->lastCall());
        $b->set('t', 1, 12.5, 60);
        self::assertSame(['LLMBUDGET.SET', 't', 1, '12.5', 60], $e->lastCall());
    }

    public function testLlmBudgetSetRejectsAmountsTheServerWouldRead(): void
    {
        $b = new LlmBudgetCommands(new RecordingExecutor(reply: 'OK'));
        foreach ([-1.0, NAN, INF, 1.0E-9] as $bad) {
            try {
                $b->set('t', 1, $bad, 60);
                self::fail('accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLlmBudgetConsumeAndStatsRecordCosts(): void
    {
        $e = new RecordingExecutor(reply: 1);
        (new LlmBudgetCommands($e))->consume('t', 10, 1.0E-5);
        self::assertSame(['LLMBUDGET.CONSUME', 't', 10, '0.00001'], $e->lastCall());
        (new LlmStatsCommands($e))->record('b', 1, 2, 0, 3.0E-6, 12);
        self::assertContains('0.000003', $e->lastCall());
    }

    public function testLlmStatsPriceSetFormatsPrices(): void
    {
        $e = new RecordingExecutor(reply: 'OK');
        (new LlmStatsCommands($e))->priceSet('m', 1.0E-5, 15.0, 0.30);
        self::assertSame(
            ['LLMSTATS.PRICE', 'SET', 'm', 'INPUT', '0.00001', 'OUTPUT', '15', 'CACHEREAD', '0.3'],
            $e->lastCall(),
        );
    }

    public function testSemanticAndPromptCacheThresholdsAndCosts(): void
    {
        $e = new RecordingExecutor(reply: 'OK');
        $s = new ScacheCommands($e);
        $s->create('ns', 3, 'cosine', 1.0E-5, 0);
        self::assertSame(['SCACHE.CREATE', 'ns', 3, 'METRIC', 'cosine', 'THRESHOLD', '0.00001', 'TTL', 0], $e->lastCall());
        $s->get('ns', [1.0E-5, 2.0, -3.5E-7], 1.0E-5);
        self::assertSame(['SCACHE.GET', 'ns', '0.00001', '2.0', '-0.00000035', 'THRESHOLD', '0.00001'], $e->lastCall());
        $s->threshold('ns', 1.0E-5);
        self::assertSame(['SCACHE.THRESHOLD', 'ns', '0.00001'], $e->lastCall());
        $s->set('ns', 'q', 'r', [1.0E-5, 0.25]);
        self::assertSame(['SCACHE.SET', 'ns', 'q', 'r', '0.00001', '0.25'], $e->lastCall());

        (new PcacheCommands($e))->create('ns', costPerHit: 1.0E-5);
        self::assertContains('0.00001', $e->lastCall());
    }

    public function testRatelimitRagVsimTsAndFeatureFloats(): void
    {
        $e = new RecordingExecutor(reply: 'OK');
        (new RatelimitCommands($e))->create('r', 10, 1000, 'token_bucket', 1.0E-5, 2.5E-6);
        self::assertSame(
            ['RATELIMIT.CREATE', 'r', 10, 1000, 'ALGO', 'token_bucket', 'REFILL', '0.00001', 'DRAIN', '0.0000025'],
            $e->lastCall(),
        );

        $rag = new RecordingExecutor(reply: []);
        (new RagCommands($rag))->search('p', 'q', 5, 1.0E-5, [1.0E-5, 0.5]);
        self::assertSame(['RAG.SEARCH', 'p', 'q', 'K', 5, 'ALPHA', '0.00001', 'VECTOR', '0.00001,0.5'], $rag->lastCall());

        (new VsimCommands($e))->set('i', 'k', [1.0E-5, 2.0]);
        self::assertSame(['VSIM.SET', 'i', 'k', '0.00001', '2.0'], $e->lastCall());

        (new TsCommands($e))->add('ts', '*', 1.0E-5);
        self::assertSame(['TS.ADD', 'ts', '*', '0.00001'], $e->lastCall());

        (new FeatureCommands($e))->set('g', 'e', ['f' => 1.0E-5, 'n' => 3, 's' => 'x']);
        self::assertSame(['FEATURE.SET', 'g', 'e', 'f', '0.00001', 'n', '3', 's', 'x'], $e->lastCall());
    }

    public function testVectorsRejectNanAndInfinity(): void
    {
        $e = new RecordingExecutor(reply: 'OK');
        foreach ([NAN, INF] as $bad) {
            try {
                (new VsimCommands($e))->set('i', 'k', [1.0, $bad]);
                self::fail('accepted a non-finite vector component');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
