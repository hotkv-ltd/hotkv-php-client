<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Enterprise;

use HotKV\CommandExecutor;
use HotKV\Support\Reply;

/**
 * OBSERVE (admin; needs a server license that includes the
 * `enterprise-observability` feature). This is one command name with a
 * subcommand argument ("OBSERVE INFO", "OBSERVE METRICS"), unlike the
 * dotted families. Reach this via `$client->observe()`.
 */
final class ObserveCommands
{
    public function __construct(
        private readonly CommandExecutor $client,
    ) {
    }

    /** @return array{uptime_seconds: int, connected_clients: int, total_commands: int, used_memory_bytes: int, peak_memory_bytes: int, total_connections: int, enterprise_features: string} */
    public function info(): mixed
    {
        return Reply::toMap($this->client->execute(['OBSERVE', 'INFO']));
    }

    /** @return array{uptime_seconds: int, connected_clients: int, total_commands: int, total_connections: int, used_memory_bytes: int, peak_memory_bytes: int, slowlog_length: int, alerts_sent: int, alerts_failed: int, num_workers: int} */
    public function metrics(): mixed
    {
        return Reply::toMap($this->client->execute(['OBSERVE', 'METRICS']));
    }
}
