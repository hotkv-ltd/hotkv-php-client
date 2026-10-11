<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;
use HotKV\Support\Reply;

/**
 * ALERTS (admin; needs a server license that includes the
 * `enterprise-observability` feature). One command name with a
 * subcommand argument, like OBSERVE. Reach this via `$client->alerts()`.
 */
final class AlertsCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @return array{enabled: int, flag_enabled: int, webhook_url: string, provider: string, supported_providers: string, min_severity: string, alerts_sent: int, alerts_failed: int, alerts_dropped: int, alerts_rate_limited: int, alerts_deduped: int, alerts_filtered: int, alerts_queued: int} */
    public function info(): mixed
    {
        return Reply::toMap($this->client->execute(['ALERTS', 'INFO']));
    }

    /**
     * Sends a test alert. $severity defaults to the dispatcher's
     * configured minimum. $message, if given, is split on spaces into
     * separate command arguments the way the server expects and rejoined
     * server side into the custom message.
     *
     * @return string a simple string starting "OK"
     */
    public function test(?string $severity = null, ?string $message = null): mixed
    {
        $args = ['ALERTS', 'TEST'];
        if ($severity !== null) {
            $args[] = $severity;
        }
        if ($message !== null && $message !== '') {
            array_push($args, ...explode(' ', $message));
        }

        return $this->client->execute($args);
    }

    /** Empty string disables alerting. @return string a simple string starting "OK" */
    public function setUrl(string $url): mixed
    {
        return $this->client->execute(['ALERTS', 'SETURL', $url]);
    }

    /** @return string a simple string starting "OK" */
    public function setSeverity(string $severity): mixed
    {
        return $this->client->execute(['ALERTS', 'SETSEVERITY', $severity]);
    }

    /** Toggles the master flag without clearing the webhook URL. @return string a simple string starting "OK" */
    public function on(): mixed
    {
        return $this->client->execute(['ALERTS', 'ON']);
    }

    /** @return string a simple string starting "OK" */
    public function off(): mixed
    {
        return $this->client->execute(['ALERTS', 'OFF']);
    }
}
