<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\QuickTime;

/**
 * Centralised list of QuickTime atom type codes that surface metadata.
 *
 * QuickTime File Format 2012 defines the core atom structures and four-character codes
 * used to store movie metadata and track details.
 */
final readonly class QuickTimeTag
{
    /**
     * File type atom identifying the major/minor brands.
     * ISO/IEC 14496-12 (ISO BMFF) file type box.
     */
    public const string ATOM_FTYP = 'ftyp';

    /**
     * Movie atom container.
     * QuickTime File Format 2012, Movie Atoms.
     */
    public const string ATOM_MOOV = 'moov';

    /**
     * Track atom container.
     * QuickTime File Format 2012, Movie Atoms.
     */
    public const string ATOM_TRAK = 'trak';

    /**
     * Track header atom describing track characteristics.
     * QuickTime File Format 2012, Track Header Atoms.
     */
    public const string ATOM_TKHD = 'tkhd';

    /**
     * Media atom container for a track.
     * QuickTime File Format 2012, Media Atoms.
     */
    public const string ATOM_MDIA = 'mdia';

    /**
     * Handler reference atom identifying the media handler.
     * QuickTime File Format 2012, Handler Reference Atoms.
     */
    public const string ATOM_HDLR = 'hdlr';

    /**
     * Media information atom container.
     * QuickTime File Format 2012, Media Information Atoms.
     */
    public const string ATOM_MINF = 'minf';

    /**
     * Sample table atom container.
     * QuickTime File Format 2012, Sample Table Atoms.
     */
    public const string ATOM_STBL = 'stbl';

    /**
     * Sample description atom describing sample formats.
     * QuickTime File Format 2012, Sample Description Atoms.
     */
    public const string ATOM_STSD = 'stsd';

    /**
     * User data atom container.
     * QuickTime File Format 2012, User Data Atoms.
     */
    public const string ATOM_UDTA = 'udta';

    /**
     * Metadata atom container for item list/keys.
     * ISO/IEC 14496-12 (ISO BMFF) metadata box.
     */
    public const string ATOM_META = 'meta';

    /**
     * Metadata keys atom mapping key indices to names.
     * ISO/IEC 14496-12 (ISO BMFF) metadata keys box.
     */
    public const string ATOM_KEYS = 'keys';

    /**
     * Metadata item list atom containing values.
     * ISO/IEC 14496-12 (ISO BMFF) item list box.
     */
    public const string ATOM_ILST = 'ilst';

    /**
     * Metadata data atom containing the payload.
     * ISO/IEC 14496-12 (ISO BMFF) data box.
     */
    public const string ATOM_DATA = 'data';

    /**
     * Free-form metadata mean atom.
     * ISO/IEC 14496-12 (ISO BMFF) free-form metadata (mean/name/data).
     */
    public const string ATOM_MEAN = 'mean';

    /**
     * Free-form metadata name atom.
     * ISO/IEC 14496-12 (ISO BMFF) free-form metadata (mean/name/data).
     */
    public const string ATOM_NAME = 'name';
}
