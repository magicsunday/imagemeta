<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use MagicSunday\ImageMeta\Value\Enum\SubjectAreaType;
use MagicSunday\ImageMeta\Value\SubjectArea;

use function array_values;

/**
 * Converts EXIF subject area representations.
 *
 * EXIF 3.0 §4.6.6.7.22 (SubjectArea) defines point, circle, and rectangle representations.
 */
final readonly class SubjectAreaConverter
{
    /**
     * Normalizes EXIF subject area representations into a rectangle map.
     *
     * EXIF 3.0 §4.6.6.7.22 (SubjectArea) defines Count = 2 (point), Count = 3 (circle),
     * and Count = 4 (rectangle) using unsigned SHORT components prior to rotation processing.
     *
     * @param array<int, int|float|string> $values Subject area values as extracted from metadata.
     *
     * @return array{x:int,y:int,w:int|null,h:int|null}|null
     */
    public function toRect(array $values): ?array
    {
        $subjectArea = SubjectArea::fromComponents(array_values($values));

        if (!$subjectArea instanceof SubjectArea) {
            return null;
        }

        return match ($subjectArea->type) {
            SubjectAreaType::Point => [
                'x' => $subjectArea->centerX,
                'y' => $subjectArea->centerY,
                'w' => null,
                'h' => null,
            ],
            SubjectAreaType::Circle => $subjectArea->diameter === null
                ? null
                : [
                    'x' => $subjectArea->centerX,
                    'y' => $subjectArea->centerY,
                    'w' => $subjectArea->diameter,
                    'h' => $subjectArea->diameter,
                ],
            SubjectAreaType::Rectangle => ($subjectArea->width === null || $subjectArea->height === null)
                ? null
                : [
                    'x' => $subjectArea->centerX,
                    'y' => $subjectArea->centerY,
                    'w' => $subjectArea->width,
                    'h' => $subjectArea->height,
                ],
        };
    }
}
