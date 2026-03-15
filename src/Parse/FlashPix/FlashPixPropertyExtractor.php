<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\FlashPix;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Value\FlashPixSummaryInfo;

use function is_string;

/**
 * Extracts structured metadata from parsed OLE property sets in FlashPix streams.
 *
 * Maps well-known OLE Summary Information PIDs to named fields per the
 * Microsoft OLE Property Set specification.
 */
final class FlashPixPropertyExtractor
{
    private const int PID_TITLE = 2;

    private const int PID_SUBJECT = 3;

    private const int PID_AUTHOR = 4;

    private const int PID_KEYWORDS = 5;

    private const int PID_COMMENTS = 6;

    private const int PID_LAST_SAVED_BY = 8;

    private const int PID_CREATE_TIME = 12;

    private const int PID_SAVE_TIME = 13;

    private const int PID_APPLICATION = 18;

    /**
     * Extracts Summary Information properties from a parsed OLE property set.
     */
    public function extractSummaryInfo(OlePropertySet $set): ?FlashPixSummaryInfo
    {
        $title       = $this->string($set, self::PID_TITLE);
        $subject     = $this->string($set, self::PID_SUBJECT);
        $author      = $this->string($set, self::PID_AUTHOR);
        $keywords    = $this->string($set, self::PID_KEYWORDS);
        $comments    = $this->string($set, self::PID_COMMENTS);
        $lastSavedBy = $this->string($set, self::PID_LAST_SAVED_BY);
        $application = $this->string($set, self::PID_APPLICATION);
        $createTime  = $this->dateTime($set, self::PID_CREATE_TIME);
        $saveTime    = $this->dateTime($set, self::PID_SAVE_TIME);

        if (
            ($title === null)
            && ($subject === null)
            && ($author === null)
            && ($keywords === null)
            && ($comments === null)
            && ($application === null)
        ) {
            return null;
        }

        return new FlashPixSummaryInfo(
            $title,
            $subject,
            $author,
            $keywords,
            $comments,
            $lastSavedBy,
            $application,
            $createTime,
            $saveTime,
        );
    }

    private function string(OlePropertySet $set, int $pid): ?string
    {
        $value = $set->property($pid);

        return is_string($value) ? $value : null;
    }

    private function dateTime(OlePropertySet $set, int $pid): ?DateTimeImmutable
    {
        $value = $set->property($pid);

        return $value instanceof DateTimeImmutable ? $value : null;
    }
}
