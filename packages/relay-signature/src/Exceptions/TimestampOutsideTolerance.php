<?php

declare(strict_types=1);

namespace Zasmall\RelaySignature\Exceptions;

/**
 * The signature timestamp is outside the tolerance window.
 */
final class TimestampOutsideTolerance extends InvalidSignature {}
