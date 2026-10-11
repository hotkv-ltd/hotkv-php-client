<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

use HotKV\PubSub\Message;
use HotKV\PubSub\Subscriber;

final class PubSubTest extends IntegrationTestCase
{
    /**
     * Starts a tiny standalone PHP script in the background that
     * publishes one message shortly after starting, and returns a handle
     * to reap with reapPublisher() once the subscriber loop is done.
     * Spawning must not block: the whole point is that the publish
     * happens concurrently with (and slightly after) this process
     * blocking in subscribe(), not before it.
     *
     * @return array{0: resource, 1: array<int, resource>, 2: string}
     */
    private function spawnPublisher(string $command, string $channel, string $payload): array
    {
        $script = sprintf(
            '<?php require %s; $c = new HotKV\Client(%s); usleep(150000); $c->command(%s, %s, %s); $c->close();',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export(getenv('HOTKV_TEST_URL'), true),
            var_export($command, true),
            var_export($channel, true),
            var_export($payload, true),
        );
        $file = tempnam(sys_get_temp_dir(), 'hotkv_pub_');
        file_put_contents($file, $script);
        $proc = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);

        return [$proc, $pipes, $file];
    }

    /** @param array{0: resource, 1: array<int, resource>, 2: string} $handle */
    private function reapPublisher(array $handle): void
    {
        [$proc, $pipes, $file] = $handle;
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($proc);
        @unlink($file);
    }

    public function testSubscribeReceivesPublishedMessage(): void
    {
        $channel = 'hotkv-php-test-pubsub-' . bin2hex(random_bytes(4));
        $handle = $this->spawnPublisher('PUBLISH', $channel, 'hello-world');

        $received = null;
        $this->client->subscribe([$channel], function (Message $message, Subscriber $subscriber) use (&$received): void {
            $received = $message;
            $subscriber->stop();
        });
        $this->reapPublisher($handle);

        self::assertNotNull($received);
        self::assertSame('message', $received->kind);
        self::assertSame($channel, $received->channel);
        self::assertNull($received->pattern);
        self::assertSame('hello-world', $received->payload);
    }

    public function testPsubscribeReceivesPatternAndMatchedChannel(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $pattern = "hotkv-php-test-pubsub-{$suffix}.*";
        $channel = "hotkv-php-test-pubsub-{$suffix}.room1";
        $handle = $this->spawnPublisher('PUBLISH', $channel, 'payload1');

        $received = null;
        $this->client->psubscribe([$pattern], function (Message $message, Subscriber $subscriber) use (&$received): void {
            $received = $message;
            $subscriber->stop();
        });
        $this->reapPublisher($handle);

        self::assertNotNull($received);
        self::assertSame('pmessage', $received->kind);
        self::assertSame($channel, $received->channel);
        self::assertSame($pattern, $received->pattern);
        self::assertSame('payload1', $received->payload);
    }

    public function testSsubscribeReceivesShardMessage(): void
    {
        $channel = 'hotkv-php-test-pubsub-shard-' . bin2hex(random_bytes(4));
        $handle = $this->spawnPublisher('SPUBLISH', $channel, 'shard-payload');

        $received = null;
        $this->client->ssubscribe([$channel], function (Message $message, Subscriber $subscriber) use (&$received): void {
            $received = $message;
            $subscriber->stop();
        });
        $this->reapPublisher($handle);

        self::assertNotNull($received);
        self::assertSame('smessage', $received->kind);
        self::assertSame($channel, $received->channel);
        self::assertSame('shard-payload', $received->payload);
    }

    public function testStopEndsTheLoopAfterTheCurrentMessage(): void
    {
        $channel = 'hotkv-php-test-pubsub-' . bin2hex(random_bytes(4));
        $handle = $this->spawnPublisher('PUBLISH', $channel, 'only-message');

        $count = 0;
        $this->client->subscribe([$channel], function (Message $message, Subscriber $subscriber) use (&$count): void {
            $count++;
            $subscriber->stop();
        });
        $this->reapPublisher($handle);

        self::assertSame(1, $count);
    }

    public function testPubsubChannelsAndNumsub(): void
    {
        $channel = 'hotkv-php-test-pubsub-introspect-' . bin2hex(random_bytes(4));
        $seen = false;

        // Publish nothing; just verify the introspection commands work
        // and, if nobody is subscribed, report zero.
        $numsub = $this->client->pubsubNumsub([$channel]);
        self::assertSame([$channel, 0], $numsub);
        self::assertIsArray($this->client->pubsubChannels());
        self::assertIsInt($this->client->pubsubNumpat());
        self::assertIsArray($this->client->pubsubShardChannels());
        self::assertFalse($seen);
    }
}
