<?php

declare(strict_types=1);

namespace Zasmall\RelaySignature\Exceptions;

/**
 * The X-Relay-Signature header is missing.
 */
final class MissingSignature extends InvalidSignature {}
