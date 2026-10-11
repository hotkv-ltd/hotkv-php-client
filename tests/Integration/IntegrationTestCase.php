<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

use HotKV\Client;
use HotKV\Exception\NotLicensedException;
use PHPUnit\Framework\TestCase;

/**
 * Base class for every integration test: connects to HOTKV_TEST_URL, or
 * skips the whole test if it is not set. Each test uses key prefixes
 * unique to that test run and cleans them up afterward.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Client $client;

    /** @var list<string> */
    private array $cleanupKeys = [];

    protected function setUp(): void
    {
        $url = getenv('HOTKV_TEST_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('HOTKV_TEST_URL is not set; skipping integration tests.');
        }
        $this->client = new Client($url);
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            if ($this->cleanupKeys !== []) {
                try {
                    $this->client->del($this->cleanupKeys);
                } catch (\Throwable) {
                    // Best effort: a test that already deleted its own keys, or
                    // one whose key never existed, must not fail teardown.
                }
            }
            $this->client->close();
        }
        $this->cleanupKeys = [];
    }

    /** A key unique to this test run, tracked for automatic cleanup via DEL. */
    protected function key(string $suffix = ''): string
    {
        $k = 'hotkv-php-test:' . bin2hex(random_bytes(6)) . ($suffix !== '' ? ":{$suffix}" : '');
        $this->cleanupKeys[] = $k;

        return $k;
    }

    /** A namespace/bucket/group name unique to this test run for the HotKV specific command families (not cleaned up via DEL). */
    protected function ns(string $suffix = ''): string
    {
        return 'hotkv-php-test-' . bin2hex(random_bytes(6)) . ($suffix !== '' ? "-{$suffix}" : '');
    }

    /**
     * Runs $fn; if the server rejects it with NOLICENSE (its license does
     * not include the feature), the test is skipped with a clear reason
     * instead of failing, so it runs automatically once a license that
     * includes the feature is present.
     */
    protected function withLicense(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (NotLicensedException $e) {
            self::markTestSkipped('not licensed: ' . $e->getMessage());
        }
    }

    /**
     * Compares a sorted-set style score numerically: under RESP3 the
     * server sends scores as native doubles (PHP float), under RESP2 as
     * a formatted bulk string, so tests must not assume either exact
     * type or exact string formatting.
     */
    protected function assertScore(float $expected, mixed $actual, float $delta = 1e-9): void
    {
        self::assertIsNotArray($actual);
        self::assertEqualsWithDelta($expected, (float) $actual, $delta);
    }

    /** @param array<string, float> $expected @param array<string, mixed> $actual */
    protected function assertScoreMap(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);
        self::assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $member => $score) {
            $this->assertScore($score, $actual[$member]);
        }
    }
}
