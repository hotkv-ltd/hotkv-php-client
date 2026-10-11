<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Tests\Integration;

use HotKV\Client;
use HotKV\ClientOptions;
use HotKV\Exception\ConnectionException;
use HotKV\Exception\HotKVException;
use HotKV\LlmPrice;
use HotKV\PubSub\Message;
use HotKV\PubSub\Subscriber;
use HotKV\TokenUsage;
use PHPUnit\Framework\TestCase;

/**
 * A customer's view: connect over the internet to a TLS + password HotKV
 * server and use the SDK through its public API, including the AI features.
 *
 * Skipped unless HOTKV_REMOTE_HOST is set. Configuration is read only from
 * the environment, never from a file in the repository:
 *
 *   HOTKV_REMOTE_HOST      server host or IP (the certificate must name it)
 *   HOTKV_REMOTE_PORT      TLS port (default 6379)
 *   HOTKV_REMOTE_PASSWORD  the password (never printed by these tests)
 *   HOTKV_REMOTE_CA        path to the CA / certificate file to verify against
 *
 * The server is shared: every key and namespace carries a unique
 * "remote-php-<random>" prefix and is removed afterwards, and nothing global
 * (FLUSHALL, FLUSHDB, CONFIG SET, ACL, SHUTDOWN) is ever sent.
 */
final class RemoteTest extends TestCase
{
    private string $prefix;

    /** @var list<Client> */
    private array $clients = [];

    /** @var list<callable(): void> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        if ((string) getenv('HOTKV_REMOTE_HOST') === '') {
            self::markTestSkipped('HOTKV_REMOTE_HOST is not set; skipping the remote (internet) tests.');
        }
        self::assertNotSame('', (string) getenv('HOTKV_REMOTE_PASSWORD'), 'HOTKV_REMOTE_PASSWORD must be set');
        self::assertNotSame('', (string) getenv('HOTKV_REMOTE_CA'), 'HOTKV_REMOTE_CA must be set');
        $this->prefix = 'remote-php-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $fn) {
            try {
                $fn();
            } catch (\Throwable) {
                // Best effort: a test that already removed its own data.
            }
        }
        $this->cleanup = [];
        foreach ($this->clients as $c) {
            $c->close();
        }
        $this->clients = [];
    }

    // -- helpers --------------------------------------------------------

    /** @param array<string, mixed> $override */
    private function options(array $override = []): array
    {
        return array_merge([
            'host' => (string) getenv('HOTKV_REMOTE_HOST'),
            'port' => (int) (getenv('HOTKV_REMOTE_PORT') ?: 6379),
            'password' => (string) getenv('HOTKV_REMOTE_PASSWORD'),
            'tls' => true,
            'tlsCaFile' => (string) getenv('HOTKV_REMOTE_CA'),
            'connectTimeout' => 10.0,
            'commandTimeout' => 20.0,
        ], $override);
    }

    /** @param array<string, mixed> $override */
    private function client(array $override = []): Client
    {
        return $this->clients[] = new Client($this->options($override));
    }

    private function key(string $suffix = ''): string
    {
        $k = $this->prefix . ':' . ($suffix !== '' ? $suffix : bin2hex(random_bytes(3)));
        $this->cleanup[] = function () use ($k): void {
            $this->client()->del([$k]);
        };

        return $k;
    }

    private function nsName(string $suffix): string
    {
        return $this->prefix . '-' . $suffix;
    }

    /** Runs a child PHP process with the inherited environment, returns [process, pipes, script path]. */
    private function spawn(string $body): array
    {
        $script = '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$c = new HotKV\Client(["host" => getenv("HOTKV_REMOTE_HOST"), "port" => (int) getenv("HOTKV_REMOTE_PORT"),'
            . '"password" => getenv("HOTKV_REMOTE_PASSWORD"), "tls" => true, "tlsCaFile" => getenv("HOTKV_REMOTE_CA")]);'
            . $body . '$c->close();';
        $file = tempnam(sys_get_temp_dir(), 'hotkv_remote_');
        file_put_contents($file, $script);
        $proc = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);

