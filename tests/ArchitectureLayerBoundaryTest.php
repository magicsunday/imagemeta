<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests;

use MagicSunday\ImageMeta\Contract\IccParserInterface;
use MagicSunday\ImageMeta\Contract\IptcParserInterface;
use MagicSunday\ImageMeta\Contract\TiffExifParserInterface;
use MagicSunday\ImageMeta\Contract\XmpParserInterface;
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Parse\Iptc\IptcParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function class_implements;
use function file_get_contents;
use function is_string;
use function preg_match_all;
use function str_starts_with;

/**
 * Enforces architecture layer boundaries by scanning source files for forbidden imports.
 *
 * AGENTS.md §5.7 — Detect ≠ Parse ≠ Model ≠ Convenience.
 * Restricted layers (Model, Convenience, Exif, Value) must not import from the Parse namespace.
 * Parser interfaces reside in the Contract namespace and may be used by any layer.
 *
 * @internal
 */
#[CoversClass(IptcParserInterface::class)]
#[CoversClass(XmpParserInterface::class)]
#[CoversClass(TiffExifParserInterface::class)]
#[CoversClass(IccParserInterface::class)]
#[UsesClass(IptcParser::class)]
#[UsesClass(XmpParser::class)]
#[UsesClass(TiffExifParser::class)]
#[UsesClass(IccParser::class)]
final class ArchitectureLayerBoundaryTest extends TestCase
{
    /**
     * Scans restricted source directories for forbidden Parse namespace imports.
     *
     * Only MetadataReader.php (the top-level orchestrator) is exempt from this rule.
     */
    #[Test]
    public function restrictedLayersDoNotImportFromParseNamespace(): void
    {
        $srcRoot        = __DIR__ . '/../src';
        $restrictedDirs = ['Model', 'Convenience', 'Exif', 'Value'];

        $violations = [];

        foreach ($restrictedDirs as $dir) {
            $dirPath = $srcRoot . '/' . $dir;

            if (!is_dir($dirPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dirPath),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if (!is_string($contents)) {
                    continue;
                }

                $matches = [];
                preg_match_all(
                    '/^use\s+MagicSunday\\\\ImageMeta\\\\Parse\\\\/m',
                    $contents,
                    $matches,
                );

                if ($matches[0] !== []) {
                    $violations[] = $dir . '/' . $file->getFilename() . ': ' . $matches[0][0];
                }
            }
        }

        self::assertSame([], $violations, 'Restricted layers must not import from the Parse namespace');
    }

    /**
     * Verifies parser interfaces reside in the Contract namespace.
     */
    #[Test]
    public function parserInterfacesResideInContractNamespace(): void
    {
        self::assertTrue(
            str_starts_with(IptcParserInterface::class, 'MagicSunday\\ImageMeta\\Contract\\'),
            'IptcParserInterface must reside in the Contract namespace',
        );

        self::assertTrue(
            str_starts_with(XmpParserInterface::class, 'MagicSunday\\ImageMeta\\Contract\\'),
            'XmpParserInterface must reside in the Contract namespace',
        );

        self::assertTrue(
            str_starts_with(TiffExifParserInterface::class, 'MagicSunday\\ImageMeta\\Contract\\'),
            'TiffExifParserInterface must reside in the Contract namespace',
        );

        self::assertTrue(
            str_starts_with(IccParserInterface::class, 'MagicSunday\\ImageMeta\\Contract\\'),
            'IccParserInterface must reside in the Contract namespace',
        );
    }

    /**
     * Verifies concrete parsers implement the Contract interfaces.
     */
    #[Test]
    public function concreteParserClassesImplementContractInterfaces(): void
    {
        $implementations = [
            IptcParserInterface::class     => IptcParser::class,
            XmpParserInterface::class      => XmpParser::class,
            TiffExifParserInterface::class => TiffExifParser::class,
            IccParserInterface::class      => IccParser::class,
        ];

        foreach ($implementations as $interface => $concrete) {
            self::assertContains(
                $interface,
                class_implements($concrete),
                $concrete . ' must implement ' . $interface,
            );
        }
    }
}
