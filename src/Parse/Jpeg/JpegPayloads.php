<?php
declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

final class JpegPayloads
{
    /** @var list<string> */
    private array $icc = [];

    /** @var list<string> */
    private array $iptc = [];

    public function addIccPayload(string $payload): void
    {
        $this->icc[] = $payload;
    }

    /** @return list<string> */
    public function getIccPayloads(): array
    {
        return $this->icc;
    }

    public function addIptcPayload(string $payload): void
    {
        $this->iptc[] = $payload;
    }

    /** @return list<string> */
    public function getIptcPayloads(): array
    {
        return $this->iptc;
    }
}
