<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

use HotKV\Client;
use HotKV\Exception\ServerException;
use HotKV\Protocol\PushMessage;

/**
 * The generic command()/execute() method is how commands without a typed
 * wrapper are reached. This exercises it
 * live against families the SDK has no typed methods for.
 */
final class GenericCommandTest extends IntegrationTestCase
{
    public function testClientTrackingReplyStaysInSyncWhenInvalidationPushesArrive(): void
    {
        $pushes = [];
        $other = new Client(getenv('HOTKV_TEST_URL'));
        try {
            $this->client->onPush(static function (PushMessage $m) use (&$pushes): void {
                $pushes[] = $m->elements;
            });
            self::assertSame('OK', $this->client->command('CLIENT', 'TRACKING', 'ON'));
            $k = $this->key();
            $this->client->set($k, '1');
            self::assertSame('1', $this->client->get($k));
            $other->set($k, '2');

            // The invalidation frame arrives before this reply; it must not be taken for it.
            self::assertSame('PONG', $this->client->ping());
            self::assertSame('2', $this->client->get($k));
            self::assertSame([['invalidate', [$k]]], $pushes);

            // Pipelines read replies the same way.
            $this->client->get($k);
            $other->set($k, '3');
            self::assertSame(['PONG', '3'], $this->client->pipeline(function ($p) use ($k) {
                $p->ping();
                $p->get($k);
            }));
            self::assertCount(2, $pushes);

            $info = $this->client->command('CLIENT', 'TRACKINGINFO');
            self::assertIsArray($info);
            self::assertSame('OK', $this->client->command('CLIENT', 'TRACKING', 'OFF'));
        } finally {
            $other->close();
        }
    }

    public function testPushesAreDroppedWhenNoHandlerIsSet(): void
    {
        $other = new Client(getenv('HOTKV_TEST_URL'));
        try {
            $this->client->command('CLIENT', 'TRACKING', 'ON');
            $k = $this->key();
            $this->client->set($k, '1');
            $this->client->get($k);
            $other->set($k, '2');
            self::assertSame('PONG', $this->client->ping());
            self::assertSame('2', $this->client->get($k));
        } finally {
            $other->close();
        }
    }

    public function testClientAndAclIntrospection(): void
    {
        self::assertStringContainsString('addr=', (string) $this->client->command('CLIENT', 'LIST'));
        self::assertSame('default', $this->client->command('ACL', 'WHOAMI'));
        self::assertGreaterThan(100, $this->client->command('COMMAND', 'COUNT'));
        self::assertSame(-1, $this->client->command('CLIENT', 'GETREDIR'));
    }

    public function testBloomCountMinAndTopK(): void
    {
        $bf = $this->key('bf');
        self::assertSame('OK', $this->client->command('BF.RESERVE', $bf, '0.01', '100'));
        // RESP3 sends BF replies as booleans, RESP2 as integers.
        self::assertSame(1, (int) $this->client->command('BF.ADD', $bf, 'a'));
        self::assertSame(1, (int) $this->client->command('BF.EXISTS', $bf, 'a'));
        self::assertSame(0, (int) $this->client->command('BF.EXISTS', $bf, 'b'));

        $cms = $this->key('cms');
        self::assertSame('OK', $this->client->command('CMS.INITBYDIM', $cms, '100', '5'));
        self::assertSame([3], $this->client->command('CMS.INCRBY', $cms, 'a', '3'));
        self::assertSame([3], $this->client->command('CMS.QUERY', $cms, 'a'));

        $tk = $this->key('topk');
        self::assertSame('OK', $this->client->command('TOPK.RESERVE', $tk, '3'));
        $this->client->command('TOPK.ADD', $tk, 'a', 'b', 'a');
        self::assertSame(['a', 'b'], $this->client->command('TOPK.LIST', $tk));
    }

    public function testEnterpriseAdminFamiliesAnswerOnALicensedServer(): void
    {
        $this->withLicense(function (): void {
            $audit = $this->client->command('AUDIT.INFO');
            self::assertSame('total_entries', $audit[0]);
            $enc = $this->client->command('ENCRYPTION.INFO');
            self::assertSame('enabled', $enc[0]);
            $repl = $this->client->command('REPLICATION.INFO');
            self::assertSame('role', $repl[0]);
            self::assertIsArray($this->client->command('TENANT.LIST'));
            self::assertIsArray($this->client->command('IAM.ROLE.LIST'));
        });
    }

    public function testServerErrorsFromTheGenericMethodAreTypedExceptions(): void
    {
        try {
            $this->client->command('NOT-A-COMMAND');
            self::fail('expected an error');
        } catch (ServerException $e) {
            self::assertSame('ERR', $e->getPrefix());
        }
        $k = $this->key();
        $this->client->set($k, 'x');
        $this->expectException(\HotKV\Exception\WrongTypeException::class);
        $this->client->command('LPUSH', $k, 'y');
    }

    public function testBinarySafeArgumentsAndValues(): void
    {
        $k = $this->key();
        $value = '';
        for ($i = 0; $i < 256; $i++) {
            $value .= chr($i);
        }
        $value .= "\r\n\$5\r\n*3\r\n";
        self::assertSame('OK', $this->client->command('SET', $k, $value));
        self::assertSame($value, $this->client->command('GET', $k));
        self::assertSame(strlen($value), $this->client->command('STRLEN', $k));
        $binKey = $k . "\x00\xff\r\n";
        self::assertSame('OK', $this->client->command('SET', $binKey, 'v'));
        self::assertSame('v', $this->client->command('GET', $binKey));
        $this->client->command('DEL', $binKey);
    }

    public function testClientRecoversAfterTheServerClosesItsConnection(): void
    {
        $other = new Client(getenv('HOTKV_TEST_URL'));
        try {
            $k = $this->key();
            $this->client->set($k, 'v');
            // Read: retried once on a fresh connection.
            self::assertSame(1, $other->command('CLIENT', 'KILL', 'ID', $this->client->clientId()));
            self::assertSame('v', $this->client->get($k));
            // Write: not retried, but the dead socket must not poison later commands.
            self::assertSame(1, $other->command('CLIENT', 'KILL', 'ID', $this->client->clientId()));
            try {
                $this->client->set($k, 'w');
            } catch (\HotKV\Exception\ConnectionException) {
                // expected: a write is never retried blindly
            }
            self::assertSame('OK', $this->client->set($k, 'x'));
            self::assertSame('x', $this->client->get($k));
        } finally {
            $other->close();
        }
    }
}
