<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;

/** EMBED.DEDUP.*: needs a server license that includes the embed-dedup feature. */
final class EmbedDedupCommandsTest extends IntegrationTestCase
{
    public function testLifecycle(): void
    {
        $this->withLicense(function (): void {
            $ns = $this->ns('embed-dedup');
            $hash = hash('sha256', 'some text to embed');

            self::assertSame(1, $this->client->embedDedup()->put($ns, $hash, 'text-embedding-3-small', 'v1,v2,v3'));
            self::assertSame(0, $this->client->embedDedup()->put($ns, $hash, 'text-embedding-3-small', 'v1,v2,v3'));

            self::assertSame('v1,v2,v3', $this->client->embedDedup()->get($ns, $hash));

            $stats = $this->client->embedDedup()->stats($ns);
            self::assertSame(1, $stats['entries']);

            self::assertContains($ns, $this->client->embedDedup()->list());

            self::assertSame(1, $this->client->embedDedup()->release($ns, $hash));
            self::assertGreaterThanOrEqual(0, $this->client->embedDedup()->purge($ns));
            self::assertSame(1, $this->client->embedDedup()->drop($ns));
        });
    }
}
