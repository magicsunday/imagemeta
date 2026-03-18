<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Model;

use function spl_object_id;

/**
 * Encapsulates the fallback IFD resolution logic used by domain readers.
 *
 * When primary metadata is absent, certain readers consult secondary IFDs
 * (thumbnails, sub-IFDs, subsequent IFDs) in a deterministic priority order.
 */
final readonly class FallbackIfdSet
{
    /**
     * @param Ifd|null        $ifd1           Optional primary thumbnail IFD.
     * @param array<int, Ifd> $subIfds        Parsed SubIFDs indexed by their file offsets.
     * @param list<Ifd>       $subsequentIfds Additional linked IFDs from the next-pointer chain.
     * @param Ifd             $ifd0           Root IFD of the TIFF structure.
     */
    public function __construct(
        private ?Ifd $ifd1,
        private array $subIfds,
        private array $subsequentIfds,
        private Ifd $ifd0,
    ) {
    }

    /**
     * Provides the fallback IFDs consulted when primary metadata is absent.
     *
     * @param bool $includePrimaryThumbnail When true the primary thumbnail (IFD1) is considered.
     * @param bool $includeIfd0             When true the root directory (IFD0) is appended as a last resort.
     *
     * @return list<Ifd>
     */
    public function resolve(bool $includePrimaryThumbnail = true, bool $includeIfd0 = false): array
    {
        $ifds = [];
        $seen = [];

        $append = static function (?Ifd $candidate) use (&$ifds, &$seen): void {
            if (!$candidate instanceof Ifd) {
                return;
            }

            $id = spl_object_id($candidate);

            if (isset($seen[$id])) {
                return;
            }

            $seen[$id] = true;
            $ifds[]    = $candidate;
        };

        if ($includePrimaryThumbnail) {
            $append($this->ifd1);
        }

        foreach ($this->subIfds as $ifd) {
            $append($ifd);
        }

        foreach ($this->subsequentIfds as $ifd) {
            $append($ifd);
        }

        if ($includeIfd0) {
            $append($this->ifd0);
        }

        return $ifds;
    }
}
