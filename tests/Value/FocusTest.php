<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Enum\SubjectAreaType;
use MagicSunday\ImageMeta\Value\Focus;
use MagicSunday\ImageMeta\Value\SubjectArea;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Focus value object for subject distance and AF metadata.
 * It verifies subject distance values are stored alongside AF mode strings.
 * The suite covers SubjectArea composition with type and coordinate fields.
 * This ensures focus-related metadata remains consistent in structured output.
 */
#[CoversClass(Focus::class)]
final class FocusTest extends TestCase
{
    /**
     * Stores subject distance values.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithFocusDistance(): void
    {
        $focus = new Focus(
            subjectDistanceM: 2.5,
            subjectArea: null,
            afMode: null,
        );

        self::assertSame(2.5, $focus->subjectDistanceM);
    }

    /**
     * Stores subject area rectangles when provided.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithSubjectArea(): void
    {
        $subjectArea = new SubjectArea(
            type: SubjectAreaType::Rectangle,
            centerX: 100,
            centerY: 200,
            width: 50,
            height: 75,
        );

        $focus = new Focus(
            subjectDistanceM: null,
            subjectArea: $subjectArea,
            afMode: null,
        );

        self::assertNotNull($focus->subjectArea);
        self::assertSame(100, $focus->subjectArea->centerX);
        self::assertSame(200, $focus->subjectArea->centerY);
        self::assertSame(50, $focus->subjectArea->width);
        self::assertSame(75, $focus->subjectArea->height);
    }

    /**
     * Stores autofocus mode values.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithAFMode(): void
    {
        $focus = new Focus(
            subjectDistanceM: null,
            subjectArea: null,
            afMode: 'Continuous',
        );

        self::assertSame('Continuous', $focus->afMode);
    }

    /**
     * Accepts null focus fields.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $focus = new Focus(
            subjectDistanceM: null,
            subjectArea: null,
            afMode: null,
        );

        self::assertNull($focus->subjectDistanceM);
        self::assertNull($focus->subjectArea);
        self::assertNull($focus->afMode);
    }
}
