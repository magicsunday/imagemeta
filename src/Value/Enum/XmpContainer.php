<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

/**
 * XMP RDF container type identifiers.
 *
 * XMP specification §5.7.2 and RDF Schema §2.2 define the container types
 * (Alt, Bag, Seq) used to represent multi-valued properties within XMP metadata.
 * This enum provides type-safe container identification when parsing XMP packets
 * embedded in JPEG APP1 segments or ISOBMFF uuid/item payloads.
 */
enum XmpContainer: string
{
    /**
     * Alternative container (rdf:Alt).
     *
     * XMP specification §5.7.2.2 describes Alt as an ordered list of alternative
     * values differentiated by language or qualifier attributes. The first child
     * represents the default value (typically xml:lang="x-default").
     *
     * Example: Title or description in multiple languages.
     */
    case Alt = 'Alt';

    /**
     * Unordered container (rdf:Bag).
     *
     * XMP specification §5.7.2.3 defines Bag as an unordered collection of values
     * where element order carries no semantic meaning. Typically used for keywords,
     * subject categories, or tag lists.
     *
     * Example: dc:subject keywords or photoshop:SupplementalCategories.
     */
    case Bag = 'Bag';

    /**
     * Ordered container (rdf:Seq).
     *
     * XMP specification §5.7.2.1 specifies Seq as an ordered list where element
     * position is significant. Sequence containers preserve the declared order
     * of values for ranked or chronological properties.
     *
     * Example: crs:ToneCurvePV2012 control points or xmpMM:History processing steps.
     */
    case Seq = 'Seq';
}
