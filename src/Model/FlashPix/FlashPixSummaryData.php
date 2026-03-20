<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\FlashPix;

use DateTimeImmutable;

/**
 * Represents extracted OLE Summary Information properties from a FlashPix stream.
 *
 * Property IDs follow the Microsoft OLE Summary Information specification.
 */
final readonly class FlashPixSummaryData
{
    /**
     * @param string|null            $title       Document title (PID 2).
     * @param string|null            $subject     Document subject (PID 3).
     * @param string|null            $author      Document author (PID 4).
     * @param string|null            $keywords    Document keywords (PID 5).
     * @param string|null            $comments    Document comments (PID 6).
     * @param string|null            $lastSavedBy Last person who saved the document (PID 8).
     * @param string|null            $application Application that created the document (PID 18).
     * @param DateTimeImmutable|null $createTime  Document creation time (PID 12).
     * @param DateTimeImmutable|null $saveTime    Last save time (PID 13).
     */
    public function __construct(
        public ?string $title = null,
        public ?string $subject = null,
        public ?string $author = null,
        public ?string $keywords = null,
        public ?string $comments = null,
        public ?string $lastSavedBy = null,
        public ?string $application = null,
        public ?DateTimeImmutable $createTime = null,
        public ?DateTimeImmutable $saveTime = null,
    ) {
    }
}
