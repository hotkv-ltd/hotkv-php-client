<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;
use HotKV\TokenUsage;

final class ScacheCommandsTest extends IntegrationTestCase
{
    public function testCreateSetGetStatsFlushDrop(): void
    {
        $this->withLicense(function (): void {
            $ns = $this->ns('scache');
            self::assertSame('OK', $this->client->scache()->create($ns, 3, threshold: 0.5));

            self::assertSame('OK', $this->client->scache()->set($ns, 'what is hotkv', 'a fast data store', [1.0, 0.0, 0.0]));

            $hit = $this->client->scache()->get($ns, [1.0, 0.0, 0.0]);
            self::assertNotNull($hit);
            self::assertSame('a fast data store', $hit['response']);

            $miss = $this->client->scache()->get($ns, [0.0, 0.0, 1.0]);
            self::assertNull($miss);

            $stats = $this->client->scache()->stats($ns);
            self::assertSame(1, $stats['entries']);
            self::assertGreaterThanOrEqual(1, $stats['hits']);

            self::assertSame('OK', $this->client->scache()->threshold($ns, 0.9));
            self::assertContains($ns, $this->client->scache()->list());

            self::assertSame(1, $this->client->scache()->flush($ns));
            self::assertSame(1, $this->client->scache()->drop($ns));
        });
    }

    public function testGetWithThresholdOverride(): void
    {
        $this->withLicense(function (): void {
            // The override parameter is accepted and still matches an exact
            // vector even against a stricter-than-default override.
            $ns = $this->ns('scache-thresh');
            $this->client->scache()->create($ns, 2, threshold: 0.5);
            $this->client->scache()->set($ns, 'q', 'r', [1.0, 0.0]);

            $hit = $this->client->scache()->get($ns, [1.0, 0.0], thresholdOverride: 0.9999);
            self::assertNotNull($hit);
            self::assertSame('r', $hit['response']);
        });
    }

    public function testSetWithUsageReportsSavingsOnStats(): void
    {
        $this->withLicense(function (): void {
            $ns = $this->ns('scache-usage');
            $usage = new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20);
            $this->client->scache()->create($ns, 3, threshold: 0.8);
            self::assertSame('OK', $this->client->scache()->set($ns, 'q', 'cached answer', [0.1, 0.2, 0.3], usage: $usage));

            self::assertNotNull($this->client->scache()->get($ns, [0.1, 0.2, 0.3])); // attribute the hit

            $stats = $this->client->scache()->stats($ns);
            self::assertGreaterThanOrEqual(1, $stats['usage_saved_requests']);
            self::assertGreaterThanOrEqual(100, $stats['usage_saved_input_tokens']);
        });
    }
}
