<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Provides access to the EXIF version matrix expectations shared across tests.
 *
 * @phpstan-import-type StructuredExpectations from ExifExpectationAssertions
 * @phpstan-import-type ApiExpectations from ExifExpectationAssertions
 * @phpstan-import-type ModelExpectations from ExifExpectationAssertions
 * @phpstan-type AllExpectations array{
 *     structured: StructuredExpectations,
 *     api: ApiExpectations,
 *     model: ModelExpectations,
 * }
 */
final class ExifVersionExpectations
{
    private const string FIXTURE_DIR = __DIR__ . '/../Fixtures/Images/ExifVersions';

    private const string EXPECTATIONS_FILE = self::FIXTURE_DIR . '/expectations.json';

    /**
     * @var array<string, AllExpectations>|null
     */
    private static ?array $cache = null;

    /**
     * Returns the absolute path to the fixture with the given filename.
     */
    public static function path(string $fixture): string
    {
        return self::FIXTURE_DIR . '/' . $fixture;
    }

    /**
     * @return iterable<string, array{string, StructuredExpectations}> Data provider payload for structured expectations.
     */
    public static function provideStructured(): iterable
    {
        foreach (self::all() as $fixture => $expectation) {
            yield $fixture => [$fixture, $expectation['structured']];
        }
    }

    /**
     * @return iterable<string, array{string, ApiExpectations}> Data provider payload for API expectations.
     */
    public static function provideApi(): iterable
    {
        foreach (self::all() as $fixture => $expectation) {
            yield $fixture => [$fixture, $expectation['api']];
        }
    }

    /**
     * @return iterable<string, array{string, StructuredExpectations, ApiExpectations, ModelExpectations}> Data provider
     *                                                                                                          payload for
     *                                                                                                          structured,
     *                                                                                                          API and raw
     *                                                                                                          model
     *                                                                                                          expectations.
     */
    public static function provideAll(): iterable
    {
        foreach (self::all() as $fixture => $expectation) {
            yield $fixture => [$fixture, $expectation['structured'], $expectation['api'], $expectation['model']];
        }
    }

    /**
     * Returns the structured, API and model expectations for a given fixture.
     *
     * @return AllExpectations
     */
    public static function get(string $fixture): array
    {
        $expectations = self::all();

        if (!isset($expectations[$fixture])) {
            throw new InvalidArgumentException(sprintf('Unknown EXIF fixture expectation: %s', $fixture));
        }

        return $expectations[$fixture];
    }

    /**
     * @return array<string, AllExpectations> Cached expectations.
     */
    private static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $json = file_get_contents(self::EXPECTATIONS_FILE);
        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to read EXIF expectations from %s', self::EXPECTATIONS_FILE));
        }

        /** @var array<string, AllExpectations> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::$cache = $data;

        return self::$cache;
    }
}
