<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\StreamWindow;
use MagicSunday\ImageMeta\Core\Traits\NormalizesOffsets;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxDescriptor;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;
use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeKeyResolver;
use MagicSunday\ImageMeta\Tests\Helpers\IsoBmffBoxTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function chr;
use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function strlen;

/**
 * Tests for QuickTimeKeyResolver covering parseKeys, parseFreeformKey,
 * and resolveKeyName methods.
 * Validates both correct key extraction and error paths for malformed data.
 */
#[CoversClass(QuickTimeKeyResolver::class)]
#[UsesClass(ParseError::class)]
#[UsesClass(Stream::class)]
#[UsesClass(StreamWindow::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(BoxNavigator::class)]
#[UsesClass(BoxDescriptor::class)]
#[UsesTrait(NormalizesOffsets::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
#[UsesTrait(IsoBmffBoxTrait::class)]
final class QuickTimeKeyResolverTest extends TestCase
{
    use IsoBmffBoxTrait;

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Creates a QuickTimeKeyResolver with its BoxNavigator wrapping the given binary data.
     * Returns the resolver plus a BoxDescriptor for the content region.
     *
     * @return array{0: QuickTimeKeyResolver, 1: BoxDescriptor}
     */
    private function createResolverWithDescriptor(string $content): array
    {
        $handle = fopen('php://temp', 'wb+');
        if ($handle === false) {
            self::fail('Unable to create temporary stream handle.');
        }

        fwrite($handle, $content);
        rewind($handle);

        $stream    = new Stream($handle, strlen($content));
        $navigator = new BoxNavigator($stream);
        $resolver  = new QuickTimeKeyResolver($navigator);
        $window    = $stream->window(0, strlen($content));

        $descriptor = new BoxDescriptor(
            type: 'keys',
            size: 8 + strlen($content),
            offset: 0,
            contentOffset: 0,
            contentSize: strlen($content),
            window: $window,
            userType: null,
        );

        return [$resolver, $descriptor];
    }

    /**
     * Builds a keys box payload with a single mdta key entry.
     */
    private function buildKeysPayloadWithMdtaKey(string $keyName): string
    {
        // version=0, flags=0
        $data = chr(0) . chr(0) . chr(0) . chr(0);
        // entry_count=1
        $data .= pack('N', 1);
        // entry: size(4) + namespace(4) + key_value (NUL-terminated for mdta)
        $keyValue  = $keyName . "\0";
        $entrySize = 8 + strlen($keyValue);

        return $data . (pack('N', $entrySize) . 'mdta' . $keyValue);
    }

    /**
     * Builds a keys box payload with a single non-mdta key entry.
     */
    private function buildKeysPayloadWithCustomNamespace(string $namespace, string $keyName): string
    {
        // version=0, flags=0
        $data = chr(0) . chr(0) . chr(0) . chr(0);
        // entry_count=1
        $data .= pack('N', 1);
        // entry: size(4) + namespace(4) + key_value
        $entrySize = 8 + strlen($keyName);

        return $data . (pack('N', $entrySize) . $namespace . $keyName);
    }

    // =========================================================================
    // parseKeys — positive tests
    // =========================================================================

    /**
     * Parses a keys box with a single mdta key entry.
     */
    #[Test]
    public function parseKeysSingleMdtaEntry(): void
    {
        $keyName = 'com.apple.quicktime.title';
        $payload = $this->buildKeysPayloadWithMdtaKey($keyName);

        [$resolver, $descriptor] = $this->createResolverWithDescriptor($payload);
        $map                     = $resolver->parseKeys($descriptor);

        self::assertArrayHasKey(1, $map);
        self::assertSame('mdta', $map[1]['namespace']);
        self::assertSame($keyName, $map[1]['name']);
    }

    /**
     * Parses a keys box with multiple entries.
     */
    #[Test]
    public function parseKeysMultipleEntries(): void
    {
        $key1 = 'com.apple.quicktime.title';
        $key2 = 'com.apple.quicktime.model';

        // version=0, flags=0
        $data = chr(0) . chr(0) . chr(0) . chr(0);
        // entry_count=2
        $data .= pack('N', 2);

        // entry 1
        $kv1       = $key1 . "\0";
        $entrySize = 8 + strlen($kv1);
        $data .= pack('N', $entrySize) . 'mdta' . $kv1;

        // entry 2
        $kv2       = $key2 . "\0";
        $entrySize = 8 + strlen($kv2);
        $data .= pack('N', $entrySize) . 'mdta' . $kv2;

        [$resolver, $descriptor] = $this->createResolverWithDescriptor($data);
        $map                     = $resolver->parseKeys($descriptor);

        self::assertCount(2, $map);
        self::assertSame($key1, $map[1]['name']);
        self::assertSame($key2, $map[2]['name']);
    }

