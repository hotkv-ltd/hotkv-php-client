# HotKV PHP Client

[![CI](https://github.com/hotkv-ltd/hotkv-php-client/actions/workflows/ci.yml/badge.svg)](https://github.com/hotkv-ltd/hotkv-php-client/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE)

A high-performance PHP client for [HotKV](https://hotkv.com), a RESP2/RESP3-compatible
in-memory data store with built-in AI and LLM-serving features (semantic and prompt
caching, agent memory, RAG, feature store, rate limiting, time series and more).

Pure PHP, zero runtime dependencies, PHP 8.1+.

## Install

```bash
composer require hotkv/client
```

## Quick start

```php
use HotKV\Client;

$client = new Client('hotkv://127.0.0.1:6379');

$client->set('greeting', 'hello from HotKV');
echo $client->get('greeting');

$client->hset('user:1', ['name' => 'Ada', 'plan' => 'pro']);
print_r($client->hgetall('user:1'));

$client->close();
```

`Client` also accepts an option array or a `HotKV\ClientOptions` instance:

```php
$client = new Client([
    'host' => '127.0.0.1',
    'port' => 6379,
    'username' => 'default',
    'password' => 'secret',
    'database' => 2,
    'clientName' => 'checkout-service',
    'connectTimeout' => 2.0,
    'commandTimeout' => 5.0,
]);
```

The core data types (strings, keys, lists, sets, hashes, sorted sets, streams,
geo, bitmaps, HyperLogLog, pub/sub, SCAN, and the common server and scripting
commands) have typed methods with PHPDoc types. Many server commands do not
(see [Command coverage](#command-coverage)); every one of them is still
reachable through the generic escape hatch, which sends the arguments as given
and returns the decoded reply:

```php
$client->command('OBJECT', 'ENCODING', 'greeting');
$client->execute(['OBJECT', 'ENCODING', 'greeting']);
$client->command('BF.ADD', 'filter', 'item');   // no typed method for BF.*
```

Arguments are strings, ints, floats or bools. Strings are binary safe. Floats
are written as plain decimal text (see [Floating point arguments](#floating-point-arguments)).

## Connecting

### URLs

```php
new Client('hotkv://127.0.0.1:6379');                 // plain TCP
new Client('hotkvs://127.0.0.1:6380');                // TLS
new Client('redis://127.0.0.1:6379');                 // redis:// accepted for drop-in migration
new Client('hotkv://user:pass@127.0.0.1:6379/2');      // auth + database index
new Client('unix:///var/run/hotkv.sock?db=1');         // Unix domain socket
```

### TLS

```php
new Client([
    'host' => 'hotkv.internal',
    'tls' => true,
    'tlsCaFile' => '/etc/ssl/certs/hotkv-ca.pem',
    'tlsCertFile' => '/etc/hotkv/client.pem',
    'tlsKeyFile' => '/etc/hotkv/client.key',
    'tlsServerName' => 'hotkv.internal',
    // insecure, tests only:
    'tlsInsecureSkipVerify' => false,
]);
```

### Persistent connections and pooling

PHP normally runs one request at a time with no real threads, so `Client`
keeps a single connection by default and offers a `persistent` flag
(`stream_socket_client` with `STREAM_CLIENT_PERSISTENT`) so that connection
survives across requests on the same PHP-FPM worker, instead of reconnecting
every time:

```php
new Client(['host' => 'hotkv.internal', 'persistent' => true]);
```

For Fiber based or embedded uses that genuinely need more than one connection
open at once, set `poolSize` (bounded, with an idle timeout and a PING health
check on borrow after being idle):

```php
new Client(['host' => 'hotkv.internal', 'poolSize' => 10, 'poolIdleTimeout' => 30.0]);
```

### Retries

A read-only command (`GET`, `HGETALL`, `PCACHE.GET`, ...) that fails because
the connection dropped is retried once automatically, after reconnecting.
Writes are never retried blindly, since the client cannot tell whether the
original write reached the server before the connection dropped; set
`retryReadsOnce: false` to disable even the read retry.

## Pipelines

Queue many commands and send them in a single round trip. Results come back
in order; a command the server rejected appears as a `HotKV\Exception\ServerException`
in its slot instead of throwing, so one bad command never discards the rest
of the batch:

```php
use HotKV\Pipeline;

$results = $client->pipeline(function (Pipeline $p) {
    $p->set('a', '1');
    $p->incr('counter');
    $p->get('a');
});
// ['OK', 1, '1']
```

## Transactions

```php
use HotKV\Transaction;

$client->watch('balance');
$current = (int) $client->get('balance');

$result = $client->transaction(function (Transaction $tx) use ($current) {
    $tx->set('balance', (string) ($current - 10));
    $tx->incr('withdrawals');
});
// null if another client changed "balance" between watch() and the transaction
// (EXEC aborted), otherwise the queued commands' results in order.
```

## Pub/Sub

```php
use HotKV\PubSub\Message;
use HotKV\PubSub\Subscriber;

$client->subscribe(['orders'], function (Message $message, Subscriber $subscriber) {
    echo "{$message->channel}: {$message->payload}\n";
    if ($message->payload === 'stop') {
        $subscriber->stop(); // the only way to end the blocking loop
    }
});
```

`psubscribe()` (pattern) and `ssubscribe()` (shard channels) work the same
way. `publish()`, `spublish()` and the `PUBSUB` introspection commands are
plain typed methods on `Client`.

## Cluster

```php
use HotKV\Cluster\ClusterClient;

$cluster = new ClusterClient(['10.0.0.1:6379', '10.0.0.2:6379', '10.0.0.3:6379']);
$cluster->set('user:42', 'Ada');   // routed by CRC16("user:42") to the owning node
$cluster->get('user:42');
```

`ClusterClient` discovers the slot map with `CLUSTER SLOTS` (falling back to
`CLUSTER SHARDS`), hashes keys with CRC16 (honoring `{hash tag}`), follows
`MOVED` by refreshing its slot map and `ASK` by sending `ASKING` then retrying
once on the target node, and can read from replicas:

```php
new ClusterClient($seeds, readFromReplicas: true);
```

Pipelines, transactions, pub/sub and `SCAN` are node-local concepts and stay
on `Client`; reach a specific node directly with `$cluster->nodeClient($host, $port)`.

## HotKV exclusive commands

Most HotKV specific and AI command families have an accessor on `Client`,
grouped the way the server groups them. The AI families need a server license
that covers them; on an unlicensed server they answer `NOLICENSE` (a
`NotLicensedException`). TAG, VSIM (the index commands), GCRA and HOTKEYS work
without one.

```php
$client->set('doc:1', $documentText);              // TAG.SET needs the key to exist
$client->tag()->set('doc:1', ['pdf', 'invoice']);
$client->tag()->members('invoice');               // every key tagged "invoice"

$client->vsim()->createIndex('docs', 384);
$client->vsim()->set('docs', 'doc:1', $embedding);
$client->vsim()->search('docs', 5, $queryVector);

$client->rag()->create('kb', 384);
$client->rag()->ingest('kb', 'doc:1', $text, vectors: $chunkVectors);
$client->rag()->search('kb', 'how do I reset my password?', vector: $queryVector);

$client->ratelimit()->create('signups', limit: 100, windowMs: 60_000);
$client->ratelimit()->check('signups', $ipAddress);

$client->ts()->create('cpu:host1');
$client->ts()->add('cpu:host1', '*', 42.5);
```

Other accessors: `hotkeys()`, `gcra()`, `scache()`, `pcache()`, `toolCache()`,
`ncache()`, `guard()`, `rerank()`, `llmstats()`, `llmbudget()`, `prompt()`,
`embedDedup()`, `amem()`, `feature()`, `observe()`, `alerts()`.
Nanosecond resolution TTLs are plain `Client` methods: `nexpire()`, `nttl()`,
`nexpireat()`.

Not wrapped with typed methods (use `command()`): TENANT.*, IAM.*, AUDIT.*,
ENCRYPTION.*, REPLICATION.*, the `*.SYNC` replication helpers, and
FEATURE.SETAT (an internal command the server uses to replicate FEATURE.SET).

## Command coverage

Typed methods were checked against server v0.3.0. Any command without a typed
method goes through `command()` / `execute()`. In short:

- Typed: strings, keys and expiry, lists (including LMPOP/BLMPOP and the
  blocking pops), sets, hashes (including HEXPIRE/HTTL, HGETDEL, HGETEX,
  HSETEX), sorted sets (including ZREMRANGEBY* and ZMPOP/BZMPOP), streams,
  geo, bitmaps, HyperLogLog, pub/sub, SCAN/SSCAN/HSCAN/ZSCAN as generators,
  EVAL/EVALSHA (and the `_RO` forms), SORT/SORT_RO, LCS, MSETEX, DELEX,
  DIGEST, SLOWLOG, MEMORY USAGE, ROLE, WAIT, plus the families listed under
  [HotKV exclusive commands](#hotkv-exclusive-commands).
- Not typed, but reachable with `command()` / `execute()`: CLIENT (except
  SETNAME, GETNAME and ID, so CLIENT TRACKING, CACHING, GETREDIR,
  TRACKINGINFO, LIST, INFO, KILL and PAUSE), ACL, CLUSTER administration,
  FUNCTION and FCALL, the probabilistic structures (BF.*, CMS.*, TOPK.*),
  LATENCY, MODULE, DEBUG, replication and persistence control (REPLICAOF,
  FAILOVER, SAVE, BGSAVE, BGREWRITEAOF, SHUTDOWN), HIMPORT, XCFGSET, SFLUSH,
  CLUSTERSCAN and TRIMSLOTS, and the enterprise families named above.
- `ClusterClient` routes any command by its first key argument (see Cluster);
  it is exercised only by unit tests with fake nodes, not against a live
  cluster.

## Floating point arguments

PHP renders small and large floats in exponent form (`1.0E-5`), and its float
to string conversions depend on the `precision` and `serialize_precision`
settings and, for `sprintf('%f')`, on the process locale. None of that reaches
the wire: every float the SDK sends goes through one formatter,
`HotKV\Support\Numbers`, which writes the shortest plain decimal that round
trips (`0.00001`, and `1.0` for a whole number, never an exponent).

- NaN is always rejected with an `\InvalidArgumentException`; infinity is
  rejected where the server refuses it (GEO, INCRBYFLOAT, vectors, costs,
  timeouts) and sent as `inf` / `-inf` where the server accepts it (ZADD scores,
  ZINCRBY).
- USD amounts for `llmbudget()->set()` and `llmstats()->priceSet()` use the
  server's fixed point money format: digits with at most 6 decimals. A float
  is rounded to the nearest micro-dollar, and a positive amount smaller than
  one micro-dollar is refused instead of rounding to 0, which the server reads
  as "no limit". Pass a decimal string to control the text exactly.

## Push messages and client side caching

With RESP3 (the default) the server can send out of band push frames between
replies, for example the `invalidate` messages after
`$client->command('CLIENT', 'TRACKING', 'ON')`. They are never returned as a
command's reply. Register a callback to receive them, or leave it unset to
drop them:

```php
$client->onPush(function (\HotKV\Protocol\PushMessage $push) {
    [$kind, $keys] = $push->elements;   // ['invalidate', ['user:1']]
});
$client->command('CLIENT', 'TRACKING', 'ON');
```

## AI caching

`HotKV\AI\PromptCache` builds a canonical, cross-language deterministic cache
key from the request and only calls the LLM on a miss:

```php
use HotKV\AI\PromptCache;
use HotKV\AI\PromptCallResult;

$cache = new PromptCache($client, namespace: 'chat-completions', ttlSeconds: 3600);
$cache->createNamespace(costPerHit: 0.002);

$messages = [
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user', 'content' => $userMessage],
];
$params = ['temperature' => '0.7', 'max_tokens' => '500']; // format numbers yourself

$result = $cache->getOrCall('openai', 'gpt-6-sol', '', $messages, $params, function () use ($messages) {
    $start = microtime(true);
    // Call your LLM provider's client here. This closure only runs on a
    // cache miss.
    $response = $yourLlmClient->chat($messages);

    return new PromptCallResult(
        response: $response->text,
        inputTokens: $response->usage->promptTokens,
        outputTokens: $response->usage->completionTokens,
        costUsd: $response->usage->costUsd,
        latencyMs: (int) ((microtime(true) - $start) * 1000),
    );
});

echo $result->response;
echo $result->hit ? 'served from cache' : 'called the LLM';
```

`HotKV\AI\SemanticCache` does the same for a caller-supplied embedding vector,
looking up the nearest cached answer above a similarity threshold instead of
an exact key match; it never computes embeddings itself, so it works with any
embedding provider:

```php
use HotKV\AI\SemanticCache;

$cache = new SemanticCache($client, namespace: 'faq-answers', ttlSeconds: 86400);
$cache->createNamespace(dims: 1536, threshold: 0.92);

$result = $cache->getOrCall($question, $questionEmbedding, function () use ($question) {
    return $yourLlmClient->answer($question); // only runs on a miss
});
```

Both helpers are provider neutral: they never depend on any particular LLM
or embeddings SDK.

**Recording usage so hits report savings**: the closure passed to
`getOrCall()` may return either just the response (a `PromptCallResult`
for `PromptCache`, a plain string or `SemanticCallResult` for
`SemanticCache`), or one carrying a `HotKV\TokenUsage` (the original
call's token usage). When it does, that usage is stored with the cache
entry, so a later hit attributes its tokens, and once the model is
priced, their exact cost, to `stats()`'s `usage_saved_*` counters:

```php
use HotKV\TokenUsage;

$result = $cache->getOrCall('anthropic', 'claude-sonnet-5', '', $messages, [], function () use ($messages) {
    $response = $yourLlmClient->chat($messages);

    return new PromptCallResult(
        response: $response->text,
        usage: new TokenUsage(
            model: 'claude-sonnet-5',
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
        ),
    );
});

print_r($cache->stats()); // usage_saved_requests, usage_saved_input_tokens, ..., usage_saved_cost_usd
```

## LLM cost tracking

`$client->llmstats()` and `$client->llmbudget()` track token usage and cost
independently of caching, e.g. across every call your application makes to
a given model:

```php
$client->llmstats()->bucket('chat');
$client->llmstats()->record('chat', 100, 20, 0, 0, 120, model: 'claude-sonnet-5'); // latency in whole ms
// cost_usd (the 5th argument, 0 above) is ignored and priced by the server when model: is given
print_r($client->llmstats()->get('chat')); // requests, cost_usd, cache_write_5m_tokens, ...

// Custom pricing for a model not in HotKV's built-in price table
$client->llmstats()->priceSet('my-fine-tuned-model', 2, 8); // USD per 1,000,000 input/output tokens
$price = $client->llmstats()->priceGet('my-fine-tuned-model');

$client->llmbudget()->set('team-alpha', 1_000_000, 50.0, 86_400);
$allowed = $client->llmbudget()->consume(
    'team-alpha',
    usage: new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20),
);
```

For OpenAI style responses, whose `prompt_tokens` already includes cache
hits, pass `prompt_tokens - cached_tokens` as `record()`'s `$inputTokens`
argument (`$cachedTokens` is always cache-READ tokens, unlike Anthropic,
which reports it separately).

## Error handling

Every failure is a `HotKV\Exception\HotKVException`:

```php
use HotKV\Exception\ConnectionException;
use HotKV\Exception\NotLicensedException;
use HotKV\Exception\ServerException;
use HotKV\Exception\TimeoutException;
use HotKV\Exception\WrongTypeException;

try {
    $client->pcache()->get('ns', $key);
} catch (NotLicensedException $e) {
    // the server's current license does not cover this feature
} catch (WrongTypeException $e) {
    // the key holds a different type
} catch (ServerException $e) {
    // any other "-ERR ..." reply; $e->getPrefix() is the error's first token
} catch (TimeoutException $e) {
    // connect/read/write did not complete in time
} catch (ConnectionException $e) {
    // the socket could not be opened, or dropped
}
```

`MovedException` and `AskException` (both `ServerException` subtypes) carry
the redirect's hash slot and target `host`/`port`; `ClusterClient` handles
them for you.

## Testing

```bash
composer install
vendor/bin/phpunit --testsuite unit          # no server needed
HOTKV_TEST_URL=hotkv://127.0.0.1:6399 vendor/bin/phpunit --testsuite integration
vendor/bin/phpunit                           # both
```

Integration tests need a running HotKV server, which is not downloadable
publicly, so CI runs only the unit suite. Environment variables:

- `HOTKV_TEST_URL`: the server to test against. Without it every integration
  test is skipped.
- `HOTKV_TEST_ALLOW_FLUSHALL=1`: also run the FLUSHALL test, which wipes every
  database. Use it only against a throwaway server.
- `HOTKV_REMOTE_HOST`, `HOTKV_REMOTE_PORT`, `HOTKV_REMOTE_PASSWORD`,
  `HOTKV_REMOTE_CA`: run `tests/Integration/RemoteTest.php` against a TLS and
  password protected server over the network. Everything it creates carries a
  `remote-php-<random>` prefix and is removed afterwards; it sends no
  FLUSHALL, FLUSHDB or CONFIG SET. Keep the password in the environment, never
  in a file in the repository.

Integration tests for the licensed families (AI caches, agent memory, RAG,
rate limiting, time series and so on) are skipped automatically (not failed),
with a "not licensed: ..." reason, when the server's license does not cover
them. Run against a licensed server, nothing is skipped except the FLUSHALL
test unless you opt in. Every integration test uses a run-unique key prefix
and cleans up after itself.

## Contributing

Bug reports and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Support

- Bugs and feature requests: [GitHub issues](https://github.com/hotkv-ltd/hotkv-php-client/issues)
- Questions and commercial support: support@hotkv.com
- Security vulnerabilities: see [SECURITY.md](SECURITY.md)

## License

Licensed under the Apache License, Version 2.0: see [LICENSE](LICENSE) and [NOTICE](NOTICE).

## Trademarks and affiliation

"HotKV" is a trademark of HotKV Ltd; the license does not grant rights to use it.
The HotKV server is a separate commercial product, and its enterprise commands need a HotKV license.

HotKV is an independent product of HotKV Ltd. It speaks the RESP protocol and implements
many Redis commands so that existing tools and client habits carry over, but it is not Redis,
Valkey, Dragonfly or KeyDB, and this SDK is built for and tested against HotKV. HotKV Ltd is not
affiliated with, endorsed by or sponsored by Redis Ltd., the Valkey project, DragonflyDB or KeyDB.
Redis is a registered trademark of Redis Ltd. Valkey, Dragonfly, KeyDB and all other product and
company names are trademarks of their respective owners; they are used here only to describe
protocol and command compatibility.
