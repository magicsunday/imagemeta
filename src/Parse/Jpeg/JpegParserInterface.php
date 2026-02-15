<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;

/**
 * Defines the contract for extracting metadata payloads from JPEG streams.
 */
interface JpegParserInterface
{
    /**
     * @return list<string>
     */
    public function extractExifBlobs(): array;

    /**
     * @return list<string>
     */
    public function extractXmpPackets(): array;

    /**
     * @return string|null
     */
    public function getIccProfile(): ?string;

    /**
     * @return list<string>
     */
    public function getIccSegments(): array;

    /**
     * @return list<string>
     */
    public function getIptcPayloads(): array;

    /**
     * @return array<int, string>
     */
    public function getFlashPixStreams(): array;

    /**
     * @return list<JpegAudioStream>
     */
    public function getAudioStreams(): array;

    /**
     * @return MpfDocument|null
     */
    public function getMpfDocument(): ?MpfDocument;

    /**
     * @return int|null
     */
    public function getFrameSamplePrecision(): ?int;

    /**
     * @return int|null
     */
    public function getFrameHeight(): ?int;

    /**
     * @return int|null
     */
    public function getFrameWidth(): ?int;

    /**
     * @return array<int, array{horizontal:int, vertical:int}>|null
     */
    public function getFrameComponentSamplingFactors(): ?array;

    /**
     * @return array{0:int,1:int}|null
     */
    public function getFrameYCbCrSubSampling(): ?array;
}
