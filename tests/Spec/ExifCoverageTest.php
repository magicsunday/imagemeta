<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Spec;

use MagicSunday\ImageMeta\Exif\StructuredExif;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

final class ExifCoverageTest extends TestCase
{
    public function testCoverageMapMatchesImplementation(): void
    {
        $map = $this->loadCoverageMap(__DIR__ . '/../../resources/exif-map.yaml');
        self::assertNotSame([], $map, 'Coverage map must not be empty.');

        $tagMethods  = $this->collectTagUsage();
        $tagMetadata = $this->collectTagMetadata();

        $missingTags = [];
        foreach (array_keys($tagMethods) as $tag) {
            if (!isset($map[$tag])) {
                $missingTags[] = $tag;
            }
        }

        self::assertSame([], $missingTags, 'All tags referenced by ParsedExif must be present in the coverage map.');

        $allowedIfds          = ['IFD0', 'ExifIFD', 'PreviewIFD', 'GPSIFD', 'InteropIFD'];
        $structuredReflection = new ReflectionClass(StructuredExif::class);
        $parsedReflection     = new ReflectionClass(ParsedExif::class);
        $knownParsedMethods   = array_fill_keys(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $parsedReflection->getMethods()), true);

        $unknownTypes = [];
        foreach ($map as $tag => $entry) {
            self::assertArrayHasKey('ifd', $entry, sprintf('Entry for %s must define the ifd field.', $tag));
            self::assertIsString($entry['ifd'], sprintf('Entry for %s must provide the ifd field as string.', $tag));
            self::assertContains($entry['ifd'], $allowedIfds, sprintf('Entry for %s references an unknown IFD "%s".', $tag, $entry['ifd']));

            self::assertArrayHasKey('type', $entry, sprintf('Entry for %s must define the type field.', $tag));
            self::assertIsString($entry['type'], sprintf('Entry for %s must provide the type field as string.', $tag));
            $type = $entry['type'];
            if ($type !== 'auto') {
                $typeConst = 'TYPE_' . strtoupper($type);
                if (!defined(TiffConst::class . '::' . $typeConst)) {
                    $unknownTypes[$tag] = $type;
                }
            }

            self::assertArrayHasKey('minVersion', $entry, sprintf('Entry for %s must define the minVersion field.', $tag));
            self::assertIsString($entry['minVersion'], sprintf('Entry for %s must provide the minVersion field as string.', $tag));
            $minVersion = $entry['minVersion'];
            self::assertMatchesRegularExpression('/^\d+\.\d+$/', $minVersion, sprintf('Entry for %s has invalid minVersion "%s".', $tag, $minVersion));

            if (isset($entry['maxVersion'])) {
                self::assertIsString($entry['maxVersion'], sprintf('Entry for %s must provide the maxVersion field as string.', $tag));
                $maxVersion = $entry['maxVersion'];
                self::assertMatchesRegularExpression('/^\d+\.\d+$/', $maxVersion, sprintf('Entry for %s has invalid maxVersion "%s".', $tag, $maxVersion));
            }

            if (isset($tagMetadata[$tag])) {
                self::assertSame($tagMetadata[$tag]['ifd'], $entry['ifd'], sprintf('Entry for %s has mismatching IFD (expected %s).', $tag, $tagMetadata[$tag]['ifd']));
                self::assertSame($tagMetadata[$tag]['min'], $minVersion, sprintf('Entry for %s has mismatching minVersion (expected %s).', $tag, $tagMetadata[$tag]['min']));
            }

            self::assertArrayHasKey('voGetter', $entry, sprintf('Entry for %s must define the voGetter field.', $tag));
            $rawGetters = $entry['voGetter'];
            $getters    = [];

            if (is_array($rawGetters)) {
                foreach ($rawGetters as $getter) {
                    self::assertIsString($getter, sprintf('Getter entry for %s must be a string.', $tag));
                    $getters[] = $getter;
                }
            } elseif (is_string($rawGetters)) {
                $getters[] = $rawGetters;
            } else {
                self::fail(sprintf('Entry for %s must define voGetter as string or list of strings.', $tag));
            }

            self::assertNotSame([], $getters, sprintf('Entry for %s must list at least one voGetter.', $tag));

            /** @var list<string> $getters */
            foreach ($getters as $getter) {
                $this->assertValidGetterPath($getter, $structuredReflection, $knownParsedMethods);
            }

            self::assertTrue(defined(ExifTag::class . '::' . $tag), sprintf('ExifTag constant for %s must exist.', $tag));
        }

        self::assertSame([], $unknownTypes, sprintf('Unknown type identifiers detected: %s', json_encode($unknownTypes, JSON_PRETTY_PRINT)));

