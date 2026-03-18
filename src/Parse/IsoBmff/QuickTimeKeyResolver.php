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
 * Resolves QuickTime metadata key namespaces and free-form key structures
 * as described in QuickTime File Format 2012 §4.
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
     * QuickTime 'mdir' FourCC identifying the iTunes-style metadata handler.
     *
     * Used as the handler reference type in the metadata hdlr box for
     * containers produced by ffmpeg, Premiere, HandBrake, OBS, and most
     * non-Apple video tools. The ilst entries use direct fourcc keys
     * (e.g. ©nam, ©ART) instead of a keys→index mapping.
     */
    public const string QUICKTIME_MDIR = 'mdir';

    /**
     * FourCC for QuickTime mean payload in free-form metadata.
     */
    private const string FREEFORM_MEAN = 'mean';

    /**
     * FourCC for QuickTime name payload in free-form metadata.
     */
    private const string FREEFORM_NAME = 'name';

    /**
     * Maps iTunes-style fourcc codes to canonical QuickTime metadata key names.
     *
     * When the handler type is 'mdir', ilst entries use direct fourcc keys
     * instead of 1-based indices into a keys table. These fourchars map to
     * the same reverse-DNS key names used by the 'mdta' scheme.
     *
     * @var array<string, string>
     */
    private const array MDIR_KEY_MAP = [
        "\xA9nam" => 'com.apple.quicktime.title',
        "\xA9ART" => 'com.apple.quicktime.artist',
        "\xA9alb" => 'com.apple.quicktime.album',
        "\xA9cmt" => 'com.apple.quicktime.comment',
        "\xA9day" => 'com.apple.quicktime.creationDate',
        "\xA9gen" => 'com.apple.quicktime.genre',
        "\xA9too" => 'com.apple.quicktime.software',
        "\xA9wrt" => 'com.apple.quicktime.author',
        'cprt'    => 'com.apple.quicktime.copyright',
        'desc'    => 'com.apple.quicktime.description',
    ];

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
        $header = $this->boxNavigator->readFullBoxHeader($win);

        if (($header->version !== 0) || ($header->flags !== 0)) {
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
                throw new ParseError('keys entry key_namespace is not a valid 4CC code', 1909);
            }

            // Reject empty key_value entries (size <= 8 means no actual key data)
            if ($size <= 8) {
                throw new ParseError('keys entry has empty key_value', 1910);
            }

            $name = $win->read($size - 8);

            if ($namespace === self::QUICKTIME_MDTA) {
                // QuickTime File Format 2012, "Metadata Item Keys Atom" defines
                // key_value as uint8[Key_size-8] containing the key name bytes.
                // Some writers append a trailing NUL; tolerate and strip exactly one.
                if (str_ends_with($name, "\0")) {
                    $name = substr($name, 0, -1);
                }

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
     * Resolves an iTunes-style fourcc code to its canonical QuickTime key name.
     *
     * @param string $fourcc Four-character code from an mdir ilst entry.
     *
     * @return string|null Canonical key name, or null when the fourcc is unknown.
     */
    public function resolveMdirKey(string $fourcc): ?string
    {
        return self::MDIR_KEY_MAP[$fourcc] ?? null;
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
        return $this->parseFreeformAtomPayload($mean, 'mean');
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
        return $this->parseFreeformAtomPayload($name, 'name');
    }

    /**
     * Parses a free-form metadata atom (mean or name) as a FullAtom.
     *
     * QuickTime File Format 2012: both the mean and name atoms are FullAtoms
     * with version 0 and flags 0. The remaining payload is a UTF-8 string.
     *
     * @param BoxDescriptor $atom  Box descriptor for the atom.
     * @param string        $label Human-readable atom label for error messages.
     *
     * @return string The decoded payload string.
     */
    private function parseFreeformAtomPayload(BoxDescriptor $atom, string $label): string
    {
        if ($atom->contentSize < 4) {
            throw new ParseError($label . ' atom truncated', 1429);
        }

        $win = $atom->window;
        $win->seek(0);

        $header = $this->boxNavigator->readFullBoxHeader($win);

        if ($header->version !== 0) {
            throw new ParseError($label . ' atom version must be 0', 1430);
        }

        if ($header->flags !== 0) {
            throw new ParseError($label . ' atom flags must be 0', 1431);
        }

        $payloadSize = $atom->contentSize - 4;

        if ($payloadSize < 1) {
            throw new ParseError($label . ' atom has empty payload', 1432);
        }

        $value = $win->read($payloadSize);

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new ParseError($label . ' atom contains invalid UTF-8', 1433);
        }

        return $value;
    }
}
