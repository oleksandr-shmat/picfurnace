<?php

declare(strict_types=1);

namespace App\Pixelforge;

use RuntimeException;

/**
 * Base of everything PixelforgeClient throws. The subclasses tell the caller what to do next.
 */
abstract class PixelforgeException extends RuntimeException {}
