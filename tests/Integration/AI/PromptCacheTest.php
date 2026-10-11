<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\AI;

use HotKV\AI\PromptCache;
use HotKV\AI\PromptCallResult;
use HotKV\Tests\Integration\IntegrationTestCase;
use HotKV\TokenUsage;

/** Backed by PCACHE.*: needs a server license that includes the prompt-cache feature. */
final class PromptCacheTest extends IntegrationTestCase
{
    public function testGetOrCallOnlyInvokesTheLlmOnAMiss(): void
    {
        $this->withLicense(function (): void {
            $cache = new PromptCache($this->client, $this->ns('promptcache'), ttlSeconds: 60);
            $cache->createNamespace();

            $calls = 0;
            $fn = function () use (&$calls): PromptCallResult {
                $calls++;

                return new PromptCallResult('the llm answer', inputTokens: 10, outputTokens: 5, costUsd: 0.001, latencyMs: 120);
            };

            $messages = [['role' => 'user', 'content' => 'What is HotKV?']];

            $first = $cache->getOrCall('openai', 'gpt-6-sol', '', $messages, ['temperature' => '0.7'], $fn);
            self::assertFalse($first->hit);
            self::assertSame('the llm answer', $first->response);
            self::assertSame(1, $calls);

            $second = $cache->getOrCall('openai', 'gpt-6-sol', '', $messages, ['temperature' => '0.7'], $fn);
            self::assertTrue($second->hit);
            self::assertSame('the llm answer', $second->response);
            // The LLM function must not run again on a hit.
            self::assertSame(1, $calls);

            self::assertSame($first->key, $second->key);
        });
    }

    public function testDifferentParamsAreDifferentCacheEntries(): void
    {
        $this->withLicense(function (): void {
            $cache = new PromptCache($this->client, $this->ns('promptcache-params'), ttlSeconds: 60);
            $cache->createNamespace();

            $messages = [['role' => 'user', 'content' => 'hi']];
            $r1 = $cache->getOrCall('p', 'm', '', $messages, ['temperature' => '0.1'], fn () => new PromptCallResult('a'));
            $r2 = $cache->getOrCall('p', 'm', '', $messages, ['temperature' => '0.9'], fn () => new PromptCallResult('b'));

            self::assertNotSame($r1->key, $r2->key);
            self::assertFalse($r2->hit);
        });
    }

    public function testWithStatsBucketRecordsUsageOnMiss(): void
    {
        $this->withLicense(function (): void {
            $bucket = $this->ns('promptcache-stats');
            $cache = (new PromptCache($this->client, $this->ns('promptcache-with-stats'), ttlSeconds: 60))
                ->withStatsBucket($bucket);
            $cache->createNamespace();

            $cache->getOrCall('p', 'm', '', [], [], fn () => new PromptCallResult('r', inputTokens: 7, outputTokens: 3, costUsd: 0.01, latencyMs: 50));

            $stats = $this->client->llmstats()->get($bucket);
            self::assertSame(1, $stats['requests']);
            self::assertSame(7, $stats['input_tokens']);
            self::assertSame(3, $stats['output_tokens']);
        });
    }

    public function testWithStatsBucketRecordsUsageWithModelIsPricedByTheServer(): void
    {
        $this->withLicense(function (): void {
            $bucket = $this->ns('promptcache-stats-model');
            $cache = (new PromptCache($this->client, $this->ns('promptcache-with-stats-model'), ttlSeconds: 60))
                ->withStatsBucket($bucket);
            $cache->createNamespace();

            $usage = new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20);
            $cache->getOrCall('p', 'm', '', [], [], fn () => new PromptCallResult('r', usage: $usage));

            $stats = $this->client->llmstats()->get($bucket);
            self::assertSame(1, $stats['requests']);
            self::assertGreaterThan(0.0, (float) $stats['cost_usd']);
        });
    }

    public function testGetOrCallReportsUsageOnALaterHit(): void
    {
        $this->withLicense(function (): void {
            $cache = new PromptCache($this->client, $this->ns('promptcache-usage'), ttlSeconds: 60);
            $cache->createNamespace();

            $usage = new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20);
            $messages = [['role' => 'user', 'content' => 'usage test']];

            $cache->getOrCall('anthropic', 'claude-sonnet-5', '', $messages, [], fn () => new PromptCallResult('hello there', usage: $usage));
            $second = $cache->getOrCall('anthropic', 'claude-sonnet-5', '', $messages, [], fn () => new PromptCallResult('should not run'));
            self::assertTrue($second->hit); // attribute the hit

            $stats = $cache->stats();
            self::assertGreaterThanOrEqual(1, $stats['usage_saved_requests']);
            self::assertGreaterThanOrEqual(100, $stats['usage_saved_input_tokens']);
            self::assertGreaterThanOrEqual(20, $stats['usage_saved_output_tokens']);
        });
    }
}
