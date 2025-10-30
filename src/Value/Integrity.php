<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Represents provenance and integrity information about the asset.
 */
final readonly class Integrity
{
    /**
     * @param string|null $originalFileName    Original file name when available.
     * @param string|null $originalDigest      Digest identifying the original asset.
     * @param bool|null   $edited              Indicates whether editing history is present.
     * @param string|null $historyLastSoftware Last software reported in the editing history.
     * @param string|null $imageHistory        Free-form history description recorded in EXIF.
     * @param bool|null   $makerNotesSafe      Flag denoting whether the maker notes are safe to edit.
     */
    public function __construct(
        public ?string $originalFileName,
        public ?string $originalDigest,
        public ?bool $edited,
        public ?string $historyLastSoftware,
        public ?string $imageHistory,
        public ?bool $makerNotesSafe = null,
    ) {
    }

    /**
     * Returns the original file name when available.
     */
    public function originalFileName(): ?string
    {
        return $this->originalFileName;
    }

    /**
     * Returns the digest identifying the original asset.
     */
    public function originalDigest(): ?string
    {
        return $this->originalDigest;
    }

    /**
     * Indicates whether editing history is present.
     */
    public function edited(): ?bool
    {
        return $this->edited;
    }

    /**
     * Returns the last software recorded in the editing history.
     */
    public function historyLastSoftware(): ?string
    {
        return $this->historyLastSoftware;
    }

    /**
     * Returns the free-form image history description.
     */
    public function imageHistory(): ?string
    {
        return $this->imageHistory;
    }

    /**
     * Returns the flag denoting whether maker notes are safe to edit.
     */
    public function makerNotesSafe(): ?bool
    {
        return $this->makerNotesSafe;
    }
}
