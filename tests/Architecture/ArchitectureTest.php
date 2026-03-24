<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture rules enforced by PHPat (runs as part of PHPStan).
 *
 * AGENTS.md §5.7 — Detect ≠ Parse ≠ Model ≠ Convenience ≠ Value.
 * Restricted layers must not cross their boundaries.
 *
 * Note: Value → Model is intentional (Value objects wrap raw Model data,
 * e.g. Iptc→IptcDocument, Xmp→XmpDocument, AudioClips→JpegAudioStream).
 *
 * @internal
 */
final class ArchitectureTest
{
    #[TestRule]
    public function modelDoesNotDependOnParse(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Parse'))
            ->because('AGENTS.md §5.7 — Model holds data, Parse parses streams');
    }

    #[TestRule]
    public function modelDoesNotDependOnFactory(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Factory'))
            ->because('AGENTS.md §5.5 — Models hold data, factories create');
    }

    #[TestRule]
    public function convenienceDoesNotDependOnParse(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Convenience'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Parse'))
            ->because('AGENTS.md §5.7 — Convenience wraps, Parse parses');
    }

    #[TestRule]
    public function exifDoesNotDependOnParse(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Exif'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Parse'))
            ->because('AGENTS.md §5.7 — Exif layer must not import from Parse');
    }

    #[TestRule]
    public function valueDoesNotDependOnParse(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Value'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Parse'))
            ->because('AGENTS.md §5.7 — Value is typed output, Parse is streaming input');
    }

    #[TestRule]
    public function detectDoesNotDependOnParse(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Detect'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Parse'))
            ->because('AGENTS.md §5.7 — Detect identifies containers by signature, not by parsing');
    }

    #[TestRule]
    public function detectDoesNotDependOnModel(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Detect'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Model'))
            ->because('AGENTS.md §5.7 — Detect uses signatures, not data models');
    }

    #[TestRule]
    public function parseDoesNotDependOnFactory(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Parse'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Factory'))
            ->because('AGENTS.md §5.5 — Parsers parse, factories create');
    }

    #[TestRule]
    public function makerNotesDoesNotDependOnParse(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\MakerNotes'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\ImageMeta\Parse'))
            ->because('AGENTS.md §5.7 — Vendor logic in MakerNotes, not in parsers');
    }
}