    /**
     * Parses a keys box with a non-mdta namespace entry.
     */
    #[Test]
    public function parseKeysNonMdtaNamespace(): void
    {
        $payload = $this->buildKeysPayloadWithCustomNamespace('itun', 'Artist');

        [$resolver, $descriptor] = $this->createResolverWithDescriptor($payload);
        $map                     = $resolver->parseKeys($descriptor);

        self::assertArrayHasKey(1, $map);
        self::assertSame('itun', $map[1]['namespace']);
        self::assertSame('Artist', $map[1]['name']);
    }

    // =========================================================================
    // parseKeys — negative tests
    // =========================================================================

    /**
     * Rejects a truncated keys box.
     */
    #[Test]
    public function parseKeysRejectsTruncated(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('keys box truncated');

        // Only 7 bytes (needs at least 8)
        $content = chr(0) . chr(0) . chr(0) . chr(0) . chr(0) . chr(0) . chr(0);

        [$resolver, $descriptor] = $this->createResolverWithDescriptor($content);
        $resolver->parseKeys($descriptor);
    }

    /**
     * Rejects a keys box with non-zero version.
     */
    #[Test]
    public function parseKeysRejectsNonZeroVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('keys box version/flags must be 0');

        $content = chr(1) . chr(0) . chr(0) . chr(0) . pack('N', 0);

