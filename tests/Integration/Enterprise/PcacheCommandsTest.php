<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;
use HotKV\TokenUsage;

/** PCACHE.*: needs a server license that includes the prompt-cache feature. */
final class PcacheCommandsTest extends IntegrationTestCase
{
    public function testLifecycle(): void
    {
        $this->withLicense(function (): void {
            $ns = $this->ns('pcache');
            self::assertSame('OK', $this->client->pcache()->create($ns, maxEntries: 1000, ttl: 0, costPerHit: 0.002));
            self::assertSame('OK', $this->client->pcache()->set($ns, 'prompt1', 'response1'));
            self::assertSame('response1', $this->client->pcache()->get($ns, 'prompt1'));
            self::assertNull($this->client->pcache()->get($ns, 'missing-prompt'));

            $getex = $this->client->pcache()->getex($ns, 'prompt1');
            self::assertSame('response1', $getex['response']);
            self::assertGreaterThanOrEqual(1, $getex['hit_count']);

            $stats = $this->client->pcache()->stats($ns);
            self::assertSame(1, $stats['entries']);
            self::assertArrayHasKey('usage_saved_cost_usd', $stats);

            self::assertContains($ns, $this->client->pcache()->list());
            self::assertSame('OK', $this->client->pcache()->config($ns, 'TTL', 3600));

            self::assertSame(1, $this->client->pcache()->del($ns, 'prompt1'));
            self::assertGreaterThanOrEqual(0, $this->client->pcache()->flush($ns));
            self::assertSame(1, $this->client->pcache()->drop($ns));
        });
    }

    public function testSetWithUsageReportsSavingsOnStats(): void
    {
        $this->withLicense(function (): void {
            $ns = $this->ns('pcache-usage');
            self::assertSame('OK', $this->client->pcache()->create($ns));
            $usage = new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20);
            self::assertSame('OK', $this->client->pcache()->set($ns, 'prompt1', 'response1', usage: $usage));
            self::assertSame('response1', $this->client->pcache()->get($ns, 'prompt1')); // attribute the hit

            $stats = $this->client->pcache()->stats($ns);
            self::assertGreaterThanOrEqual(1, $stats['usage_saved_requests']);
            self::assertGreaterThanOrEqual(100, $stats['usage_saved_input_tokens']);
            self::assertGreaterThanOrEqual(20, $stats['usage_saved_output_tokens']);
            self::assertSame(1, $this->client->pcache()->drop($ns));
        });
    }
}
