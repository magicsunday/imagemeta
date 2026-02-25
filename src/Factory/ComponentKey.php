<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Factory;

/**
 * Identifies the named component slots exchanged between ValueFactory and StructuredMetadataBuilder.
 */
enum ComponentKey: string
{
    case Audio          = 'audio';
    case Author         = 'author';
    case Camera         = 'camera';
    case Capture        = 'capture';
    case ColorProfile   = 'colorProfile';
    case Composite      = 'composite';
    case Container      = 'container';
    case Derived        = 'derived';
    case DepthMap       = 'depthMap';
    case Device         = 'device';
    case EmbeddedAudio  = 'embeddedAudio';
    case Exposure       = 'exposure';
    case File           = 'file';
    case FlashPix       = 'flashPix';
    case Focus          = 'focus';
    case Gps            = 'gps';
    case Image          = 'image';
    case Integrity      = 'integrity';
    case Interop        = 'interop';
    case Iptc           = 'iptc';
    case Keywords       = 'keywords';
    case Lens           = 'lens';
    case Motion         = 'motion';
    case MultiPicture   = 'multiPicture';
    case Processing     = 'processing';
    case Regions        = 'regions';
    case Related        = 'related';
    case Rights         = 'rights';
    case Scene          = 'scene';
    case Sensor         = 'sensor';
    case Standards      = 'standards';
    case Temporal       = 'temporal';
    case Thumbnail      = 'thumbnail';
    case Tiff           = 'tiff';
    case Video          = 'video';
    case WhiteBalance   = 'whiteBalance';
    case Xmp            = 'xmp';
    case MakerNotesApple = 'makerNotesApple';
}