        [$resolver, $descriptor] = $this->createResolverWithDescriptor($content);
        $resolver->parseKeys($descriptor);
    }

    /**
     * Rejects a keys box with non-zero flags.
     */
    #[Test]
    public function parseKeysRejectsNonZeroFlags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('keys box version/flags must be 0');

        $content = chr(0) . chr(0) . chr(0) . chr(1) . pack('N', 0);

        [$resolver, $descriptor] = $this->createResolverWithDescriptor($content);
        $resolver->parseKeys($descriptor);
    }

    /**
     * Rejects a keys entry with an invalid (too small) size.
     */
    #[Test]
    public function parseKeysRejectsInvalidEntrySize(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('invalid keys entry size');

        // version=0, flags=0, entry_count=1
        $data = chr(0) . chr(0) . chr(0) . chr(0) . pack('N', 1);
        // entry with size=4 (too small, minimum is 8)
        $data .= pack('N', 4) . 'mdta';

        [$resolver, $descriptor] = $this->createResolverWithDescriptor($data);
        $resolver->parseKeys($descriptor);
    }

    /**
     * Rejects a keys entry with empty key_value (size==8).
     */
    #[Test]
    public function parseKeysRejectsEmptyKeyValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('keys entry has empty key_value');

        // version=0, flags=0, entry_count=1
        $data = chr(0) . chr(0) . chr(0) . chr(0) . pack('N', 1);
        // entry with size=8 (exactly header, no key_value)
        $data .= pack('N', 8) . 'mdta';

        [$resolver, $descriptor] = $this->createResolverWithDescriptor($data);
        $resolver->parseKeys($descriptor);
    }

    /**
     * Rejects mdta key_value missing NUL terminator.
     */
    #[Test]
    public function parseKeysRejectsMdtaMissingNulTerminator(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('keys mdta key_value missing NUL terminator');

        // version=0, flags=0, entry_count=1
        $data = chr(0) . chr(0) . chr(0) . chr(0) . pack('N', 1);
        // mdta key without NUL terminator
        $keyValue  = 'com.apple.quicktime.title';
        $entrySize = 8 + strlen($keyValue);
        $data .= pack('N', $entrySize) . 'mdta' . $keyValue;

        [$resolver, $descriptor] = $this->createResolverWithDescriptor($data);
        $resolver->parseKeys($descriptor);
    }

    /**
     * Rejects entries that do not fill the container exactly.
     */
    #[Test]
    public function parseKeysRejectsEntriesNotFillingContainer(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('keys entries do not fill container');

        $keyName = 'com.apple.quicktime.title';
        $payload = $this->buildKeysPayloadWithMdtaKey($keyName);
        // Append extra bytes that make entries not fill the container
        $payload .= "\x00\x00";

        [$resolver, $descriptor] = $this->createResolverWithDescriptor($payload);
        $resolver->parseKeys($descriptor);
    }

    // =========================================================================
    // resolveKeyName — positive tests
    // =========================================================================

    /**
     * Returns the key name directly for mdta namespace.
     */
    #[Test]
    public function resolveKeyNameReturnsMdtaKeyDirectly(): void
    {
        [$resolver, $_] = $this->createResolverWithDescriptor('X');

        $result = $resolver->resolveKeyName([
            'namespace' => 'mdta',
            'name'      => 'com.apple.quicktime.title',
        ]);

        self::assertSame('com.apple.quicktime.title', $result);
    }

    /**
     * Prefixes non-mdta keys with the namespace and colon.
     */
    #[Test]
    public function resolveKeyNamePrefixesNonMdtaNamespace(): void
    {
        [$resolver, $_] = $this->createResolverWithDescriptor('X');

        $result = $resolver->resolveKeyName([
            'namespace' => 'itun',
            'name'      => 'Artist',
        ]);

        self::assertSame('itun:Artist', $result);
    }

    // =========================================================================
    // parseFreeformKey — positive test
    // =========================================================================

    /**
     * Parses a free-form entry with mean and name atoms into a dotted key.
     */
    #[Test]
    public function parseFreeformKeyReturnsDottedKey(): void
    {
        // mean: FullAtom (v0, flags=0) + payload
        $meanPayload = chr(0) . chr(0) . chr(0) . chr(0) . 'com.apple.iTunes';
        $meanBox     = $this->box('mean', $meanPayload);

        // name: FullAtom (v0, flags=0) + payload
        $namePayload = chr(0) . chr(0) . chr(0) . chr(0) . 'ISRC';
        $nameBox     = $this->box('name', $namePayload);

        $entryContent = $meanBox . $nameBox;

        $handle = fopen('php://temp', 'wb+');
        if ($handle === false) {
            self::fail('Unable to create temporary stream handle.');
        }

        fwrite($handle, $entryContent);
        rewind($handle);

        $stream    = new Stream($handle, strlen($entryContent));
        $navigator = new BoxNavigator($stream);
        $resolver  = new QuickTimeKeyResolver($navigator);
        $window    = $stream->window(0, strlen($entryContent));

        $descriptor = new BoxDescriptor(
            type: '----',
            size: 8 + strlen($entryContent),
            offset: 0,
            contentOffset: 0,
            contentSize: strlen($entryContent),
            window: $window,
            userType: null,
        );

        $key = $resolver->parseFreeformKey($descriptor);

        self::assertSame('com.apple.iTunes.ISRC', $key);
    }

    // =========================================================================
    // parseFreeformKey — negative test
    // =========================================================================

    /**
     * Returns null when mean is missing from a free-form entry.
     */
    #[Test]
    public function parseFreeformKeyReturnsNullWithoutMean(): void
    {
        // Only a name box, no mean box
        $namePayload = chr(0) . chr(0) . chr(0) . chr(0) . 'ISRC';
        $nameBox     = $this->box('name', $namePayload);

        $handle = fopen('php://temp', 'wb+');
        if ($handle === false) {
            self::fail('Unable to create temporary stream handle.');
        }

        fwrite($handle, $nameBox);
        rewind($handle);

        $stream    = new Stream($handle, strlen($nameBox));
        $navigator = new BoxNavigator($stream);
        $resolver  = new QuickTimeKeyResolver($navigator);
        $window    = $stream->window(0, strlen($nameBox));

        $descriptor = new BoxDescriptor(
            type: '----',
            size: 8 + strlen($nameBox),
            offset: 0,
            contentOffset: 0,
            contentSize: strlen($nameBox),
            window: $window,
            userType: null,
        );

        self::assertNull($resolver->parseFreeformKey($descriptor));
    }

    /**
     * Rejects a mean atom with a non-zero version.
     */
    #[Test]
    public function parseFreeformKeyRejectsNonZeroMeanVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('mean atom version must be 0');

        // mean: FullAtom (v=1, flags=0) + payload
        $meanPayload = chr(1) . chr(0) . chr(0) . chr(0) . 'com.apple.iTunes';
        $meanBox     = $this->box('mean', $meanPayload);

        $namePayload = chr(0) . chr(0) . chr(0) . chr(0) . 'ISRC';
        $nameBox     = $this->box('name', $namePayload);

        $entryContent = $meanBox . $nameBox;

        $handle = fopen('php://temp', 'wb+');
        if ($handle === false) {
            self::fail('Unable to create temporary stream handle.');
        }

        fwrite($handle, $entryContent);
        rewind($handle);

        $stream    = new Stream($handle, strlen($entryContent));
        $navigator = new BoxNavigator($stream);
        $resolver  = new QuickTimeKeyResolver($navigator);
        $window    = $stream->window(0, strlen($entryContent));

        $descriptor = new BoxDescriptor(
            type: '----',
            size: 8 + strlen($entryContent),
            offset: 0,
            contentOffset: 0,
            contentSize: strlen($entryContent),
            window: $window,
            userType: null,
        );

        $resolver->parseFreeformKey($descriptor);
    }
}
