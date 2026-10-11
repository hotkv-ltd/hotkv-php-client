<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\AI;

use HotKV\AI\SemanticCache;
use HotKV\AI\SemanticCallResult;
use HotKV\Tests\Integration\IntegrationTestCase;
use HotKV\TokenUsage;

/** Backed by SCACHE.*: needs a server license that includes the semantic-cache feature. */
final class SemanticCacheTest extends IntegrationTestCase
{
    public function testGetOrCallOnlyInvokesTheFallbackOnAMiss(): void
    {
        $this->withLicense(function (): void {
            $cache = new SemanticCache($this->client, $this->ns('semcache'), ttlSeconds: 60);
            $cache->createNamespace(dims: 3, threshold: 0.8);

            $calls = 0;
            $fn = function () use (&$calls): string {
                $calls++;

                return 'a fast in-memory data store';
            };

            $first = $cache->getOrCall('what is hotkv', [1.0, 0.0, 0.0], $fn);
            self::assertFalse($first->hit);
            self::assertSame('a fast in-memory data store', $first->response);
            self::assertSame(1, $calls);

            // The exact same embedding must hit without calling $fn again.
            $second = $cache->getOrCall('what is hotkv', [1.0, 0.0, 0.0], $fn);
            self::assertTrue($second->hit);
            self::assertSame('a fast in-memory data store', $second->response);
            self::assertSame(1, $calls);
        });
    }

    public function testGetOrCallReportsUsageOnALaterHit(): void
    {
        $this->withLicense(function (): void {
            $cache = new SemanticCache($this->client, $this->ns('semcache-usage'), ttlSeconds: 60);
            $cache->createNamespace(dims: 3, threshold: 0.8);
            $usage = new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20);

            $first = $cache->getOrCall(
                'what is hotkv',
                [1.0, 0.0, 0.0],
                fn (): SemanticCallResult => new SemanticCallResult('a fast in-memory data store', $usage),
            );
            self::assertFalse($first->hit);

            $second = $cache->getOrCall('what is hotkv', [1.0, 0.0, 0.0], fn (): string => 'should not run');
            self::assertTrue($second->hit); // attribute the hit

            $stats = $cache->stats();
            self::assertGreaterThanOrEqual(1, $stats['usage_saved_requests']);
            self::assertGreaterThanOrEqual(100, $stats['usage_saved_input_tokens']);
        });
    }
}
