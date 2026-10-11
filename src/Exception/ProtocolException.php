<?php

// Copyright (c) 2026 HotKV Ltd
// Licensed under the Apache License, Version 2.0. See LICENSE in the project root.

declare(strict_types=1);

namespace HotKV\Exception;

/**
 * Raised when data read from the server does not parse as valid RESP2 or
 * RESP3, or otherwise violates the protocol's framing rules.
 */
class ProtocolException extends HotKVException
{
}
