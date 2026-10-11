<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Unit;

use HotKV\ClientOptions;
use HotKV\Exception\HotKVException;
use PHPUnit\Framework\TestCase;

final class ClientOptionsTest extends TestCase
{
    public function testParsesBasicHotkvUrl(): void
    {
        $opts = ClientOptions::fromUrl('hotkv://127.0.0.1:6399');
        self::assertSame('127.0.0.1', $opts->host);
        self::assertSame(6399, $opts->port);
        self::assertFalse($opts->tls);
        self::assertNull($opts->username);
        self::assertNull($opts->password);
        self::assertSame(0, $opts->database);
    }

    public function testDefaultsHostAndPortWhenOmitted(): void
    {
        $opts = ClientOptions::fromUrl('hotkv://');
        self::assertSame('127.0.0.1', $opts->host);
        self::assertSame(6379, $opts->port);
    }

    public function testHotkvsAndRedissEnableTls(): void
    {
        self::assertTrue(ClientOptions::fromUrl('hotkvs://host:1234')->tls);
        self::assertTrue(ClientOptions::fromUrl('rediss://host:1234')->tls);
        self::assertFalse(ClientOptions::fromUrl('redis://host:1234')->tls);
    }

    public function testParsesUserAndPassword(): void
    {
        $opts = ClientOptions::fromUrl('hotkv://alice:s3cret@host:6399');
        self::assertSame('alice', $opts->username);
        self::assertSame('s3cret', $opts->password);
    }

    public function testSingleUserinfoComponentIsLegacyPasswordOnlyAuth(): void
    {
        $opts = ClientOptions::fromUrl('redis://:s3cret@host:6399');
        self::assertNull($opts->username);
        self::assertSame('s3cret', $opts->password);

        $opts2 = ClientOptions::fromUrl('redis://s3cret@host:6399');
        self::assertNull($opts2->username);
        self::assertSame('s3cret', $opts2->password);
    }

    public function testParsesDatabaseIndexFromPath(): void
    {
        $opts = ClientOptions::fromUrl('hotkv://host:6399/3');
        self::assertSame(3, $opts->database);
    }

    public function testUrlDecodesUserAndPassword(): void
    {
        $opts = ClientOptions::fromUrl('hotkv://us%20er:pa%40ss@host:6399');
        self::assertSame('us er', $opts->username);
        self::assertSame('pa@ss', $opts->password);
    }

    public function testInvalidDatabaseIndexThrows(): void
    {
        $this->expectException(HotKVException::class);
        ClientOptions::fromUrl('hotkv://host:6399/not-a-number');
    }

    public function testUnsupportedSchemeThrows(): void
    {
        $this->expectException(HotKVException::class);
        ClientOptions::fromUrl('ftp://host:6399');
    }

    public function testUnixSchemeParsesSocketPath(): void
    {
        $opts = ClientOptions::fromUrl('unix:///var/run/hotkv.sock?db=2');
        self::assertSame('/var/run/hotkv.sock', $opts->path);
        self::assertSame(2, $opts->database);
    }

    public function testFromArrayAcceptsCamelAndSnakeCaseKeys(): void
    {
        $opts = ClientOptions::fromArray(['host' => 'h', 'connectTimeout' => 1.5]);
        self::assertSame(1.5, $opts->connectTimeout);

        $opts2 = ClientOptions::fromArray(['host' => 'h', 'connect_timeout' => 2.5]);
        self::assertSame(2.5, $opts2->connectTimeout);
    }

    public function testFromArrayWithUrlKeyAppliesOverrides(): void
    {
        $opts = ClientOptions::fromArray(['url' => 'hotkv://host:6399', 'password' => 'override']);
        self::assertSame('host', $opts->host);
        self::assertSame('override', $opts->password);
    }

    public function testConstructorDefaults(): void
    {
        $opts = new ClientOptions();
        self::assertSame('127.0.0.1', $opts->host);
        self::assertSame(6379, $opts->port);
        self::assertSame(3, $opts->protocol);
        self::assertTrue($opts->retryReadsOnce);
        self::assertSame(1, $opts->poolSize);
    }

    public function testUrlErrorMessagesNeverContainThePassword(): void
    {
        self::assertSame('hotkvs://***@h:1/x', ClientOptions::redactUrl('hotkvs://user:s3cret@h:1/x'));
        self::assertSame('hotkv://***@h', ClientOptions::redactUrl('hotkv://:s3cret@h'));
        self::assertSame('hotkv://h:6379', ClientOptions::redactUrl('hotkv://h:6379'));
        try {
            ClientOptions::fromUrl('unix://u:s3cret@');
            self::fail('expected an exception');
        } catch (\HotKV\Exception\HotKVException $e) {
            self::assertStringNotContainsString('s3cret', $e->getMessage());
        }
        try {
            ClientOptions::fromUrl('hotkv://u:s3cret@h:6379/notanumber');
            self::fail('expected an exception');
        } catch (\HotKV\Exception\HotKVException $e) {
            self::assertStringNotContainsString('s3cret', $e->getMessage());
        }
    }
}
