<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;
use HotKV\TokenUsage;

/** LLMBUDGET.*: needs a server license that includes the llm-budget feature. */
final class LlmBudgetCommandsTest extends IntegrationTestCase
{
    public function testLifecycle(): void
    {
        $this->withLicense(function (): void {
            $name = $this->ns('llmbudget');
            self::assertSame('OK', $this->client->llmbudget()->set($name, 1000, 1.0, 3600));

            self::assertSame(1, $this->client->llmbudget()->consume($name, 100, 0.1));
            self::assertSame(0, $this->client->llmbudget()->consume($name, 10_000, 10.0));

            $status = $this->client->llmbudget()->status($name);
            self::assertSame(1000, $status['token_limit']);
            self::assertGreaterThanOrEqual(1, $status['requests_allowed']);
            self::assertGreaterThanOrEqual(1, $status['requests_rejected']);
            self::assertSame(0, $status['unpriced_requests']);

            self::assertContains($name, $this->client->llmbudget()->list());
            self::assertSame(1, $this->client->llmbudget()->reset($name));
            self::assertSame(1, $this->client->llmbudget()->drop($name));
        });
    }

    public function testConsumeWithUsageIsPricedByTheServer(): void
    {
        $this->withLicense(function (): void {
            $name = $this->ns('llmbudget-usage');
            self::assertSame('OK', $this->client->llmbudget()->set($name, 10_000, 5.0, 3600));

            $usage = new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20);
            self::assertSame(1, $this->client->llmbudget()->consume($name, usage: $usage));

            $status = $this->client->llmbudget()->status($name);
            self::assertSame(120, $status['tokens_used']);
            self::assertGreaterThan(0.0, (float) $status['cost_used_usd']);
        });
    }

    public function testSetAcceptsTinyAndFractionalLimitsInFixedPointForm(): void
    {
        $this->withLicense(function (): void {
            // PHP renders 1.0E-5 in exponent form; the server's money parser
            // rejects that, so the SDK must send plain fixed-point text.
            $name = $this->ns('llmbudget-float');
            foreach ([[1.0E-5, '0.000010'], [0.000012, '0.000012'], [50.0, '50.000000'], [12.5, '12.500000'], [1.0E+6, '1000000.000000']] as [$limit, $expected]) {
                self::assertSame('OK', $this->client->llmbudget()->set($name, 1000, $limit, 3600), "limit {$limit}");
                self::assertSame($expected, $this->client->llmbudget()->status($name)['cost_limit_usd']);
            }
            self::assertSame(1, $this->client->llmbudget()->drop($name));
        });
    }

    public function testConsumeAcceptsTinyCosts(): void
    {
        $this->withLicense(function (): void {
            $name = $this->ns('llmbudget-tiny');
            $this->client->llmbudget()->set($name, 1000, 1.0, 3600);
            self::assertSame(1, $this->client->llmbudget()->consume($name, 1, 1.0E-5));
            self::assertSame(1, $this->client->llmbudget()->consume($name, 1, 2.5E-6));
            self::assertEqualsWithDelta(1.25E-5, (float) $this->client->llmbudget()->status($name)['cost_used_usd'], 1.0E-6);
            self::assertSame(1, $this->client->llmbudget()->drop($name));
        });
    }

    public function testSetRejectsAnAmountBelowOneMicroDollarBeforeSendingIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->llmbudget()->set('never-sent', 1000, 1.0E-9, 3600);
    }
}
