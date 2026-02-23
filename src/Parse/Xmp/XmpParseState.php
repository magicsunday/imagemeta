<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpContainer;
use MagicSunday\ImageMeta\Model\Xmp\XmpLanguageAlternative;
use MagicSunday\ImageMeta\Model\Xmp\XmpStructuredValue;

/**
 * Mutable accumulator holding all intermediate state during an XMP parse pass.
 */
final class XmpParseState
{
    /** @var array<string, array<int, string>|string|XmpLanguageAlternative> */
    public array $data = [];

    /** @var array<string, XmpStructuredValue> */
    public array $structuredData = [];

    /** @var array<string, XmpContainer> */
    public array $containerKinds = [];

    /** @var array<string, string> */
    public array $namespacePrefixes = [];

    /** @var array<int, array{string, string}> */
    public array $elementPath = [];

    /** @var array<int, string> */
    public array $textBuffers = [];

    /** @var array<int, list<string>> */
    public array $listBuffers = [];

    /** @var array<int, list<array{lang: string, value: string}>> */
    public array $altBuffers = [];

    /** @var array<int, string> */
    public array $listKinds = [];

    /** @var array<int, string> */
    public array $languageBuffers = [];

    /** @var array<int, array<string, array<int, string>|string|XmpLanguageAlternative|XmpStructuredValue>> */
    public array $structuredBuffers = [];

    public bool $insideRdfGraph = false;

    /**
     * Removes all depth-indexed buffers for the given element depth.
     */
    public function clearDepthBuffers(int $depth): void
    {
        unset(
            $this->elementPath[$depth],
            $this->textBuffers[$depth],
            $this->listBuffers[$depth],
            $this->altBuffers[$depth],
            $this->listKinds[$depth],
            $this->languageBuffers[$depth],
            $this->structuredBuffers[$depth],
        );
    }
}
