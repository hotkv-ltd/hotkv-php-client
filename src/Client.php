<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV;

use HotKV\Commands\BitmapCommands;
use HotKV\Commands\GeoCommands;
use HotKV\Commands\HashCommands;
use HotKV\Commands\HyperLogLogCommands;
use HotKV\Commands\KeyCommands;
use HotKV\Commands\ListCommands;
use HotKV\Commands\PubSubCommands;
use HotKV\Commands\ScanCommands;
use HotKV\Commands\ScriptingCommands;
use HotKV\Commands\ServerCommands;
use HotKV\Commands\SetCommands;
use HotKV\Commands\SortedSetCommands;
use HotKV\Commands\StreamCommands;
use HotKV\Commands\StringCommands;
use HotKV\Exception\ConnectionException;
use HotKV\Exception\ServerException;
use HotKV\PubSub\Subscriber;
use HotKV\Enterprise\AlertsCommands;
use HotKV\Enterprise\AmemCommands;
use HotKV\Enterprise\EmbedDedupCommands;
use HotKV\Enterprise\FeatureCommands;
use HotKV\Enterprise\GcraCommands;
use HotKV\Enterprise\HotkeysCommands;
use HotKV\Enterprise\KeyedCacheCommands;
use HotKV\Enterprise\LlmBudgetCommands;
use HotKV\Enterprise\LlmStatsCommands;
use HotKV\Enterprise\ObserveCommands;
use HotKV\Enterprise\PcacheCommands;
use HotKV\Enterprise\PromptRegistryCommands;
use HotKV\Enterprise\RagCommands;
use HotKV\Enterprise\RatelimitCommands;
use HotKV\Enterprise\ScacheCommands;
use HotKV\Enterprise\TagCommands;
use HotKV\Enterprise\TsCommands;
use HotKV\Enterprise\VsimCommands;
use HotKV\Support\Idempotency;

/**
 * A connection to one HotKV server: `new Client('hotkv://127.0.0.1:6399')`
 * or `new Client(['host' => ..., 'password' => ...])`.
 *
 * Command coverage lives in traits shared with Pipeline and Transaction
 * (so the same typed method works standalone, pipelined or queued) plus
 * accessor methods for every HotKV exclusive and AI command family
 * (`$client->scache()`, `->pcache()`, `->amem()`, `->rag()`,
 * `->llmstats()`, and so on).
 */
final class Client implements CommandDispatcher, CommandExecutor
{
    use StringCommands;
    use KeyCommands;
    use ListCommands;
    use SetCommands;
    use HashCommands;
    use SortedSetCommands;
    use StreamCommands;
    use GeoCommands;
    use BitmapCommands;
    use HyperLogLogCommands;
    use ServerCommands;
    use ScriptingCommands;
    use ScanCommands;
    use PubSubCommands;

    private readonly ClientOptions $options;
    private readonly ConnectionPool $pool;
    private ?Connection $pinned;

    private ?TagCommands $tagCommands = null;
    private ?HotkeysCommands $hotkeysCommands = null;
    private ?GcraCommands $gcraCommands = null;
    private ?VsimCommands $vsimCommands = null;
    private ?ScacheCommands $scacheCommands = null;
    private ?PcacheCommands $pcacheCommands = null;
    private ?KeyedCacheCommands $toolCacheCommands = null;
    private ?KeyedCacheCommands $ncacheCommands = null;
    private ?KeyedCacheCommands $guardCommands = null;
    private ?KeyedCacheCommands $rerankCommands = null;
    private ?LlmStatsCommands $llmStatsCommands = null;
    private ?LlmBudgetCommands $llmBudgetCommands = null;
    private ?PromptRegistryCommands $promptCommands = null;
    private ?EmbedDedupCommands $embedDedupCommands = null;
    private ?AmemCommands $amemCommands = null;
    private ?RagCommands $ragCommands = null;
    private ?RatelimitCommands $ratelimitCommands = null;
    private ?TsCommands $tsCommands = null;
    private ?FeatureCommands $featureCommands = null;
    private ?ObserveCommands $observeCommands = null;
    private ?AlertsCommands $alertsCommands = null;

    /**
     * @param string|array<string, mixed>|ClientOptions $options a
     *     "hotkv://" (or "hotkvs://", "redis://", "rediss://", "unix://")
     *     URL, an option array (see ClientOptions::fromArray()), or an
     *     already built ClientOptions
     */
    public function __construct(string|array|ClientOptions $options = [])
    {
        $this->options = match (true) {
            $options instanceof ClientOptions => $options,
            is_string($options) => ClientOptions::fromUrl($options),
            default => ClientOptions::fromArray($options),
        };
        $this->pool = new ConnectionPool($this->options);
        $this->pinned = $this->options->poolSize <= 1 ? new Connection($this->options) : null;
    }

