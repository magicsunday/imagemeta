<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Jpeg\JfifSegment;
use MagicSunday\ImageMeta\Model\Jpeg\JpegAudioStream;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;

/**
 * Defines the contract for extracting metadata payloads from JPEG streams.
 */
interface JpegParserInterface
{
    /**
     * @return list<string>
     *
     * @throws ParseError
     */
    public function extractExifBlobs(): array;

    /**
     * @return list<string>
     *
     * @throws ParseError
     */
    public function extractXmpPackets(): array;

    /**
     * @throws ParseError
     */
    public function getIccProfile(): ?string;

    /**
     * @return list<string>
     *
     * @throws ParseError
     */
    public function getIccSegments(): array;

    /**
     * @return list<string>
     *
     * @throws ParseError
     */
    public function getIptcPayloads(): array;

    /**
     * @return array<int, string>
     *
     * @throws ParseError
     */
    public function getFlashPixStreams(): array;

    /**
     * @return list<JpegAudioStream>
     *
     * @throws ParseError
     */
    public function getAudioStreams(): array;

    /**
     * @throws ParseError
     */
    public function getMpfDocument(): ?MpfDocument;

    /**
     * @throws ParseError
     */
    public function getJfifSegment(): ?JfifSegment;

    /**
     * @throws ParseError
     */
    public function getFrameSamplePrecision(): ?int;

    /**
     * @throws ParseError
     */
    public function getFrameHeight(): ?int;

    /**
     * @throws ParseError
     */
    public function getFrameWidth(): ?int;

    /**
     * @return array<int, array{horizontal:int, vertical:int}>|null
     *
     * @throws ParseError
     */
    public function getFrameComponentSamplingFactors(): ?array;

    /**
     * @return array{0:int,1:int}|null
     *
     * @throws ParseError
     */
    public function getFrameYCbCrSubSampling(): ?array;
}
