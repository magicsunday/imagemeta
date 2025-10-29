<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Provides access to the EXIF version matrix expectations shared across tests.
 */
final class ExifVersionExpectations
{
    private const string FIXTURE_DIR = __DIR__ . '/../Fixtures/Images/ExifVersions';

    private const string EXPECTATIONS_FILE = self::FIXTURE_DIR . '/expectations.json';

    /**
     * @var array<string, array{structured: array<string, mixed>, api: array<string, mixed>}>|null
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
     * @return iterable<string, array{string, array<string, mixed>}> Data provider payload for structured expectations.
     */
    public static function provideStructured(): iterable
    {
        foreach (self::all() as $fixture => $expectation) {
            yield $fixture => [$fixture, $expectation['structured']];
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}> Data provider payload for API expectations.
     */
    public static function provideApi(): iterable
    {
        foreach (self::all() as $fixture => $expectation) {
            yield $fixture => [$fixture, $expectation['api']];
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}> Data provider payload for both
     *                                                                                     structured and API expectations.
     */
    public static function provideAll(): iterable
    {
        foreach (self::all() as $fixture => $expectation) {
            yield $fixture => [$fixture, $expectation['structured'], $expectation['api']];
        }
    }

    /**
     * Returns the structured and API expectations for a given fixture.
     *
     * @return array{structured: array<string, mixed>, api: array<string, mixed>}
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
     * @return array<string, array{structured: array<string, mixed>, api: array<string, mixed>}> Cached expectations.
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

        /** @var array<string, array{structured: array<string, mixed>, api: array<string, mixed>}> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::$cache = $data;

        return self::$cache;
    }
}