    /**
     * Registers a callback for RESP3 out of band push messages that arrive
     * between command replies, e.g. the "invalidate" frames after
     * `$client->command('CLIENT', 'TRACKING', 'ON')`. They are never
     * mistaken for a command's reply; without a callback they are dropped.
     * Pass null to remove it. Connections opened for pub/sub are separate
     * and unaffected.
     *
     * @param (callable(Protocol\PushMessage): void)|null $handler
     */
    public function onPush(?callable $handler): void
    {
        $this->pool->setPushHandler($handler);
        $this->pinned?->setPushHandler($handler);
    }

    public function options(): ClientOptions
    {
        return $this->options;
    }

    /**
     * Sends any command and returns its decoded reply. The escape hatch
     * for commands this SDK does not wrap with a typed method.
     */
    public function command(string|int|float|bool ...$args): mixed
    {
        return $this->execute(array_values($args));
    }

    /** @param list<string|int|float|bool> $args */
    public function execute(array $args): mixed
    {
        return $this->dispatch($args);
    }

    public function dispatch(array $args): mixed
    {
        $retryable = $this->options->retryReadsOnce && Idempotency::isReadOnly($args);
        $conn = $this->acquire();
        try {
            try {
                return $conn->sendCommand($args);
            } catch (ConnectionException $e) {
                if (!$retryable) {
                    throw $e;
                }
                $conn->close();

                return $conn->sendCommand($args);
            }
        } finally {
            $this->release($conn);
        }
    }

    /**
     * Queues every command issued on the Pipeline inside the callback and
     * sends them all in a single round trip. Returns the replies in
     * order; a reply that was a server error is a ServerException object
     * in that slot rather than a thrown exception, so one bad command
     * never discards the rest of the batch.
     *
     * @param callable(Pipeline): void $fn
     * @return list<mixed>
     */
    public function pipeline(callable $fn): array
    {
        $conn = $this->acquireSession();
        try {
            $pipeline = new Pipeline($conn);
            $fn($pipeline);

            return $pipeline->execute();
        } finally {
            $this->releaseSession($conn);
        }
    }

    /**
     * Runs a MULTI/EXEC transaction: every command issued on the
     * Transaction inside the callback is queued with MULTI and sent with
     * EXEC. Returns the results in order, or null if the transaction was
     * aborted (a WATCHed key changed). Call watch() on the client before
     * calling transaction() to make EXEC conditional.
     *
     * @param callable(Transaction): void $fn
     * @return list<mixed>|null
     */
    public function transaction(callable $fn): ?array
    {
        $conn = $this->acquireSession();
        try {
            $tx = new Transaction($conn);
            try {
                $fn($tx);

                return $tx->exec();
            } catch (\Throwable $e) {
                $tx->discard();

                throw $e;
            }
        } finally {
            $this->releaseSession($conn);
        }
    }

    /**
     * Watches keys for changes ahead of a transaction() call: EXEC will
     * abort (return null) if any watched key was modified first. Uses the
     * client's single connection, so it only composes correctly with a
     * Client built without a multi connection pool (the default).
     */
    public function watch(string ...$keys): void
    {
        if ($keys === []) {
            return;
        }
        $this->dispatch(array_merge(['WATCH'], array_values($keys)));
    }

    public function unwatch(): void
    {
        $this->dispatch(['UNWATCH']);
    }

    /**
     * Runs a script by SHA1, loading and retrying it once with EVAL if
     * the server has not seen that script before ("NOSCRIPT"). Needs the
     * real, synchronous reply of the first attempt, so unlike eval() and
     * evalsha() this is only available directly on Client.
     *
     * @param list<string> $keys
     * @param list<string> $args
     */
    public function evalOrLoad(string $script, array $keys = [], array $args = []): mixed
    {
        try {
            return $this->evalsha(sha1($script), $keys, $args);
        } catch (ServerException $e) {
            if ($e->getPrefix() !== 'NOSCRIPT') {
                throw $e;
            }

            return $this->eval($script, $keys, $args);
        }
    }

    /**
     * Subscribes to channels and blocks, invoking $onMessage for every
     * message, until the subscriber's stop() is called (from inside the
     * callback, given as the second argument) or the connection drops.
     *
     * @param list<string> $channels
     * @param callable(PubSub\Message, Subscriber): void $onMessage
     */
    public function subscribe(array $channels, callable $onMessage): void
    {
        $this->runSubscriber('SUBSCRIBE', 'UNSUBSCRIBE', $channels, $onMessage);
    }

