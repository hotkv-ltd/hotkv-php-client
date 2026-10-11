<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;

final class HotkeysCommandsTest extends IntegrationTestCase
{
    public function testTopAndReset(): void
    {
        $k = $this->key();
        $this->client->set($k, 'v');
        for ($i = 0; $i < 5; $i++) {
            $this->client->get($k);
        }

        $top = $this->client->hotkeys()->top(5);
        self::assertIsArray($top);

        self::assertSame('OK', $this->client->hotkeys()->reset());
    }
}
