<?php
declare(strict_types=1);


namespace MagicSunday\ImageMeta\Model\Xmp;


final class XmpDocument
{
    /** @param array<string, array<string, mixed>> $data nsUri => [prop => value] */
    public function __construct(public readonly array $data) {}


    /** Convenient flat lookup: nsUri + prop */
    public function get(string $nsUri, string $prop): mixed
    {
        return $this->data[$nsUri][$prop] ?? null;
    }


    /** Namespace‑agnostic helper (first match by local name) */
    public function find(string $localName): mixed
    {
        foreach ($this->data as $ns => $props) {
            foreach ($props as $k => $v) {
                if (str_contains($k, ':')) {
                    [$prefix, $ln] = explode(':', $k, 2);
                    if ($ln === $localName) return $v;
                }
            }
        }
        return null;
    }
}
