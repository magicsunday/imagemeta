<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Parse\ParserLimits;

use function mb_check_encoding;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function substr;

/**
 * Resolves QuickTime metadata key namespaces and free-form key structures.
 *
 * @phpstan-type QuickTimeKeyEntry = array{namespace: string, name: string}
 */
final readonly class QuickTimeKeyResolver
{
    /**
     * QuickTime 'mdta' FourCC identifying the metadata type scheme.
     *
     * Used as (1) the handler reference type in the metadata hdlr box
     * (QuickTime File Format 2012, "Metadata Atom") and (2) the key namespace
     * in the keys atom ("Metadata item keys atom"), where the key_value is a
     * UTF-8 reverse-DNS string (e.g. 'com.apple.quicktime.content.identifier').
     */
    public const string QUICKTIME_MDTA = 'mdta';

    /**
     * FourCC for QuickTime mean payload in free-form metadata.
     */
    private const string FREEFORM_MEAN = 'mean';

    /**
     * FourCC for QuickTime name payload in free-form metadata.
     */
    private const string FREEFORM_NAME = 'name';

    /**
     * @param BoxNavigator $boxNavigator Shared box navigation infrastructure.
     */
    public function __construct(
        private BoxNavigator $boxNavigator,
    ) {
    }

    /**
     * Parses the QuickTime metadata keys atom.
     *
     * QuickTime File Format 2012, "Metadata item keys atom": the keys atom contains
     * a 4-byte key_namespace and a variable-length key_value whose structure depends
     * on the namespace.
     *
     * @param BoxDescriptor $keys Box descriptor for the QuickTime `keys` box.
     *
     * @return array<int, QuickTimeKeyEntry>
     */
    public function parseKeys(BoxDescriptor $keys): array
    {
        $win = $keys->window;
        $win->seek(0);

        if ($keys->contentSize < 8) {
            throw new ParseError('keys box truncated', 1221);
        }

        // QuickTime File Format 2012, "Metadata item keys atom": the keys atom
        // is a FullAtom; version must be 0 and flags must be 0 for the defined
        // structure.
        $version = $win->readU8();
        $flags   = $this->boxNavigator->readUInt24($win);

        if (($version !== 0) || ($flags !== 0)) {
            throw new ParseError('keys box version/flags must be 0', 1222);
        }

        $entryCount = $win->readU32BE();

        if ($entryCount > ParserLimits::MAX_KEYS_ENTRIES) {
            throw new ParseError('keys entry count exceeds maximum allowed', 1223);
        }

        $map = [];
        $pos = $win->tell();

        for ($i = 1; $i <= $entryCount; ++$i) {
            if ($pos + 8 > $keys->contentSize) {
                throw new ParseError('keys entry truncated', 1224);
            }

            $win->seek($pos);
            $size      = $win->readU32BE();
            $namespace = $win->read(4);
            if (($size < 8) || (($pos + $size) > $keys->contentSize)) {
                throw new ParseError('invalid keys entry size', 1225);
            }

            // Validate key_namespace as proper 4CC code (printable ASCII)
            if (preg_match('/^[\x20-\x7E]{4}$/', $namespace) !== 1) {
                throw new ParseError('keys entry key_namespace is not a valid 4CC code', 1416);
            }

            // Reject empty key_value entries (size <= 8 means no actual key data)
            if ($size <= 8) {
                throw new ParseError('keys entry has empty key_value', 1417);
            }

            $name = $win->read($size - 8);

            if ($namespace === self::QUICKTIME_MDTA) {
                // QuickTime File Format 2012, "Metadata item keys atom": mdta key_value is
                // a NUL-terminated UTF-8 string; strip terminator before storing key names.
                if (!str_ends_with($name, "\0")) {
                    throw new ParseError('keys mdta key_value missing NUL terminator', 1455);
                }

                $name = substr($name, 0, -1);

                if ($name === '') {
                    throw new ParseError('keys mdta key_value is empty after NUL terminator removal', 1456);
                }

                if (str_contains($name, "\0")) {
                    throw new ParseError('keys mdta key_value contains embedded NUL bytes', 1457);
                }

                if (!mb_check_encoding($name, 'UTF-8')) {
                    throw new ParseError('keys mdta key_value contains invalid UTF-8', 1385);
                }
            }

            $map[$i] = [
                'namespace' => $namespace,
                'name'      => $name,
            ];
            $pos += $size;
        }

        if ($pos !== $keys->contentSize) {
            throw new ParseError('keys entries do not fill container', 1226);
        }

        return $map;
    }

    /**
     * Parses a free-form metadata key (----) into a dotted namespace string.
     *
     * @param BoxDescriptor $entry Box descriptor representing the free-form entry.
     */
    public function parseFreeformKey(BoxDescriptor $entry): ?string
    {
        $mean = null;
        $name = null;
        foreach ($this->boxNavigator->walkChildren($entry) as $child) {
            if ($child->type === self::FREEFORM_MEAN) {
                $mean = $this->parseFreeformMean($child);
            } elseif ($child->type === self::FREEFORM_NAME) {
                $name = $this->parseFreeformName($child);
            }
        }

        if ($mean === null || $mean === '' || $name === null || $name === '') {
            return null;
        }

        return $mean . '.' . $name;
    }

    /**
     * Resolves a structured key entry into a string key name for the QuickTimeKeyMap.
     *
     * QuickTime File Format 2012, "Metadata item keys atom": for the 'mdta' namespace
     * the key_value is a reverse-DNS string used directly. For other namespaces the key
     * is prefixed with the 4-byte namespace to prevent collisions between naming schemes.
     *
     * @param QuickTimeKeyEntry $entry Structured key entry with namespace and name.
     */
    public function resolveKeyName(array $entry): string
    {
        if ($entry['namespace'] === self::QUICKTIME_MDTA) {
            return $entry['name'];
        }

        return $entry['namespace'] . ':' . $entry['name'];
    }

    /**
     * Parses a free-form metadata Mean atom as a FullAtom.
     *
     * QuickTime File Format 2012: the mean atom is a FullAtom with version 0
     * and flags 0. The remaining payload is a UTF-8 namespace string.
     *
     * @param BoxDescriptor $mean Box descriptor for the mean atom.
     *
     * @return string The decoded namespace string.
     */
    private function parseFreeformMean(BoxDescriptor $mean): string
    {
        if ($mean->contentSize < 4) {
            throw new ParseError('mean atom truncated', 1429);
        }

        $win = $mean->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = $this->boxNavigator->readUInt24($win);

        if ($version !== 0) {
            throw new ParseError('mean atom version must be 0', 1430);
        }

        if ($flags !== 0) {
            throw new ParseError('mean atom flags must be 0', 1431);
        }

        $payloadSize = $mean->contentSize - 4;
        if ($payloadSize < 1) {
            throw new ParseError('mean atom has empty payload', 1432);
        }

        $value = $win->read($payloadSize);

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new ParseError('mean atom contains invalid UTF-8', 1433);
        }

        return $value;
    }

    /**
     * Parses a free-form metadata Name atom as a FullAtom.
     *
     * QuickTime File Format 2012: the name atom is a FullAtom with version 0
     * and flags 0. The remaining payload is a UTF-8 key name string.
     *
     * @param BoxDescriptor $name Box descriptor for the name atom.
     *
     * @return string The decoded key name string.
     */
    private function parseFreeformName(BoxDescriptor $name): string
    {
        if ($name->contentSize < 4) {
            throw new ParseError('name atom truncated', 1434);
        }

        $win = $name->window;
        $win->seek(0);

        $version = $win->readU8();
        $flags   = $this->boxNavigator->readUInt24($win);

        if ($version !== 0) {
            throw new ParseError('name atom version must be 0', 1435);
        }

        if ($flags !== 0) {
            throw new ParseError('name atom flags must be 0', 1436);
        }

        $payloadSize = $name->contentSize - 4;
        if ($payloadSize < 1) {
            throw new ParseError('name atom has empty payload', 1437);
        }

        $value = $win->read($payloadSize);

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new ParseError('name atom contains invalid UTF-8', 1438);
        }

        return $value;
    }
}
