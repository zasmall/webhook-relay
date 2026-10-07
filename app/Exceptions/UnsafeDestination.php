<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A delivery URL resolved to an address the relay refuses to call.
 */
final class UnsafeDestination extends RuntimeException {}
