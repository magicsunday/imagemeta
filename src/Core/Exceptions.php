<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

/**
 * Represents a generic parsing failure triggered by malformed input data.
 */
class ParseError extends \RuntimeException {}

/**
 * Represents an attempt to access bytes outside a declared safe range.
 */
class BoundsError extends \OutOfBoundsException {}
