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

    public function getMpfDocument(): ?MpfDocument;

    public function getFrameSamplePrecision(): ?int;

    public function getFrameHeight(): ?int;

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
