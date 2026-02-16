<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

/**
 * ISO BMFF / QuickTime four-character box type codes.
 *
 * Each case represents a box type defined by ISO 14496-12, EXIF 3.0 §4.8,
 * or the QuickTime File Format 2012 specification.
 */
enum BoxType: string
{
    /** QuickTime metadata box. */
    case META = 'meta';

    /** File type box describing the major brand. */
    case FTYP = 'ftyp';

    /** QuickTime movie box. */
    case MOOV = 'moov';

    /** Movie fragment box. */
    case MOOF = 'moof';

    /** UUID box used to store custom payloads. */
    case UUID = 'uuid';

    /** Embedded Exif box (EXIF 3.0 §4.8). */
    case EXIF = 'Exif';

    /** Item information box. */
    case IINF = 'iinf';

    /** Item location box. */
    case ILOC = 'iloc';

    /** Item data box. */
    case IDAT = 'idat';

    /** Primary item box. */
    case PITM = 'pitm';

    /** Item reference box. */
    case IREF = 'iref';

    /** Data information box. */
    case DINF = 'dinf';

    /** Data reference box. */
    case DREF = 'dref';

    /** URL data reference. */
    case URL = 'url ';

    /** URN data reference. */
    case URN = 'urn ';

    /** Embedded XMP metadata box. */
    case XMP = 'XMP ';

    /** QuickTime metadata keys box. */
    case KEYS = 'keys';

    /** QuickTime item list box. */
    case ILST = 'ilst';

    /** QuickTime user data box. */
    case UDTA = 'udta';

    /** QuickTime track name atom inside user data. */
    case NAME = 'name';

    /** QuickTime track container. */
    case TRAK = 'trak';

    /** Track header box. */
    case TKHD = 'tkhd';

    /** Media container box. */
    case MDIA = 'mdia';

    /** Handler reference box. */
    case HDLR = 'hdlr';

    /** Media information box. */
    case MINF = 'minf';

    /** Sample table box. */
    case STBL = 'stbl';

    /** Sample description box. */
    case STSD = 'stsd';

    /** Sampling Rate box inside AudioSampleEntryV1. */
    case SRAT = 'srat';

    /** Video media header box. */
    case VMHD = 'vmhd';

    /** Sound media header box. */
    case SMHD = 'smhd';

    /** Null media header box. */
    case NMHD = 'nmhd';

    /** Time-to-sample box. */
    case STTS = 'stts';

    /** Sample-to-chunk box. */
    case STSC = 'stsc';

    /** Sample size box. */
    case STSZ = 'stsz';

    /** Compact sample size box. */
    case STZ2 = 'stz2';

    /** Chunk offset box. */
    case STCO = 'stco';

    /** Large chunk offset box. */
    case CO64 = 'co64';

    /** Item information entry box. */
    case INFE = 'infe';

    /** QuickTime free-form metadata box. */
    case FREEFORM = '----';

    /** QuickTime data box. */
    case DATA = 'data';

    /** QuickTime metadata header atom. */
    case MHDR = 'mhdr';

    /** QuickTime item information atom inside metadata items. */
    case ITIF = 'itif';

    /** QuickTime country list atom. */
    case CTRY = 'ctry';

    /** QuickTime language list atom. */
    case LANG = 'lang';

    /** Movie header box. */
    case MVHD = 'mvhd';

    /** Media header box. */
    case MDHD = 'mdhd';
}
