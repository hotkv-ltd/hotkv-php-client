<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Exception;

/**
 * Base type for every exception raised by the HotKV SDK.
 *
 * Catch this to handle any failure coming from the client: a transport
 * problem, a malformed reply, or an error reply from the server.
 */
class HotKVException extends \RuntimeException
{
}