    /** @param list<string> $patterns @param callable(PubSub\Message, Subscriber): void $onMessage */
    public function psubscribe(array $patterns, callable $onMessage): void
    {
        $this->runSubscriber('PSUBSCRIBE', 'PUNSUBSCRIBE', $patterns, $onMessage);
    }

    /** @param list<string> $channels @param callable(PubSub\Message, Subscriber): void $onMessage */
    public function ssubscribe(array $channels, callable $onMessage): void
    {
        $this->runSubscriber('SSUBSCRIBE', 'SUNSUBSCRIBE', $channels, $onMessage);
    }

    private function runSubscriber(string $subscribeCmd, string $unsubscribeCmd, array $names, callable $onMessage): void
    {
        // Pub/sub takes over its own connection for as long as the
        // subscription runs: it is never the pinned/pooled connection
        // used by ordinary commands, so normal command traffic is
        // unaffected.
        $conn = new Connection($this->options);
        try {
            $subscriber = new Subscriber($conn, $subscribeCmd, $unsubscribeCmd, $names);
            $subscriber->run($onMessage);
        } finally {
            $conn->close();
        }
    }

    public function close(): void
    {
        if ($this->pinned !== null) {
            $this->pinned->close();
        }
        $this->pool->close();
    }

    private function acquire(): Connection
    {
        return $this->pinned ?? $this->pool->borrow();
    }

    private function release(Connection $conn): void
    {
        if ($this->pinned === null) {
            $this->pool->release($conn);
        }
    }

    /** Acquires a connection pinned for a whole pipeline/transaction session. */
    private function acquireSession(): Connection
    {
        return $this->acquire();
    }

    private function releaseSession(Connection $conn): void
    {
        $this->release($conn);
    }

    public function tag(): TagCommands
    {
        return $this->tagCommands ??= new TagCommands($this);
    }

    public function hotkeys(): HotkeysCommands
    {
        return $this->hotkeysCommands ??= new HotkeysCommands($this);
    }

    public function gcra(): GcraCommands
    {
        return $this->gcraCommands ??= new GcraCommands($this);
    }

    public function vsim(): VsimCommands
    {
        return $this->vsimCommands ??= new VsimCommands($this);
    }

    public function scache(): ScacheCommands
    {
        return $this->scacheCommands ??= new ScacheCommands($this);
    }

    public function pcache(): PcacheCommands
    {
        return $this->pcacheCommands ??= new PcacheCommands($this);
    }

    public function toolCache(): KeyedCacheCommands
    {
        return $this->toolCacheCommands ??= new KeyedCacheCommands($this, 'TOOL.CACHE');
    }

    public function ncache(): KeyedCacheCommands
    {
        return $this->ncacheCommands ??= new KeyedCacheCommands($this, 'NCACHE');
    }

    public function guard(): KeyedCacheCommands
    {
        return $this->guardCommands ??= new KeyedCacheCommands($this, 'GUARD');
    }

    public function rerank(): KeyedCacheCommands
    {
        return $this->rerankCommands ??= new KeyedCacheCommands($this, 'RERANK');
    }

    public function llmstats(): LlmStatsCommands
    {
        return $this->llmStatsCommands ??= new LlmStatsCommands($this);
    }

    public function llmbudget(): LlmBudgetCommands
    {
        return $this->llmBudgetCommands ??= new LlmBudgetCommands($this);
    }

    public function prompt(): PromptRegistryCommands
    {
        return $this->promptCommands ??= new PromptRegistryCommands($this);
    }

    public function embedDedup(): EmbedDedupCommands
    {
        return $this->embedDedupCommands ??= new EmbedDedupCommands($this);
    }

    public function amem(): AmemCommands
    {
        return $this->amemCommands ??= new AmemCommands($this);
    }

    public function rag(): RagCommands
    {
        return $this->ragCommands ??= new RagCommands($this);
    }

    public function ratelimit(): RatelimitCommands
    {
        return $this->ratelimitCommands ??= new RatelimitCommands($this);
    }

    public function ts(): TsCommands
    {
        return $this->tsCommands ??= new TsCommands($this);
    }

    public function feature(): FeatureCommands
    {
        return $this->featureCommands ??= new FeatureCommands($this);
    }

    public function observe(): ObserveCommands
    {
        return $this->observeCommands ??= new ObserveCommands($this);
    }

    public function alerts(): AlertsCommands
    {
        return $this->alertsCommands ??= new AlertsCommands($this);
    }
}
