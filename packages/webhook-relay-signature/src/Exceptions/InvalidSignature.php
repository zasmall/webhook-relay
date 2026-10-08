<?php

declare(strict_types=1);

namespace Zasmall\RelaySignature\Exceptions;

use RuntimeException;

/**
 * Base class for every reason a webhook signature is rejected.
 */
abstract class InvalidSignature extends RuntimeException {}
