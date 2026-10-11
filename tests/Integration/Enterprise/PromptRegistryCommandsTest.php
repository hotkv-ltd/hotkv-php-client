<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration\Enterprise;

use HotKV\Tests\Integration\IntegrationTestCase;

/** PROMPT.*: needs a server license that includes the prompt-registry feature. */
final class PromptRegistryCommandsTest extends IntegrationTestCase
{
    public function testLifecycle(): void
    {
        $this->withLicense(function (): void {
            $name = $this->ns('prompt');
            self::assertSame(1, $this->client->prompt()->put($name, 'You are a helpful assistant.'));
            self::assertSame(2, $this->client->prompt()->put($name, 'You are an even more helpful assistant.', meta: '{"author":"tests"}'));

            $latest = $this->client->prompt()->latest($name);
            self::assertSame(2, $latest[0]);
            self::assertSame('You are an even more helpful assistant.', $latest[1]);

            $v1 = $this->client->prompt()->get($name, 1);
            self::assertSame('You are a helpful assistant.', $v1[1]);

            self::assertSame([1, 2], $this->client->prompt()->versions($name));
            self::assertSame('OK', $this->client->prompt()->setActive($name, 1));

            self::assertContains($name, $this->client->prompt()->list());
            self::assertSame(1, $this->client->prompt()->drop($name));
        });
    }

    public function testGetOfMissingTemplateIsNull(): void
    {
        $this->withLicense(function (): void {
            self::assertNull($this->client->prompt()->get($this->ns('missing')));
        });
    }
}
