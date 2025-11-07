<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Microsoft;

/**
 * Microsoft Windows XP proprietary metadata tag identifiers.
 *
 * These tags are NOT part of the EXIF specification. They were introduced by Microsoft
 * for Windows XP and Windows Explorer to store image properties encoded as UTF-16LE strings.
 *
 * @see https://docs.microsoft.com/en-us/windows/win32/wic/-wic-codec-metadataquerylanguage
 */
final readonly class MicrosoftXpTag
{
    /**
     * Microsoft XPTitle property encoded as UTF-16LE.
     *
     * Windows Explorer image title field.
     */
    public const int XP_TITLE = 0x9C9B;

    /**
     * Microsoft XPComment property encoded as UTF-16LE.
     *
     * Windows Explorer image comment field.
     */
    public const int XP_COMMENT = 0x9C9C;

    /**
     * Microsoft XPAuthor property encoded as UTF-16LE.
     *
     * Windows Explorer image author field.
     */
    public const int XP_AUTHOR = 0x9C9D;

    /**
     * Microsoft XPKeywords property encoded as UTF-16LE.
     *
     * Windows Explorer image keywords field (semicolon-separated).
     */
    public const int XP_KEYWORDS = 0x9C9E;

    /**
     * Microsoft XPSubject property encoded as UTF-16LE.
     *
     * Windows Explorer image subject field.
     */
    public const int XP_SUBJECT = 0x9C9F;

    /**
     * Prevent instantiation of this constants-only utility class.
     */
    private function __construct()
    {
    }
}
