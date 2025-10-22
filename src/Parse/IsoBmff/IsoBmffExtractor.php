<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;

/**
 * Streaming ISOBMFF reader for HEIC/AVIF/MP4/MOV.
 * - Extracts EXIF blob from 'meta' → 'Exif' box or via item ('iloc' referencing)
 * - Reads QuickTime key/value metadata (keys/ilst) to get content.identifier
 */
final class IsoBmffExtractor
{
    public function __construct(private readonly Stream $s) {}

    /**
     * @return array{0: list<string> EXIF, 1: list<string> XMP, 2: ?QuickTimeMeta}
     */
    public function extract(): array
    {
        $exifs = [];
        $xmps  = [];
        $qtKeys = [];

        foreach ($this->walkTopLevel() as $box) {
            if ($box->type === 'meta') {
                $exifBlob = $this->extractExifFromMeta($box->win);
                if ($exifBlob !== null) $exifs[] = $exifBlob;
                $xmps = array_merge($xmps, $this->extractXmpFromMeta($box->win));
                $qtKeys += $this->readQuickTimeKeysUnderMeta($box->win);
            } elseif ($box->type === 'moov') {
                $qtKeys += $this->readQuickTimeFromMoov($box->win);
                $xmps = array_merge($xmps, $this->extractXmpFromMoov($box->win));
            } elseif ($box->type === 'uuid') {
                // XMP in uuid box? Adobe XMP UUID: BE7ACFCB-97A9-42E8-9C71-999491E3AFAC
                $uuid = $box->win->read(16);
                $xmpGuid = hex2bin('be7acfcb97a942e89c71999491e3afac');
                if ($uuid === $xmpGuid) {
                    $xmps[] = $this->readBoxPayload($box->win);
                }
            }
        }
        $qt = $qtKeys ? new QuickTimeMeta($qtKeys) : null;
        return [$exifs, $xmps, $qt];
    }

    private function extractXmpFromMeta(StreamWindow $meta): array
    {
        $out = [];
        $meta->seek(0);
        if ($meta->size() < 4) return $out;
        $meta->read(4);

        // XMP als Item (infe: content_type application/rdf+xml) → iloc auflösen
        $xmpItems = [];
        $iloc = null;

        foreach ($this->walkChildren($meta) as $child) {
            if ($child->type === 'iinf') {
                $xmpItems += $this->parseIinfForXmp($child->win);
            } elseif ($child->type === 'iloc') {
                $iloc = $child->win;
            } elseif ($child->type === 'XMP ') {
                $out[] = $this->readBoxPayload($child->win);
            }
        }

        if ($iloc && $xmpItems) {
            foreach ($xmpItems as $itemId) {
                $blob = $this->resolveItemViaIloc($iloc, $itemId);
                if ($blob !== null) $out[] = $blob;
            }
        }
        return $out;
    }

    private function extractXmpFromMoov(StreamWindow $moov): array
    {
        return [];
    }

    private function parseIinfForXmp(StreamWindow $iinf): array
    {
        $iinf->seek(0);
        $iinf->read(4); // version/flags
        $entryCount = $iinf->readU16BE();
        $ids = [];
        $pos = $iinf->tell();
        for ($i = 0; $i < $entryCount; $i++) {
            foreach ($this->walkChildren($iinf, $pos) as $box) {
                if ($box->type !== 'infe') continue;
                $box->win->seek(0);
                $vf = $box->win->read(4);
                $itemId = $box->win->readU16BE();
                $box->win->read(2); // protection index
                $rest = $this->readBoxPayload($box->win);
                if (str_contains($rest, 'application/rdf+xml')) {
                    $ids[] = $itemId;
                }
                $pos += $box->size;
            }
        }
        return $ids;
    }

