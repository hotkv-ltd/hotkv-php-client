<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Exception\NotLicensedException;
use HotKV\Exception\ServerException;
use HotKV\Tests\Integration\IntegrationTestCase;

/** ALERTS: needs a server license that includes the enterprise-observability feature. */
final class AlertsCommandsTest extends IntegrationTestCase
{
    public function testInfo(): void
    {
        $this->withLicense(function (): void {
            $info = $this->client->alerts()->info();
            self::assertArrayHasKey('enabled', $info);
            self::assertArrayHasKey('supported_providers', $info);
        });
    }

    public function testSetUrlSeverityAndToggle(): void
    {
        $this->withLicense(function (): void {
            self::assertStringStartsWith('OK', $this->client->alerts()->setUrl(''));
            self::assertStringStartsWith('OK', $this->client->alerts()->setSeverity('warning'));
            self::assertStringStartsWith('OK', $this->client->alerts()->off());
            self::assertStringStartsWith('OK', $this->client->alerts()->on());
        });
    }

    public function testTestAlertWithSeverityAndMessage(): void
    {
        // With no webhook URL configured, HotKV may reject the test alert
        // (queue/rate limited) rather than deliver it; either a
        // successful "OK..." or a rejection error is an acceptable,
        // well-formed reply for this command. NotLicensedException is
        // caught first (it is itself a ServerException) so it skips
        // instead of being mistaken for that rejection.
        try {
            $reply = $this->client->alerts()->test('info', 'hello from the php sdk tests');
            self::assertStringStartsWith('OK', $reply);
        } catch (NotLicensedException $e) {
            self::markTestSkipped('not licensed: ' . $e->getMessage());
        } catch (ServerException $e) {
            self::assertSame('ERR', $e->getPrefix());
        }
    }
}
