<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Value\Enum\ParseWarningScope;

/**
 * Reports damaged input that the reader tolerated instead of failing the whole read.
 *
 * The reader returns everything it could extract and records one warning per
 * tolerated failure, so consumers decide themselves whether a partially read
 * file is acceptable. The code is the numeric code of the tolerated ParseError
 * (0 for a BoundsError, i.e. data ending before a declared length).
 */
final readonly class ParseWarning
{
    /**
     * @param ParseWarningScope $scope   Part of the file whose data is incomplete.
     * @param int               $code    Numeric code of the tolerated error.
     * @param string            $message Human-readable description of the damage.
     */
    public function __construct(
        public ParseWarningScope $scope,
        public int $code,
        public string $message,
    ) {
    }

    /**
     * Creates a warning from a tolerated parser failure.
     *
     * @param ParseWarningScope      $scope     Part of the file whose data is incomplete.
     * @param ParseError|BoundsError $exception Tolerated failure.
     */
    public static function fromThrowable(ParseWarningScope $scope, ParseError|BoundsError $exception): self
    {
        return new self($scope, (int) $exception->getCode(), $exception->getMessage());
    }
}
