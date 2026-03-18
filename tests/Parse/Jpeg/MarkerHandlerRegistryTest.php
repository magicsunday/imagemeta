<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use Closure;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Model\Jpeg\Marker;
use MagicSunday\ImageMeta\Parse\Jpeg\MarkerHandlerInterface;
use MagicSunday\ImageMeta\Parse\Jpeg\MarkerHandlerRegistry;
use MagicSunday\ImageMeta\Tests\Core\CreatesTempStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers dispatch behavior and dynamic registration for marker handlers.
 */
#[CoversClass(MarkerHandlerRegistry::class)]
final class MarkerHandlerRegistryTest extends TestCase
{
    use CreatesTempStream;

    /**
     * Dispatches only handlers whose marker predicate matches.
     */
    #[Test]
    public function dispatchesOnlyMatchingHandlers(): void
    {
        $stream      = new Stream($this->createTempStream('JPEG'), 4);
        $handled     = 0;

        $app1Handler = new readonly class(function () use (&$handled): void {
            ++$handled;
        }) implements MarkerHandlerInterface {
            /** @param Closure():void $onHandle */
            public function __construct(private Closure $onHandle)
            {
            }

            public function canHandle(int $marker): bool
            {
                return $marker === Marker::APP1;
            }

            public function handle(Stream $stream, string $payload, int $offset): void
            {
                ($this->onHandle)();
            }
        };

        $app2Handler = new readonly class(function () use (&$handled): void {
            ++$handled;
        }) implements MarkerHandlerInterface {
            /** @param Closure():void $onHandle */
            public function __construct(private Closure $onHandle)
            {
            }

            public function canHandle(int $marker): bool
            {
                return $marker === Marker::APP2;
            }

            public function handle(Stream $stream, string $payload, int $offset): void
            {
                ($this->onHandle)();
            }
        };

        $registry    = new MarkerHandlerRegistry([$app1Handler, $app2Handler]);

        $registry->dispatch(Marker::APP2, $stream, 'payload', 12);

        self::assertSame(1, $handled);
        self::assertTrue($registry->supports(Marker::APP1));
        self::assertTrue($registry->supports(Marker::APP2));
        self::assertFalse($registry->supports(Marker::APP13));
    }

    /**
     * Allows registering handlers after construction.
     */
    #[Test]
    public function supportsDynamicHandlerRegistration(): void
    {
        $stream   = new Stream($this->createTempStream('JPEG'), 4);
        $payloads = [];

        $registry = new MarkerHandlerRegistry();
        $registry->register(new readonly class(function (string $payload) use (&$payloads): void {
            $payloads[] = $payload;
        }) implements MarkerHandlerInterface {
            /** @param Closure(string):void $onHandle */
            public function __construct(private Closure $onHandle)
            {
            }

            public function canHandle(int $marker): bool
            {
                return $marker === Marker::APP13;
            }

            public function handle(Stream $stream, string $payload, int $offset): void
            {
                ($this->onHandle)($payload . '@' . $offset);
            }
        });

        $registry->dispatch(Marker::APP13, $stream, 'iptc', 77);

        self::assertSame(['iptc@77'], $payloads);
    }
}
