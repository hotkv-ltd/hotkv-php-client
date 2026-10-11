<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Cluster;

use HotKV\Client;
use HotKV\ClientOptions;
use HotKV\CommandDispatcher;
use HotKV\Commands\BitmapCommands;
use HotKV\Commands\GeoCommands;
use HotKV\Commands\HashCommands;
use HotKV\Commands\HyperLogLogCommands;
use HotKV\Commands\KeyCommands;
use HotKV\Commands\ListCommands;
use HotKV\Commands\PubSubCommands;
use HotKV\Commands\ScriptingCommands;
use HotKV\Commands\ServerCommands;
use HotKV\Commands\SetCommands;
use HotKV\Commands\SortedSetCommands;
use HotKV\Commands\StreamCommands;
use HotKV\Commands\StringCommands;
use HotKV\Exception\AskException;
use HotKV\Exception\ConnectionException;
use HotKV\Exception\MovedException;
use HotKV\Support\Idempotency;
use HotKV\Support\Reply;

/**
 * A HotKV Cluster client: discovers the slot map with CLUSTER SLOTS (or
 * CLUSTER SHARDS), computes each key's hash slot with CRC16 (honoring
 * "{hash tag}"), routes commands to the node owning that slot, follows
 * MOVED redirects by refreshing the slot map, and follows ASK redirects
 * by sending ASKING then retrying once on the target node.
 *
 * Every typed core command method is available directly
 * (`$cluster->get($key)`, ...), routed automatically by the command's
 * first key argument. Multi-key commands are only safe when every key
 * hashes to the same slot (use a "{tag}" to force that); for a command
 * this heuristic routes wrongly, or to route explicitly, pass a routing
 * key to execute(). Pipelines, transactions, pub/sub and the SCAN family
 * are node-local concepts and are not exposed here: use nodeClient() to
 * reach a specific node directly for those.
 */