    private function resolveItemViaIloc(StreamWindow $iloc, int $targetItemId): ?string
    {
        $iloc->seek(0);
        $vf = $iloc->read(4); $version = ord($vf[0]);
        $sizes = ord($iloc->read(1)); $offsetSize = ($sizes >> 4) & 0x0F; $lengthSize = $sizes & 0x0F;
        $baseField = ord($iloc->read(1)); $baseOffsetSize = ($baseField >> 4) & 0x0F;
        $indexSize = ($version === 1 || $version === 2) ? (ord($iloc->read(1)) & 0x0F) : 0;
        $itemCount = $iloc->readU16BE();

        for ($i = 0; $i < $itemCount; $i++) {
            $itemId = ($version < 2) ? $iloc->readU16BE() : $this->readVar($iloc, 2);
            $constructionMethod = 0;
            if ($version >= 1) { $tmp = $iloc->readU16BE(); $constructionMethod = ($tmp >> 12) & 0x0F; }
            $dataRefIdx = $iloc->readU16BE();
            $baseOffset = $this->readVar($iloc, $baseOffsetSize ?: 0);
            $extentCount = $iloc->readU16BE();

            $payload = '';
            for ($e = 0; $e < $extentCount; $e++) {
                if ($indexSize) { $this->readVar($iloc, $indexSize); }
                $extentOffset = $this->readVar($iloc, $offsetSize);
                $extentLength = $this->readVar($iloc, $lengthSize);
                if ($itemId === $targetItemId && $dataRefIdx === 0 && $constructionMethod === 0 && $extentLength > 0) {
                    $abs = ($baseOffset ?: 0) + $extentOffset;
                    $win = $this->s->window($abs, $extentLength);
                    $payload .= $this->readBoxPayload($win);
                }
            }
            if ($itemId === $targetItemId && $payload !== '') return $payload;
        }
        return null;
    }
    /** @return \Generator<object> yields (type, size, win) objects for top-level boxes */
    private function walkTopLevel(): \Generator
    {
        $s = $this->s;
        $s->seek(0);
        $fileSize = $s->size();

        $pos = 0;
        while ($pos + 8 <= $fileSize) {
            $s->seek($pos);
            $size = $s->readU32BE();
            $type = $s->read(4);
            if ($size === 0) {
                // box extends to EOF
                $size = $fileSize - $pos;
            } elseif ($size === 1) {
                // largesize
                $size = $s->readU64BE();
            }
            if ($size < 8) {
                throw new ParseError("Invalid box size <$type>: $size");
            }
            $win = $s->window($pos + ($type === 'uuid' ? 24 : 8), $size - ($type === 'uuid' ? 24 : 8));
            yield (object)['type' => $type, 'size' => $size, 'win' => $win];
            $pos += $size;
        }
    }

    private function extractExifFromMeta(StreamWindow $meta): ?string
    {
        // meta box starts with FullBox header (version/flags) then nested boxes
        $meta->seek(0);
        if ($meta->size() < 4) return null;
        $meta->read(4); // version/flags

        // Strategy 1: direct 'Exif' child box under meta (common in HEIF)
        foreach ($this->walkChildren($meta) as $child) {
            if ($child->type === 'Exif') {
                $b = $this->readBoxPayload($child->win);
                // Some files include "Exif\0\0" prefix; normalize away if present
                if (str_starts_with($b, "Exif\0\0")) {
                    return substr($b, 6);
                }
                return $b;
            }
        }

        // Strategy 2: item-based ('iinf'/'iloc'): find an item of type 'Exif' and resolve location
        $infeById = []; // itemID => itemType
        $iloc = null;

        foreach ($this->walkChildren($meta) as $child) {
            if ($child->type === 'iinf') {
                $infeById += $this->parseIinf($child->win);
            } elseif ($child->type === 'iloc') {
                $iloc = $child->win;
            }
        }
        if ($iloc && $infeById) {
            $blob = $this->resolveExifViaIloc($iloc, $infeById, $meta);
            if ($blob !== null) {
                if (str_starts_with($blob, "Exif\0\0")) {
                    return substr($blob, 6);
                }
                return $blob;
            }
        }

        return null;
    }

    /** @return array<int, string> itemID => itemType */
    private function parseIinf(StreamWindow $iinf): array
    {
        $iinf->seek(0);
        $iinf->read(4); // version/flags
        $entryCount = $iinf->readU16BE();
        $out = [];
        for ($i = 0; $i < $entryCount; $i++) {
            foreach ($this->walkChildren($iinf) as $b) {
                if ($b->type === 'infe') {
                    $out += $this->parseInfe($b->win);
                }
            }
        }
        return $out;
    }