        // Ensure there are no dangling entries pointing to methods that no longer exist.
        $danglingEntries = [];
        foreach ($map as $tag => $entry) {
            $rawGetters = $entry['voGetter'];
            $getters    = [];

            if (is_array($rawGetters)) {
                foreach ($rawGetters as $getter) {
                    if (!is_string($getter)) {
                        $danglingEntries[$tag] = $getter;
                        continue;
                    }

                    $getters[] = $getter;
                }
            } elseif (is_string($rawGetters)) {
                $getters[] = $rawGetters;
            } else {
                continue;
            }

            /** @var list<string> $getters */
            foreach ($getters as $getter) {
                if ($getter === '') {
                    $danglingEntries[$tag] = $getter;
                    continue;
                }

                $segments = explode('.', $getter);

                if ($segments[0] !== 'raw') {
                    continue;
                }

                $method = $segments[1] ?? null;
                if ($method === null || !isset($knownParsedMethods[$method])) {
                    $danglingEntries[$tag] = $getter;
                }
            }
        }

        self::assertSame([], $danglingEntries, sprintf('voGetter entries referencing unknown ParsedExif methods: %s', json_encode($danglingEntries, JSON_PRETTY_PRINT)));
    }

    /**
     * Parses the YAML coverage map into a structured array.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadCoverageMap(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $map        = [];
        $currentTag = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#')) {
                continue;
            }

            if (!str_starts_with($line, ' ')) {
                $tag        = rtrim($line, ':');
                $currentTag = $tag;
                $map[$tag]  = [];
                continue;
            }

            if ($currentTag === null) {
                continue;
            }

            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '- ')) {
                $value = substr($trimmed, 2);
                if (!isset($map[$currentTag]['voGetter']) || !is_array($map[$currentTag]['voGetter'])) {
                    $map[$currentTag]['voGetter'] = [];
                }

                $map[$currentTag]['voGetter'][] = $this->stripQuotes($value);
                continue;
            }

            if (str_contains($trimmed, ':')) {
                [$key, $value] = array_map(trim(...), explode(':', $trimmed, 2));
                $cleanValue    = $this->stripQuotes($value);

                if ($key === 'voGetter' && $cleanValue === '') {
                    $map[$currentTag][$key] = [];
                    continue;
                }

                $map[$currentTag][$key] = $cleanValue;
            }
        }

        return $map;
    }

    /**
     * Ensures that a getter path can be resolved via StructuredExif.
     *
     * @param ReflectionClass<StructuredExif> $structuredReflection
     * @param array<string, bool>             $knownParsedMethods
     */
    private function assertValidGetterPath(string $path, ReflectionClass $structuredReflection, array $knownParsedMethods): void
    {
        self::assertNotSame('', $path, 'Getter path must not be empty.');
        $currentClass = new ReflectionClass($structuredReflection->getName());
        $segments     = explode('.', $path);

        foreach ($segments as $index => $segment) {
            if ($currentClass->hasProperty($segment)) {
                $property = $currentClass->getProperty($segment);

                if ($index === count($segments) - 1) {
                    break;
                }

                $nextClassName = $this->resolveNextClassName($property->getType(), $currentClass->getName());

                if ($nextClassName === ParsedExif::class) {
                    $currentClass = new ReflectionClass(ParsedExif::class);
                    continue;
                }

                if ($nextClassName === null || !class_exists($nextClassName)) {
                    break;
                }

                $currentClass = new ReflectionClass($nextClassName);
                continue;
            }

            self::assertTrue($currentClass->hasMethod($segment), sprintf('Method "%s" not found on %s for getter "%s".', $segment, $currentClass->getName(), $path));
            $method = $currentClass->getMethod($segment);

            if ($index === count($segments) - 1) {
                if ($currentClass->getName() === ParsedExif::class) {
                    self::assertArrayHasKey($segment, $knownParsedMethods, sprintf('ParsedExif method "%s" referenced by getter "%s" is missing.', $segment, $path));
                }

                break;
            }

            $nextClassName = $this->resolveNextClassName($method->getReturnType(), $currentClass->getName());

            if ($nextClassName === ParsedExif::class) {
                $currentClass = new ReflectionClass(ParsedExif::class);
                continue;
            }

            if ($nextClassName === null || !class_exists($nextClassName)) {
                break;
            }

            $currentClass = new ReflectionClass($nextClassName);
        }
    }

    private function resolveNextClassName(?ReflectionType $type, string $context): ?string
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof ReflectionNamedType && !$inner->isBuiltin() && $inner->getName() !== 'null') {
                    return $inner->getName();
                }
            }

            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return null;
            }

            $name = $type->getName();
            if ($name === 'self') {
                return $context;
            }

            if ($name === 'null') {
                return null;
            }

            return $name;
        }

        return null;
    }

    /**
     * Collects the EXIF tags referenced inside ParsedExif and the methods exposing them.
     *
     * @return array<string, array<int, string>>
     */
    private function collectTagUsage(): array
    {
        $code = file_get_contents('src/Model/Exif/ParsedExif.php');
        if ($code === false) {
            return [];
        }

        $tokens          = token_get_all($code);
        $tagMethods      = [];
        $currentFunction = null;
        $braceLevel      = 0;
        $inFunctionBody  = false;
        $tokenCount      = count($tokens);

        for ($i = 0; $i < $tokenCount; ++$i) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_FUNCTION) {
                $j            = $i + 1;
                $functionName = null;
                while ($j < $tokenCount) {
                    $next = $tokens[$j];
                    if (is_array($next) && $next[0] === T_STRING) {
                        $functionName = $next[1];
                        break;
                    }

                    ++$j;
                }

                $currentFunction = $functionName;
                $braceLevel      = 0;
                $inFunctionBody  = false;
                $i               = $j;
                continue;
            }

            if ($currentFunction !== null) {
                if (!$inFunctionBody) {
                    if ($token === '{' || (is_array($token) && $token[0] === T_CURLY_OPEN)) {
                        $inFunctionBody = true;
                        $braceLevel     = 1;
                    }

                    continue;
                }

                if ($token === '{' || (is_array($token) && $token[0] === T_CURLY_OPEN)) {
                    ++$braceLevel;
                    continue;
                }

                if ($token === '}') {
                    --$braceLevel;
                    if ($braceLevel === 0) {
                        $currentFunction = null;
                        $inFunctionBody  = false;
                    }

                    continue;
                }

                if (is_array($token) && $token[0] === T_STRING && $token[1] === 'ExifTag') {
                    $next1         = $tokens[$i + 1] ?? null;
                    $next2         = $tokens[$i + 2] ?? null;
                    $isDoubleColon = (is_array($next1) && $next1[0] === T_DOUBLE_COLON) || $next1 === '::';
                    if ($isDoubleColon && is_array($next2) && $next2[0] === T_STRING) {
                        $tag                                = $next2[1];
                        $tagMethods[$tag][$currentFunction] = true;
                    }
                }
            }
        }

        ksort($tagMethods);
        $result = [];
        foreach ($tagMethods as $tag => $methods) {
            $result[$tag] = array_keys($methods);
        }

        return $result;
    }

    /**
     * Derives metadata such as IFD and minimum EXIF version from ExifTag definitions.
     *
     * @return array<string, array{ifd:string,min:string}>
     */
    private function collectTagMetadata(): array
    {
        $lines = file('src/Model/Exif/ExifTag.php', FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $inDoc     = false;
        $docBuffer = '';
        $metadata  = [];

        foreach ($lines as $line) {
            $trim = trim($line);

            if (str_starts_with($trim, '/**')) {
                $inDoc     = true;
                $docBuffer = $line;
                continue;
            }

            if ($inDoc) {
                $docBuffer .= $line;
                if (str_contains($trim, '*/')) {
                    $inDoc = false;
                }

                continue;
            }

            if (preg_match('/public const int ([A-Z0-9_]+) = [^;]+;(.*)/', $line, $matches) === 1) {
                $name          = $matches[1];
                $inlineComment = $matches[2];
                $commentText   = $docBuffer . ' ' . $inlineComment;
                $docBuffer     = '';

                $metadata[$name] = [
                    'ifd' => $this->inferIfdFromComment($commentText),
                    'min' => $this->extractMinimumVersion($commentText),
                ];
            }
        }

        return $metadata;
    }

    private function inferIfdFromComment(string $commentText): string
    {
        $normalized = strtolower($commentText);

        if (str_contains($commentText, '§4.6.12') || str_contains($normalized, 'preview ifd') || str_contains($normalized, 'preview image')) {
            return 'PreviewIFD';
        }

        if (str_contains($commentText, '§4.6.6') || str_contains($normalized, 'gps sub ifd')) {
            return 'GPSIFD';
        }

        if (str_contains($commentText, '§4.6.7') || str_contains($normalized, 'interoperability ifd')) {
            return 'InteropIFD';
        }

        if (str_contains($commentText, '§4.6.3') || str_contains($normalized, 'exif sub ifd') || str_contains($normalized, 'shooting conditions')) {
            return 'ExifIFD';
        }

        return 'IFD0';
    }

    private function extractMinimumVersion(string $commentText): string
    {
        $versionMatches    = [];
        $versionMatchCount = preg_match_all('/EXIF\s+([0-9]+(?:\.[0-9]+)?)/i', $commentText, $versionMatches);
        $versions          = ($versionMatchCount === false || $versionMatchCount === 0) ? [] : $versionMatches[1];

        if ($versions === []) {
            return '1.0';
        }

        $normalizedVersions = [];
        foreach ($versions as $version) {
            $clean = rtrim($version, '.');
            if (str_contains($clean, '.')) {
                $clean = rtrim(rtrim($clean, '0'), '.');
            }

            if (!str_contains($clean, '.')) {
                $clean .= '.0';
            }

            $normalizedVersions[$clean] = true;
        }

        $versionList = array_keys($normalizedVersions);
        sort($versionList, SORT_NUMERIC);

        return $versionList[0];
    }

    private function stripQuotes(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (($trimmed[0] === '"' && str_ends_with($trimmed, '"')) || ($trimmed[0] === "'" && str_ends_with($trimmed, "'"))) {
            return substr($trimmed, 1, -1);
        }

        return $trimmed;
    }
}
