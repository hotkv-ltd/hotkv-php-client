<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;

/** OBSERVE: needs a server license that includes the enterprise-observability feature. */
final class ObserveCommandsTest extends IntegrationTestCase
{
    public function testInfo(): void
    {
        $this->withLicense(function (): void {
            $info = $this->client->observe()->info();
            self::assertArrayHasKey('uptime_seconds', $info);
            self::assertArrayHasKey('connected_clients', $info);
            self::assertGreaterThanOrEqual(1, $info['connected_clients']);
        });
    }

    public function testMetrics(): void
    {
        $this->withLicense(function (): void {
            $metrics = $this->client->observe()->metrics();
            self::assertArrayHasKey('total_commands', $metrics);
            self::assertGreaterThan(0, $metrics['total_commands']);
        });
    }
}
