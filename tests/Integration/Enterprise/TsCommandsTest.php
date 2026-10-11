<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;

final class TsCommandsTest extends IntegrationTestCase
{
    public function testCreateAddRangeAndInfo(): void
    {
        $this->withLicense(function (): void {
            $k = $this->ns('ts');
            self::assertSame('OK', $this->client->ts()->create($k, retentionMs: 0, labels: ['sensor' => 'temp']));

            self::assertSame(1000, $this->client->ts()->add($k, 1000, 21.5));
            self::assertSame(2000, $this->client->ts()->add($k, 2000, 22.0));

            $range = $this->client->ts()->range($k, '-', '+');
            self::assertCount(2, $range);
            self::assertSame(1000, $range[0][0]);

            $info = $this->client->ts()->info($k);
            self::assertSame(2, $info['totalSamples']);
            self::assertSame(['sensor' => 'temp'], $info['labels']);

            self::assertSame('OK', $this->client->ts()->retention($k, 60_000));
            self::assertContains($k, $this->client->ts()->list());
            self::assertContains($k, $this->client->ts()->list('sensor', 'temp'));

            self::assertSame('OK', $this->client->ts()->delete($k));
        });
    }

    public function testAddWithAutoTimestamp(): void
    {
        $this->withLicense(function (): void {
            $k = $this->ns('ts-auto');
            $this->client->ts()->create($k);
            $ts = $this->client->ts()->add($k, '*', 1.0);
            self::assertGreaterThan(1_700_000_000_000, $ts);
            $this->client->ts()->delete($k);
        });
    }

    public function testAggAndDownsample(): void
    {
        $this->withLicense(function (): void {
            $src = $this->ns('ts-src');
            $dest = $this->ns('ts-dest');
            $this->client->ts()->create($src);
            $this->client->ts()->create($dest);
            $this->client->ts()->add($src, 1000, 10.0);
            $this->client->ts()->add($src, 1500, 20.0);
            $this->client->ts()->add($src, 2500, 30.0);

            $agg = $this->client->ts()->agg($src, '-', '+', 1000, 'avg');
            self::assertNotEmpty($agg);

            self::assertSame('OK', $this->client->ts()->downsample($src, $dest, 'avg', 1000));

            $this->client->ts()->delete($src);
            $this->client->ts()->delete($dest);
        });
    }

    public function testAddToMissingSeriesErrors(): void
    {
        $this->expectException(\HotKV\Exception\ServerException::class);
        $this->client->ts()->add($this->ns('missing'), 1000, 1.0);
    }
}