    /** Parse an 'infe' entry (ItemInfoEntry) to map itemID → itemType (e.g., 'Exif') */
    private function parseInfe(StreamWindow $infe): array
    {
        $infe->seek(0);
        $infe->read(4); // version/flags
        $version = ord($infe->read(1)); // re-read last byte? Simpler:
        $infe->seek(0);
        $vf = $infe->read(4);
        $version = ord($vf[0]);

        // version 2/3/4 formats exist; we handle v2+ minimally
        // v2: item_ID(2), item_protection_index(2), item_name, content_type, content_encoding
        // v3/4: item_ID(2/4), item_protection_index(2), item_type(4), item_name, content_type...
        $out = [];

        if ($version >= 2) {
            $itemId = $infe->readU16BE(); // ok for v2; for v3/4 it's 2 or 4, but we keep simple
            $prot   = $infe->readU16BE();
            // For v2 no explicit item_type; for v3/4 read type (4CC). We'll try both patterns:

            // Try to sniff an 'Exif' 4CC in the remaining window:
            $restWin = new StreamWindow($infe, $infe->tell(), $infe->size() - $infe->tell());
            // naive scan for 4CC 'Exif' in rest (cheap heuristic)
            $buf = $this->readBoxPayload($restWin);
            $pos = strpos($buf, "Exif");
            if ($pos !== false) {
                $out[$itemId] = 'Exif';
            }
        }
        return $out;
    }

    /** Resolve EXIF item data via 'iloc' (highly simplified, single extent) */
    private function resolveExifViaIloc(StreamWindow $iloc, array $infeById, StreamWindow $meta): ?string
    {
        $iloc->seek(0);
        $header = $iloc->read(4); // version/flags
        $version = ord($header[0]);

        // read offset/length size descriptors (4 bits each)
        $sizes = unpack('C', $iloc->read(1))[1];
        $offsetSize = ($sizes >> 4) & 0x0F;
        $lengthSize = $sizes & 0x0F;
        $baseOffsetSize = unpack('C', $iloc->read(1))[1] >> 4;
        $indexSize = 0;
        if ($version === 1 || $version === 2) {
            $indexSize = unpack('C', $iloc->read(1))[1] & 0x0F;
        }

        $itemCount = $iloc->readU16BE();
        for ($i = 0; $i < $itemCount; $i++) {
            $itemId = $version < 2 ? $iloc->readU16BE() : $this->readVar($iloc, 2); // keep simple
            // construction_method (v1+), data_reference_index, base_offset, extent_count, extents...
            // We drastically simplify: assume single extent, no base/data ref.
            if (!isset($infeById[$itemId]) || $infeById[$itemId] !== 'Exif') {
                // skip this entry rudimentarily (not production-grade)
                continue;
            }
            // Skip fields until we reach extents (heuristic for PoC)
            // In practice you'd parse exactly by spec. For now, we try to find first extent pair.
            // ...
            // To keep this initial version pragmatic, we bail out to Strategy 1 unless files are simple.
            // Return null and rely on direct 'Exif' box when unsure.
            return null;
        }
        return null;
    }

    /** QuickTime keys under moov/udta/meta (keys/ilst) */
    private function readQuickTimeFromMoov(StreamWindow $moov): array
    {
        $keys = [];
        foreach ($this->walkChildren($moov) as $udta) {
            if ($udta->type !== 'udta') continue;
            foreach ($this->walkChildren($udta->win) as $meta) {
                if ($meta->type !== 'meta') continue;
                $keys += $this->readQuickTimeKeysUnderMeta($meta->win);
            }
        }
        return $keys;
    }

