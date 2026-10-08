<?php

declare(strict_types=1);

namespace Zasmall\RelaySignature\Exceptions;

/**
 * The X-Relay-Signature header could not be parsed.
 */
final class MalformedSignature extends InvalidSignature {}
