<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Factory;

use MagicSunday\ImageMeta\Exif\Factory\ValueFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Parse\Icc\IccParser;
use MagicSunday\ImageMeta\Value\CaptureHardware;
use MagicSunday\ImageMeta\Value\CaptureSettings;
use MagicSunday\ImageMeta\Value\LocationTime;
use MagicSunday\ImageMeta\Value\MediaContent;
use MagicSunday\ImageMeta\Value\Provenance;
use MagicSunday\ImageMeta\Value\StructuredMetadata;
use MagicSunday\ImageMeta\Value\TechnicalData;

/**
 * Assembles structured metadata aggregates from normalized value objects.
 */
final readonly class StructuredMetadataBuilder
{
    /**
     * @param ValueFactory $valueFactory Factory used to build structured components.
     */
    public function __construct(private ValueFactory $valueFactory)
    {
    }

    /**
     * Creates a builder with default concrete dependencies.
     */
    public static function createDefault(): self
    {
        return new self(new ValueFactory(new IccParser()));
    }

    /**
     * Assembles the structured metadata aggregate from the supplied metadata container.
     */
    public function assemble(Metadata $metadata): StructuredMetadata
    {
        $components = $this->valueFactory->createComponents(
            metadata: $metadata,
        );

        $hardware = new CaptureHardware(
            camera: $components[ComponentKey::Camera->value],
            lens: $components[ComponentKey::Lens->value],
            sensor: $components[ComponentKey::Sensor->value],
            device: $components[ComponentKey::Device->value],
            focus: $components[ComponentKey::Focus->value],
        );

        $content = new MediaContent(
            image: $components[ComponentKey::Image->value],
            audio: $components[ComponentKey::Audio->value],
            embeddedAudio: $components[ComponentKey::EmbeddedAudio->value],
            video: $components[ComponentKey::Video->value],
            thumbnail: $components[ComponentKey::Thumbnail->value],
            depthMap: $components[ComponentKey::DepthMap->value],
            multiPicture: $components[ComponentKey::MultiPicture->value],
            regions: $components[ComponentKey::Regions->value],
        );

        $settings = new CaptureSettings(
            exposure: $components[ComponentKey::Exposure->value],
            whiteBalance: $components[ComponentKey::WhiteBalance->value],
            scene: $components[ComponentKey::Scene->value],
            motion: $components[ComponentKey::Motion->value],
            processing: $components[ComponentKey::Processing->value],
        );

        $provenance = new Provenance(
            author: $components[ComponentKey::Author->value],
            rights: $components[ComponentKey::Rights->value],
            iptc: $components[ComponentKey::Iptc->value],
            keywords: $components[ComponentKey::Keywords->value],
            file: $components[ComponentKey::File->value],
            container: $components[ComponentKey::Container->value],
            standards: $components[ComponentKey::Standards->value],
            related: $components[ComponentKey::Related->value],
        );

        $locationTime = new LocationTime(
            gps: $components[ComponentKey::Gps->value],
            temporal: $components[ComponentKey::Temporal->value],
            capture: $components[ComponentKey::Capture->value],
        );

        $technical = new TechnicalData(
            derived: $components[ComponentKey::Derived->value],
            colorProfile: $components[ComponentKey::ColorProfile->value],
            composite: $components[ComponentKey::Composite->value],
            interop: $components[ComponentKey::Interop->value],
            integrity: $components[ComponentKey::Integrity->value],
            tiff: $components[ComponentKey::Tiff->value],
            xmp: $components[ComponentKey::Xmp->value],
            flashPix: $components[ComponentKey::FlashPix->value],
        );

        return new StructuredMetadata(
            hardware: $hardware,
            content: $content,
            settings: $settings,
            provenance: $provenance,
            locationTime: $locationTime,
            technical: $technical,
            makerNotesApple: $components[ComponentKey::MakerNotesApple->value],
        );
    }
}
