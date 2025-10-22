<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;

final class JpegExtractor
{
    public function __construct(private readonly Stream $s) {}

    /** @return list<string> XMP packets as XML strings */
    public function extractXmpPackets(): array
    {
        $s = $this->s;
        $s->seek(0);
        if ($s->read(2) !== "\xFF\xD8") {
            throw new ParseError('Not a JPEG (missing SOI)');
        }
        $xmps = [];
        while (true) {
            $marker = $this->nextMarker();
            if ($marker === 0xD9) break; // EOI
            if (in_array($marker, [0x01, 0xD0,0xD1,0xD2,0xD3,0xD4,0xD5,0xD6,0xD7], true)) continue;
            $len = $s->readU16BE();
            if ($len < 2) throw new ParseError('Invalid segment length');
            $payload = $s->read($len - 2);
            if ($marker === 0xE1) {
                $xmpSig = "http://ns.adobe.com/xap/1.0/\0";
                if (str_starts_with($payload, $xmpSig)) {
                    $xmps[] = substr($payload, strlen($xmpSig));
                }
            }
        }
        return $xmps;
    }

    private function nextMarker(): int
    {
        $s = $this->s;
        do { $b = $s->read(1); } while ($b !== "\xFF");
        do { $b = $s->read(1); } while ($b === "\xFF");
        return ord($b);
    }
}