final class ClusterClient implements CommandDispatcher
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
    use PubSubCommands;

    /** @var array<string, Client> "host:port" => node client */
    private array $clients = [];

    /** @var array<int, array{host: string, port: int, replicas: list<array{host: string, port: int}>}> slot => owning node */
    private array $slotMap = [];

    private int $replicaCursor = 0;

    /** @param list<string> $seeds "host:port" strings, or full "hotkv://" URLs */
    public function __construct(
        private readonly array $seeds,
        private readonly ClientOptions $baseOptions = new ClientOptions(),
        private readonly bool $readFromReplicas = false,
        private readonly int $maxRedirects = 5,
    ) {
        if ($seeds === []) {
            throw new \InvalidArgumentException('HotKV\Cluster\ClusterClient needs at least one seed node.');
        }
        foreach ($seeds as $seed) {
            self::parseSeed($seed); // validates eagerly, so a malformed seed fails fast at construction
        }
    }

    /** Fetches (or refetches) the slot map from the first reachable seed node. */
    public function refreshSlots(): void
    {
        $tried = [];
        $lastError = null;
        foreach ($this->seeds as $seed) {
            [$host, $port] = self::parseSeed($seed);
            $tried[] = "{$host}:{$port}";
            try {
                $client = $this->nodeClient($host, $port);
                $slots = $client->execute(['CLUSTER', 'SLOTS']);
                if (is_array($slots) && $slots !== []) {
                    $this->applySlots($slots);

                    return;
                }
                $shards = $client->execute(['CLUSTER', 'SHARDS']);
                if (is_array($shards) && $shards !== []) {
                    $this->applyShards($shards);

                    return;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw new ConnectionException(
            'Failed to load the HotKV cluster slot map from any seed (' . implode(', ', $tried) . ').'
            . ($lastError !== null ? ' Last error: ' . $lastError->getMessage() : ''),
        );
    }

    /** Sends any command; the escape hatch for commands with no typed method. */
    public function command(string|int|float|bool ...$args): mixed
    {
        return $this->execute(array_values($args));
    }

    /**
     * Routes and sends one command. The routing key defaults to $args[1]
     * (correct for the great majority of single-key commands); pass
     * $routingKey to override it.
     *
     * @param list<string|int|float|bool> $args
     */
    public function execute(array $args, ?string $routingKey = null): mixed
    {
        if ($this->slotMap === []) {
            $this->refreshSlots();
        }
        $key = $routingKey ?? (array_key_exists(1, $args) ? (string) $args[1] : null);
        if ($key === null) {
            return $this->executeOnAnyKnownNode($args);
        }

        return $this->executeRouted($key, $args, Idempotency::isReadOnly($args), 0);
    }

    public function dispatch(array $args): mixed
    {
        return $this->execute($args);
    }

    /** Reaches one specific node directly, bypassing routing (for pipelines, transactions or pub/sub on that node). */
    public function nodeClient(string $host, int $port): Client
    {
        $id = "{$host}:{$port}";
        if (!isset($this->clients[$id])) {
            $this->clients[$id] = new Client(new ClientOptions(
                host: $host,
                port: $port,
                username: $this->baseOptions->username,
                password: $this->baseOptions->password,
                database: $this->baseOptions->database,
                clientName: $this->baseOptions->clientName,
                connectTimeout: $this->baseOptions->connectTimeout,
                commandTimeout: $this->baseOptions->commandTimeout,
                protocol: $this->baseOptions->protocol,
                persistent: $this->baseOptions->persistent,
                retryReadsOnce: $this->baseOptions->retryReadsOnce,
                tls: $this->baseOptions->tls,
                tlsCaFile: $this->baseOptions->tlsCaFile,
                tlsCertFile: $this->baseOptions->tlsCertFile,
                tlsKeyFile: $this->baseOptions->tlsKeyFile,
                tlsServerName: $this->baseOptions->tlsServerName,
                tlsInsecureSkipVerify: $this->baseOptions->tlsInsecureSkipVerify,
            ));
        }

        return $this->clients[$id];
    }

    public function close(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
    }

    private function executeRouted(string $key, array $args, bool $readOnly, int $redirects): mixed
    {
        if ($redirects > $this->maxRedirects) {
            throw new ConnectionException('Too many HotKV cluster redirects for one command.');
        }
        $slot = Crc16::keySlot($key);
        if (!isset($this->slotMap[$slot])) {
            $this->refreshSlots();
        }
        $node = $this->slotMap[$slot] ?? throw new ConnectionException("No HotKV cluster node covers hash slot {$slot}.");

        if ($this->readFromReplicas && $readOnly && $node['replicas'] !== []) {
            $replica = $node['replicas'][$this->replicaCursor % count($node['replicas'])];
            $this->replicaCursor++;
            try {
                $client = $this->nodeClient($replica['host'], $replica['port']);
                $client->execute(['READONLY']);

                return $client->execute($args);
            } catch (MovedException|AskException) {
                $this->refreshSlots();

                return $this->executeRouted($key, $args, $readOnly, $redirects + 1);
            } catch (ConnectionException) {
                // Replica unreachable: fall through and use the master.
            }
        }

        $client = $this->nodeClient($node['host'], $node['port']);
        try {
            return $client->execute($args);
        } catch (AskException $e) {
            $askClient = $this->nodeClient($e->getHost(), $e->getPort());
            $askClient->execute(['ASKING']);

            return $askClient->execute($args);
        } catch (MovedException) {
            $this->refreshSlots();

            return $this->executeRouted($key, $args, $readOnly, $redirects + 1);
        }
    }

    private function executeOnAnyKnownNode(array $args): mixed
    {
        if ($this->slotMap !== []) {
            $node = $this->slotMap[array_key_first($this->slotMap)];

            return $this->nodeClient($node['host'], $node['port'])->execute($args);
        }
        [$host, $port] = self::parseSeed($this->seeds[0]);

        return $this->nodeClient($host, $port)->execute($args);
    }

    /** @param list<mixed> $slots CLUSTER SLOTS reply */
    private function applySlots(array $slots): void
    {
        $map = [];
        foreach ($slots as $range) {
            $master = $range[2];
            $replicas = [];
            for ($i = 3, $n = count($range); $i < $n; $i++) {
                $replicas[] = ['host' => (string) $range[$i][0], 'port' => (int) $range[$i][1]];
            }
            $node = ['host' => (string) $master[0], 'port' => (int) $master[1], 'replicas' => $replicas];
            for ($slot = (int) $range[0], $end = (int) $range[1]; $slot <= $end; $slot++) {
                $map[$slot] = $node;
            }
        }
        $this->slotMap = $map;
    }

    /** @param list<mixed> $shards CLUSTER SHARDS reply */
    private function applyShards(array $shards): void
    {
        $map = [];
        foreach ($shards as $shard) {
            $fields = Reply::toMap($shard);
            $slotsFlat = $fields['slots'] ?? [];
            $master = null;
            $replicas = [];
            foreach ($fields['nodes'] ?? [] as $nodeReply) {
                $n = Reply::toMap($nodeReply);
                $entry = ['host' => (string) ($n['ip'] ?? $n['endpoint'] ?? ''), 'port' => (int) ($n['port'] ?? 0)];
                if (($n['role'] ?? '') === 'master') {
                    $master = $entry;
                } else {
                    $replicas[] = $entry;
                }
            }
            if ($master === null) {
                continue;
            }
            $node = ['host' => $master['host'], 'port' => $master['port'], 'replicas' => $replicas];
            for ($i = 0, $n = count($slotsFlat); $i + 1 < $n; $i += 2) {
                for ($slot = (int) $slotsFlat[$i], $end = (int) $slotsFlat[$i + 1]; $slot <= $end; $slot++) {
                    $map[$slot] = $node;
                }
            }
        }
        $this->slotMap = $map;
    }

    /** @return array{0: string, 1: int} */
    private static function parseSeed(string $seed): array
    {
        if (str_contains($seed, '://')) {
            $opts = ClientOptions::fromUrl($seed);

            return [$opts->host, $opts->port];
        }
        $pos = strrpos($seed, ':');
        if ($pos === false) {
            throw new \InvalidArgumentException("Invalid HotKV cluster seed \"{$seed}\": expected \"host:port\".");
        }

        return [substr($seed, 0, $pos), (int) substr($seed, $pos + 1)];
    }
}
