<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core\Util;

use MagicSunday\ImageMeta\Core\Util\Iso6709Parser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ISO 6709 parser for decoding GPS coordinates from QuickTime ©xyz atoms.
 * It validates latitude, longitude, and optional altitude extraction from coordinate strings.
 * The suite covers positive and negative coordinates, with and without altitude.
 * It asserts that invalid or empty input returns null.
 */
#[CoversClass(Iso6709Parser::class)]
final class Iso6709ParserTest extends TestCase
{
    /**
     * Parses a full coordinate string with latitude, longitude, altitude, and trailing slash.
     * It confirms all three components are extracted accurately.
     */
    #[Test]
    public function parsesLatLonAltWithTrailingSlash(): void
    {
        $result = Iso6709Parser::parse('+48.1234+011.5678+500.000/');

        self::assertNotNull($result);
        self::assertEqualsWithDelta(48.1234, $result['latitude'], 0.0001);
        self::assertEqualsWithDelta(11.5678, $result['longitude'], 0.0001);
        self::assertEqualsWithDelta(500.0, $result['altitude'], 0.1);
    }

    /**
     * Parses a coordinate string with negative latitude.
     * It confirms the sign is preserved and altitude is null when omitted.
     */
    #[Test]
    public function parsesNegativeCoordinates(): void
    {
        $result = Iso6709Parser::parse('-33.8688+151.2093/');

        self::assertNotNull($result);
        self::assertEqualsWithDelta(-33.8688, $result['latitude'], 0.0001);
        self::assertEqualsWithDelta(151.2093, $result['longitude'], 0.0001);
        self::assertNull($result['altitude']);
    }

    /**
     * Parses a coordinate string with positive latitude and negative longitude.
     * It verifies the longitude sign is preserved correctly.
     */
    #[Test]
    public function parsesLatLonWithoutAltitude(): void
    {
        $result = Iso6709Parser::parse('+34.0522-118.2437/');

        self::assertNotNull($result);
        self::assertEqualsWithDelta(34.0522, $result['latitude'], 0.0001);
        self::assertEqualsWithDelta(-118.2437, $result['longitude'], 0.0001);
        self::assertNull($result['altitude']);
    }

    /**
     * Parses a coordinate string that lacks the trailing slash terminator.
     * It confirms parsing succeeds without the optional slash.
     */
    #[Test]
    public function parsesWithoutTrailingSlash(): void
    {
        $result = Iso6709Parser::parse('+34.0522-118.2437');

        self::assertNotNull($result);
        self::assertEqualsWithDelta(34.0522, $result['latitude'], 0.0001);
        self::assertEqualsWithDelta(-118.2437, $result['longitude'], 0.0001);
    }

    /**
     * Passes an empty string to the parser.
     * It asserts null is returned for empty input.
     */
    #[Test]
    public function returnsNullForEmptyString(): void
    {
        self::assertNull(Iso6709Parser::parse(''));
    }

    /**
     * Passes a non-coordinate string to the parser.
     * It asserts null is returned for invalid input.
     */
    #[Test]
    public function returnsNullForInvalidInput(): void
    {
        self::assertNull(Iso6709Parser::parse('invalid'));
    }
}
