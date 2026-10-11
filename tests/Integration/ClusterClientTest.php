<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

use HotKV\ClientOptions;
use HotKV\Cluster\ClusterClient;
use HotKV\Exception\ConnectionException;

/**
 * The HOTKV_TEST_URL server is a single, standalone node (see
 * SDK_AGENT_BRIEF.md / start_test_server.sh): it has cluster mode
 * compiled in but no slots assigned ("cluster_state:fail" in CLUSTER
 * INFO), so real cross-node routing cannot be exercised here. CRC16 slot
 * math and MOVED/ASK parsing (the parts that do not need a live cluster)
 * are covered by unit tests instead; this test only checks that
 * ClusterClient behaves sanely against a non-cluster node.
 */
final class ClusterClientTest extends IntegrationTestCase
{
    private function seed(): string
    {
        $url = getenv('HOTKV_TEST_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('HOTKV_TEST_URL is not set; skipping integration tests.');
        }
        $opts = ClientOptions::fromUrl($url);

        return "{$opts->host}:{$opts->port}";
    }

    public function testRefreshSlotsFailsClearlyAgainstANonClusterNode(): void
    {
        $cluster = new ClusterClient([$this->seed()]);
        $this->expectException(ConnectionException::class);
        $cluster->refreshSlots();
    }

    public function testNodeClientReachesTheSeedDirectlyWithoutRouting(): void
    {
        $url = getenv('HOTKV_TEST_URL');
        $opts = ClientOptions::fromUrl($url);
        $cluster = new ClusterClient([$this->seed()]);

        $node = $cluster->nodeClient($opts->host, $opts->port);
        self::assertSame('PONG', $node->ping());
        $cluster->close();
    }

    public function testInvalidSeedFormatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClusterClient(['not-a-valid-seed-without-port']);
    }

    public function testEmptySeedListThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClusterClient([]);
    }
}