        return [$proc, $pipes, $file];
    }

    /** @return array{0: int, 1: string} exit code and stderr */
    private function reap(array $handle): array
    {
        [$proc, $pipes, $file] = $handle;
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        @unlink($file);

        return [$code, $err];
    }

    // -- connecting and authenticating -----------------------------------

    public function testPingEchoAndInfoOverTls(): void
    {
        $c = $this->client();
        self::assertSame('PONG', $c->ping());
        self::assertSame('echo-me', $c->ping('echo-me'));
        self::assertSame('hi', $c->echo('hi'));
        self::assertStringContainsString('redis_version', (string) $c->info('server'));
    }

    public function testUrlFormWithPasswordAndTheOptionsForm(): void
    {
        $url = sprintf(
            'hotkvs://:%s@%s:%d',
            rawurlencode((string) getenv('HOTKV_REMOTE_PASSWORD')),
            getenv('HOTKV_REMOTE_HOST'),
            (int) (getenv('HOTKV_REMOTE_PORT') ?: 6379),
        );
        $byUrl = new Client(ClientOptions::fromUrl($url, ['tlsCaFile' => (string) getenv('HOTKV_REMOTE_CA')]));
        $this->clients[] = $byUrl;
        self::assertSame('PONG', $byUrl->ping());
        $k = $this->key();
        $byUrl->set($k, 'via-url');
        self::assertSame('via-url', $this->client()->get($k));
    }

    public function testResp2AndResp3BothWork(): void
    {
        foreach ([2, 3] as $protocol) {
            $c = $this->client(['protocol' => $protocol]);
            $k = $this->key("proto{$protocol}");
            $c->hset($k, ['a' => '1']);
            self::assertSame(['a' => '1'], $c->hgetall($k), "RESP{$protocol}");
        }
    }

    public function testWrongPasswordFailsCleanly(): void
    {
        $wrong = 'wrong-' . bin2hex(random_bytes(6));
        $c = new Client($this->options(['password' => $wrong]));
        try {
            $c->ping();
            self::fail('a wrong password was accepted');
        } catch (HotKVException $e) {
            self::assertStringNotContainsString($wrong, $e->getMessage());
            self::assertNotSame('', $e->getMessage());
        } finally {
            $c->close();
        }
    }

    public function testMissingPasswordIsRejected(): void
    {
        $c = new Client($this->options(['password' => null]));
        try {
            $c->get($this->prefix . ':none');
            self::fail('a command ran without authenticating');
        } catch (HotKVException $e) {
            self::assertTrue(true);
        } finally {
            $c->close();
        }
    }

    public function testTlsWithoutTheCaFailsVerification(): void
    {
        $c = new Client($this->options(['tlsCaFile' => null, 'connectTimeout' => 5.0]));
        try {
            $c->ping();
            self::fail('a self signed certificate was trusted without its CA');
        } catch (HotKVException $e) {
            self::assertInstanceOf(ConnectionException::class, $e);
        } finally {
            $c->close();
        }
    }

    public function testInsecureSkipVerifyConnectsWithoutTheCa(): void
    {
        $c = $this->client(['tlsCaFile' => null, 'tlsInsecureSkipVerify' => true]);
        self::assertSame('PONG', $c->ping());
    }

    // -- core data types --------------------------------------------------

    public function testStringsHashesListsSetsZsetsAndStreams(): void
    {
        $c = $this->client();

        $s = $this->key('str');
        self::assertSame('OK', $c->set($s, 'hello'));
        self::assertSame('hello', $c->get($s));
        self::assertSame(11, $c->append($s, ' world'));
        self::assertSame('hello world', $c->get($s));
        $n = $this->key('n');
        self::assertSame(5, $c->incrby($n, 5));
        self::assertSame(6, $c->incr($n));

        $h = $this->key('hash');
        self::assertSame(2, $c->hset($h, ['f1' => 'v1', 'f2' => 'v2']));
        self::assertSame('v2', $c->hget($h, 'f2'));
        self::assertSame(['f1' => 'v1', 'f2' => 'v2'], $c->hgetall($h));
        self::assertSame(1, $c->hdel($h, ['f1']));

        $l = $this->key('list');
        self::assertSame(3, $c->rpush($l, ['a', 'b', 'c']));
        self::assertSame(['a', 'b', 'c'], $c->lrange($l, 0, -1));
        self::assertSame('a', $c->lpop($l));

        $set = $this->key('set');
        self::assertSame(3, $c->sadd($set, ['x', 'y', 'z']));
        self::assertEqualsCanonicalizing(['x', 'y', 'z'], $c->smembers($set));
        self::assertSame(1, $c->sismember($set, 'x'));

        $z = $this->key('zset');
        self::assertSame(3, $c->zadd($z, ['a' => 1.5, 'b' => 2.0, 'c' => 0.00001]));
        self::assertSame(['c', 'a', 'b'], $c->zrange($z, '0', '-1'));
        self::assertSame(1, $c->zremrangebyscore($z, '-inf', '1'));
        self::assertSame(2, $c->zcard($z));

        $x = $this->key('stream');
        $id = $c->xadd($x, ['field' => 'value']);
        self::assertMatchesRegularExpression('/^\d+-\d+$/', $id);
        self::assertSame(1, $c->xlen($x));
        $range = $c->xrange($x);
        self::assertSame($id, $range[0][0]);
    }

    public function testExpiry(): void
    {
        $c = $this->client();
        $k = $this->key();
        $c->set($k, 'v', ex: 100);
        self::assertGreaterThan(0, $c->ttl($k));
        self::assertLessThanOrEqual(100, $c->ttl($k));
        self::assertSame(1, $c->persist($k));
        self::assertSame(-1, $c->ttl($k));
        self::assertSame(1, $c->pexpire($k, 150));
        usleep(400_000);
        self::assertNull($c->get($k));
        self::assertSame(-2, $c->ttl($k));
    }

    public function testPipelineAndTransaction(): void
    {
        $c = $this->client();
        $a = $this->key('a');
        $counter = $this->key('ctr');
        $results = $c->pipeline(function ($p) use ($a, $counter) {
            $p->set($a, '1');
            $p->incr($counter);
            $p->get($a);
            $p->lpush($a, ['wrong-type']);
        });
        self::assertSame('OK', $results[0]);
        self::assertSame(1, $results[1]);
        self::assertSame('1', $results[2]);
        self::assertInstanceOf(\HotKV\Exception\WrongTypeException::class, $results[3]);

        $c->watch($counter);
        $tx = $c->transaction(function ($t) use ($counter, $a) {
            $t->incr($counter);
            $t->get($a);
        });
        self::assertSame([2, '1'], $tx);

        // A watched key changed by another connection aborts the transaction.
        $c->watch($counter);
        $this->client()->incr($counter);
        $aborted = $c->transaction(function ($t) use ($counter) {
            $t->incr($counter);
        });
        self::assertNull($aborted);
    }

    public function testPubSubAcrossSeparateConnections(): void
    {
        $channel = $this->nsName('chan');
        $handle = $this->spawn(sprintf('usleep(700000); $c->publish(%s, "hello-remote");', var_export($channel, true)));
        $got = null;
        $this->client(['commandTimeout' => 15.0])->subscribe([$channel], function (Message $m, Subscriber $s) use (&$got): void {
            $got = [$m->channel, $m->payload];
            $s->stop();
        });
        [$code, $err] = $this->reap($handle);
        self::assertSame(0, $code, $err);
        self::assertSame([$channel, 'hello-remote'], $got);
    }

    public function testOneMegabyteBinaryValueRoundTrips(): void
    {
        $c = $this->client();
        $k = $this->key('big');
        $value = random_bytes(1024 * 1024);
        self::assertSame('OK', $c->set($k, $value));
        self::assertSame(1024 * 1024, $c->strlen($k));
        $back = $c->get($k);
        self::assertSame(strlen($value), strlen($back));
        self::assertTrue(hash_equals(hash('sha256', $value), hash('sha256', $back)));
    }

    public function testReadsReconnectAfterTheServerClosesTheConnection(): void
    {
        $c = $this->client();
        $k = $this->key();
        $c->set($k, 'still-here');
        $id = $c->clientId();
        // Kill only this client's own connection, from another connection.
        self::assertSame(1, $this->client()->command('CLIENT', 'KILL', 'ID', $id));
        // A read is retried once on a fresh connection.
        self::assertSame('still-here', $c->get($k));
        self::assertNotSame($id, $c->clientId());

        // After the next drop the client reconnects as well, even if the
        // command that hit the dead socket is not retried (it is a write).
        $id = $c->clientId();
        self::assertSame(1, $this->client()->command('CLIENT', 'KILL', 'ID', $id));
        try {
            $c->set($k, 'after-kill');
        } catch (ConnectionException) {
            // Writes are never retried blindly.
        }
        $c->set($k, 'after-reconnect');
        self::assertSame('after-reconnect', $c->get($k));
    }

    public function testConcurrentUseFromSeveralProcessesAndAPool(): void
    {
        $counter = $this->key('concurrent');
        $handles = [];
        for ($i = 0; $i < 4; $i++) {
            $handles[] = $this->spawn(sprintf('for ($i = 0; $i < 100; $i++) { $c->incr(%s); }', var_export($counter, true)));
        }
        foreach ($handles as $h) {
            [$code, $err] = $this->reap($h);
            self::assertSame(0, $code, $err);
        }
        $pooled = $this->client(['poolSize' => 4]);
        self::assertSame('400', $pooled->get($counter));
        for ($i = 0; $i < 20; $i++) {
            $pooled->incr($counter);
        }
        self::assertSame('420', $pooled->get($counter));
    }

    // -- AI features through the typed API ---------------------------------

    public function testSemanticCache(): void
    {
        $c = $this->client();
        $ns = $this->nsName('scache');
        $this->cleanup[] = fn () => $this->client()->scache()->drop($ns);
        self::assertSame('OK', $c->scache()->create($ns, 3, threshold: 0.9));
        self::assertSame('OK', $c->scache()->set($ns, 'what is hotkv', 'a fast data store', [1.0, 0.0, 0.0]));
        $hit = $c->scache()->get($ns, [0.99, 0.05, 0.0]);
        self::assertNotNull($hit);
        self::assertSame('a fast data store', $hit['response']);
        self::assertNull($c->scache()->get($ns, [0.0, 0.0, 1.0]));
        $stats = $c->scache()->stats($ns);
        self::assertSame(1, $stats['entries']);
        self::assertGreaterThanOrEqual(1, $stats['hits']);
        self::assertSame('OK', $c->scache()->threshold($ns, 0.00001));
        self::assertSame(1, $c->scache()->drop($ns));
    }

    public function testPromptCacheAndKeyedCaches(): void
    {
        $c = $this->client();
        $ns = $this->nsName('pcache');
        $this->cleanup[] = fn () => $this->client()->pcache()->drop($ns);
        self::assertSame('OK', $c->pcache()->create($ns, maxEntries: 100, costPerHit: 0.00001));
        $usage = new TokenUsage(model: 'claude-sonnet-5', inputTokens: 100, outputTokens: 20);
        self::assertSame('OK', $c->pcache()->set($ns, 'prompt', 'response', usage: $usage));
        self::assertSame('response', $c->pcache()->get($ns, 'prompt'));
        self::assertNull($c->pcache()->get($ns, 'other'));
        self::assertGreaterThanOrEqual(1, $c->pcache()->stats($ns)['usage_saved_requests']);
        self::assertSame(1, $c->pcache()->drop($ns));

        foreach (['toolCache', 'ncache', 'guard', 'rerank'] as $accessor) {
            $cache = $c->{$accessor}();
            $kns = $this->nsName($accessor);
            $this->cleanup[] = fn () => $this->client()->{$accessor}()->drop($kns);
            self::assertSame('OK', $cache->create($kns, maxEntries: 50, ttl: 0));
            self::assertSame('OK', $cache->set($kns, 'k', 'v', usage: $usage));
            self::assertSame('v', $cache->get($kns, 'k'), $accessor);
            self::assertNull($cache->get($kns, 'absent'));
            self::assertSame(1, $cache->stats($kns)['entries']);
            self::assertContains($kns, $cache->list());
            self::assertSame(1, $cache->del($kns, 'k'));
            self::assertSame(1, $cache->drop($kns));
        }
    }

    public function testLlmBudgetWithFloatLimitsAndLlmStats(): void
    {
        $c = $this->client();
        $name = $this->nsName('budget');
        $this->cleanup[] = fn () => $this->client()->llmbudget()->drop($name);
        foreach ([[0.00001, '0.000010'], [12.5, '12.500000'], [50.0, '50.000000'], [0.000012, '0.000012']] as [$limit, $shown]) {
            self::assertSame('OK', $c->llmbudget()->set($name, 1_000_000, $limit, 3600), "limit {$limit}");
            self::assertSame($shown, $c->llmbudget()->status($name)['cost_limit_usd']);
        }
        self::assertSame('OK', $c->llmbudget()->set($name, 1000, 1.0, 3600));
        self::assertSame(1, $c->llmbudget()->consume($name, 100, 0.00001));
        self::assertSame(0, $c->llmbudget()->consume($name, 10_000, 10.0));
        self::assertSame(1, $c->llmbudget()->consume($name, usage: new TokenUsage(model: 'claude-sonnet-5', inputTokens: 10, outputTokens: 5)));
        self::assertSame(1, $c->llmbudget()->drop($name));

        $bucket = $this->nsName('stats');
        $model = $this->nsName('model');
        $this->cleanup[] = fn () => $this->client()->llmstats()->drop($bucket);
        $this->cleanup[] = fn () => $this->client()->llmstats()->priceDel($model);
        self::assertSame('OK', $c->llmstats()->bucket($bucket));
        self::assertSame('OK', $c->llmstats()->record($bucket, 100, 50, 0, 0.00002, 250));
        self::assertSame('OK', $c->llmstats()->priceSet($model, 0.00001, 15.0));
        self::assertSame('OK', $c->llmstats()->record($bucket, 1000, 1000, 0, 0, 10, model: $model));
        $stats = $c->llmstats()->get($bucket);
        self::assertSame(2, $stats['requests']);
        self::assertSame(1100, $stats['input_tokens']);
        $price = $c->llmstats()->priceGet($model);
        self::assertInstanceOf(LlmPrice::class, $price);
        self::assertEqualsWithDelta(0.00001, $price->inputUsdPerMtok, 1e-9);
        self::assertEqualsWithDelta(15.0, $price->outputUsdPerMtok, 1e-9);
    }

    public function testPromptRegistryAndEmbedDedup(): void
    {
        $c = $this->client();
        $p = $this->nsName('prompt');
        $this->cleanup[] = fn () => $this->client()->prompt()->drop($p);
        self::assertSame(1, $c->prompt()->put($p, 'You are helpful.'));
        self::assertSame(2, $c->prompt()->put($p, 'You are very helpful.'));
        self::assertSame('You are very helpful.', $c->prompt()->latest($p)[1]);
        self::assertSame([1, 2], $c->prompt()->versions($p));
        self::assertSame(1, $c->prompt()->drop($p));

        $ns = $this->nsName('dedup');
        $this->cleanup[] = fn () => $this->client()->embedDedup()->drop($ns);
        $hash = hash('sha256', 'text');
        self::assertSame(1, $c->embedDedup()->put($ns, $hash, 'model-x', '0.1,0.2'));
        self::assertSame('0.1,0.2', $c->embedDedup()->get($ns, $hash));
        self::assertSame(1, $c->embedDedup()->stats($ns)['entries']);
        self::assertSame(1, $c->embedDedup()->drop($ns));
    }

    public function testAgentMemory(): void
    {
        $c = $this->client();
        $session = $this->nsName('conv');
        $this->cleanup[] = fn () => $this->client()->amem()->convDrop($session);
        self::assertSame('OK', $c->amem()->convCreate($session, window: 10));
        $c->amem()->convAppend($session, 'user', 'hello');
        $c->amem()->convAppend($session, 'assistant', 'hi there');
        $turns = $c->amem()->convGet($session);
        self::assertSame(['user', 'assistant'], array_column($turns, 'role'));
        self::assertSame(1, $c->amem()->convDrop($session));

        $agent = $this->nsName('agent');
        $this->cleanup[] = fn () => $this->client()->amem()->semForget($agent, 'fact');
        self::assertSame('OK', $c->amem()->semCreate($agent, 3));
        $c->amem()->semStore($agent, 'fact', 'the sky is blue', [1.0, 0.0, 0.0]);
        self::assertSame('fact', $c->amem()->semRecall($agent, 1, [1.0, 0.0, 0.0])[0]['key']);
        self::assertSame('semantic', $c->amem()->search($agent, 3, [1.0, 0.0, 0.0])[0]['source']);

        $work = $this->nsName('work');
        $this->cleanup[] = fn () => $this->client()->amem()->workClear($work);
        $c->amem()->workSet($work, 'k', 'v');
        self::assertSame('v', $c->amem()->workGet($work, 'k'));
        self::assertSame(1, $c->amem()->workClear($work));

        $epis = $this->nsName('epis');
        $this->cleanup[] = fn () => $this->client()->amem()->episClear($epis);
        $c->amem()->episLog($epis, 'tool_call', 'called search()');
        self::assertCount(1, $c->amem()->episQuery($epis));
        self::assertSame(1, $c->amem()->episClear($epis));

        $sum = $this->nsName('sum');
        $this->cleanup[] = fn () => $this->client()->amem()->summaryDrop($sum);
        self::assertSame(1, $c->amem()->summarySet($sum, 'first'));
        self::assertSame(2, $c->amem()->summaryAppend($sum, 'second'));
        self::assertSame("first\nsecond", $c->amem()->summaryGet($sum)[0]);
        self::assertSame(1, $c->amem()->summaryDrop($sum));
    }

    public function testRagPipeline(): void
    {
        $c = $this->client();
        $pipeline = $this->nsName('rag');
        $this->cleanup[] = fn () => $this->client()->rag()->drop($pipeline);
        self::assertSame('OK', $c->rag()->create($pipeline, 3, chunkSize: 100, chunkOverlap: 0));
        self::assertSame(1, $c->rag()->ingest($pipeline, 'doc1', 'hotkv is a fast data store', meta: ['source' => 'remote'], vectors: [[1.0, 0.0, 0.0]]));
        $results = $c->rag()->search($pipeline, 'fast data store', k: 3, alpha: 0.5, vector: [1.0, 0.0, 0.0]);
        self::assertSame('doc1', $results[0]['doc_id']);
        self::assertSame('remote', $results[0]['meta']['source']);
        self::assertSame(1, $c->rag()->stats($pipeline)['totalDocs']);
        self::assertSame('OK', $c->rag()->drop($pipeline));
    }

    public function testRateLimitGcraTimeSeriesFeatureStoreAndVectors(): void
    {
        $c = $this->client();

        $rl = $this->nsName('rl');
        $this->cleanup[] = fn () => $this->client()->ratelimit()->drop($rl);
        self::assertSame('OK', $c->ratelimit()->create($rl, 2, 60_000));
        self::assertSame(1, $c->ratelimit()->check($rl, 'u')['allowed']);
        self::assertSame(1, $c->ratelimit()->check($rl, 'u')['allowed']);
        self::assertSame(0, $c->ratelimit()->check($rl, 'u')['allowed']);
        self::assertSame('OK', $c->ratelimit()->drop($rl));

        $gk = $this->nsName('gcra');
        $this->cleanup[] = fn () => $this->client()->gcra()->reset([$gk]);
        self::assertSame(0, $c->gcra()->limit($gk, 60_000, 1)['denied']);
        self::assertSame(1, $c->gcra()->limit($gk, 60_000, 1)['denied']);
        self::assertSame(1, $c->gcra()->reset([$gk]));

        $ts = $this->nsName('ts');
        $this->cleanup[] = fn () => $this->client()->ts()->delete($ts);
        self::assertSame('OK', $c->ts()->create($ts, labels: ['sensor' => 'remote']));
        self::assertSame(1000, $c->ts()->add($ts, 1000, 21.5));
        self::assertSame(2000, $c->ts()->add($ts, 2000, 0.00001));
        $range = $c->ts()->range($ts, '-', '+');
        self::assertCount(2, $range);
        self::assertEqualsWithDelta(0.00001, (float) $range[1][1], 1e-9);
        self::assertSame('OK', $c->ts()->delete($ts));

        $group = $this->nsName('feat');
        $this->cleanup[] = fn () => $this->client()->feature()->drop($group);
        self::assertSame('OK', $c->feature()->create($group, 'remote features', ['age' => 'int64', 'score' => 'double']));
        self::assertSame('OK', $c->feature()->set($group, 'u1', ['age' => 30, 'score' => 0.00001]));
        $got = $c->feature()->get($group, 'u1');
        self::assertEquals(30, $got['features']['age']);
        self::assertEqualsWithDelta(0.00001, (float) $got['features']['score'], 1e-9);
        self::assertSame('OK', $c->feature()->drop($group));

        $index = $this->nsName('vsim');
        $this->cleanup[] = fn () => $this->client()->vsim()->dropIndex($index);
        self::assertSame('OK', $c->vsim()->createIndex($index, 3));
        self::assertSame(1, $c->vsim()->set($index, 'a', [1.0, 0.0, 0.0]));
        self::assertSame(1, $c->vsim()->set($index, 'b', [0.0, 1.0, 0.00001]));
        self::assertArrayHasKey('a', $c->vsim()->search($index, 1, [1.0, 0.0, 0.0]));
        self::assertSame('OK', $c->vsim()->dropIndex($index));

        $snap = $this->nsName('snap');
        $this->cleanup[] = fn () => $this->client()->vsim()->drop($snap);
        $blob = random_bytes(2048);
        self::assertGreaterThanOrEqual(2048, $c->vsim()->save($snap, $blob));
        self::assertSame($blob, $c->vsim()->load($snap));
        self::assertSame(1, $c->vsim()->drop($snap));
    }

    public function testTagsOnRemoteKeys(): void
    {
        $c = $this->client();
        $k = $this->key('tagged');
        $tag = $this->nsName('tag');
        $c->set($k, 'v');
        self::assertSame(1, $c->tag()->set($k, [$tag]));
        self::assertSame([$k], $c->tag()->members($tag));
        $c->tag()->rem($k, [$tag]);
    }
}