    /** Parse QuickTime meta fullbox: keys + ilst (common layout) */
    private function readQuickTimeKeysUnderMeta(StreamWindow $meta): array
    {
        $out = [];
        $meta->seek(0);
        if ($meta->size() < 4) return $out;
        $meta->read(4); // version/flags
        $keyIndex = [];

        foreach ($this->walkChildren($meta) as $child) {
            if ($child->type === 'keys') {
                $keyIndex = $this->parseKeys($child->win); // idx => name
            }
        }
        foreach ($this->walkChildren($meta) as $child) {
            if ($child->type === 'ilst') {
                $out += $this->parseIlst($child->win, $keyIndex);
            }
        }
        return $out;
    }

    /** keys box: list of key names (1-based indexing) */
    private function parseKeys(StreamWindow $keys): array
    {
        $keys->seek(0);
        $keys->read(4); // version/flags
        $entryCount = $keys->readU32BE();
        $out = [];
        $pos = $keys->tell();
        for ($i = 1; $i <= $entryCount && $pos + 8 <= $keys->size(); $i++) {
            $keys->seek($pos);
            $size = $keys->readU32BE();
            $namespace = $keys->read(4); // 'mdta' etc.
            $nameLen = $size - 8;
            if ($nameLen < 0 || $pos + $size > $keys->size()) {
                break;
            }
            $name = $keys->read($nameLen);
            $out[$i] = $name;
            $pos += $size;
        }
        return $out;
    }

    /** ilst box: entries keyed by key index (atom type is index) → 'data' subbox value */
    private function parseIlst(StreamWindow $ilst, array $keyIndex): array
    {
        $out = [];
        foreach ($this->walkChildren($ilst) as $entry) {
            // atom type is 4 bytes; for mdta-keys Apple uses numeric indexes packed as int? In MOV it's often the key name 4CC; in mdta it's index.
            $keyId = $this->fourccToIndex($entry->type);
            if ($keyId !== null && isset($keyIndex[$keyId])) {
                $keyName = $keyIndex[$keyId];
                // find 'data' box inside entry
                foreach ($this->walkChildren($entry->win) as $sub) {
                    if ($sub->type === 'data') {
                        $val = $this->parseDataBox($sub->win);
                        if ($val !== null) {
                            $out[$keyName] = $val;
                        }
                    }
                }
            }
        }
        return $out;
    }

    private function parseDataBox(StreamWindow $data): mixed
    {
        // data box: 4 bytes type/flags(?), then value
        $data->seek(0);
        if ($data->size() < 8) return null;
        $type = $data->readU32BE(); // data type indicator (1 = UTF-8 string, etc.)
        $locale = $data->readU32BE();
        $payload = $this->readBoxPayload($data);
        // minimal: treat as UTF-8 string
        $str = trim($payload, "\0");
        if ($str !== '') return $str;
        return $payload;
    }

    /** Child walker for any container box window */
    private function walkChildren(StreamWindow $win): \Generator
    {
        $pos = $win->tell();
        while ($pos + 8 <= $win->size()) {
            $win->seek($pos);
            $size = $win->readU32BE();
            $type = $win->read(4);
            if ($size === 0) {
                $size = $win->size() - $pos;
            } elseif ($size === 1) {
                $size = $win->readU64BE();
            }
            if ($size < 8 || $pos + $size > $win->size()) {
                break;
            }
            $child = (object)[
                'type' => $type,
                'size' => $size,
                'win'  => new StreamWindow($win, $pos + 8, $size - 8),
            ];
            yield $child;
            $pos += $size;
        }
    }

    private function readBoxPayload(StreamWindow $box): string
    {
        $box->seek(0);
        return $box->read($box->size());
    }

    private function fourccToIndex(string $fourcc): ?int
    {
        // interpret 4CC as big-endian 32-bit int (used by mdta key indexing)
        if (strlen($fourcc) !== 4) return null;
        $v = unpack('N', $fourcc)[1];
        return $v > 0 ? $v : null;
    }

    private function readVar(StreamWindow $w, int $bytes): int
    {
        return match ($bytes) {
            1 => $w->readU8(),
            2 => $w->readU16BE(),
            4 => $w->readU32BE(),
            8 => $w->readU64BE(),
            default => throw new ParseError("unsupported var size $bytes"),
        };
    }
}
