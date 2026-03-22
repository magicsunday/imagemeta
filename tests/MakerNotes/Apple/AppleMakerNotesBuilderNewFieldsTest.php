<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleCaptureIdentity;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleDictionaryValueExtractor;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleFlagExtractor;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleHdr;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesBuilder;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMaps;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleRationalNormalizer;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_map;

/**
 * Exercises AppleMakerNotesBuilder decoding of the MediaGroupUUID field.
 * Validates that the newly supported maker note field is extracted into the identity group.
 *
 * @internal
 */
#[CoversClass(AppleMakerNotesBuilder::class)]
#[UsesClass(AppleCaptureIdentity::class)]
#[UsesClass(AppleDictionaryValueExtractor::class)]
#[UsesClass(AppleFlagExtractor::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(AppleMaps::class)]
#[UsesClass(AppleRationalNormalizer::class)]
#[UsesClass(SemanticStyle::class)]
#[UsesClass(AppleHdr::class)]
final class AppleMakerNotesBuilderNewFieldsTest extends TestCase
{
    /**
     * Guards builder refactoring by requiring explicit section loader and predicate helpers.
     */
    #[Test]
    public function buildUsesSectionLoadersAndPresencePredicates(): void
    {
        $reflection = new ReflectionClass(AppleMakerNotesBuilder::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertContains('loadIdentitySection', $methods);
        self::assertContains('loadHdrSection', $methods);
        self::assertContains('loadFocusSection', $methods);
        self::assertContains('loadStyleSection', $methods);
        self::assertContains('loadCameraSection', $methods);
        self::assertContains('hasAnySectionData', $methods);
    }

    /**
     * Provides a dictionary with a MediaGroupUUID value.
     * Ensures the builder extracts it into the identity group.
     */
    #[Test]
    public function buildExtractsMediaGroupUuid(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $dictionary = [
            'MediaGroupUUID' => 'E621E1F8-C36C-495A-93FC-0C247A3E6E5F',
        ];

        $makerNotes = $builder->build($dictionary);

        self::assertNotNull($makerNotes);
        self::assertNotNull($makerNotes->identity);
        self::assertSame('E621E1F8-C36C-495A-93FC-0C247A3E6E5F', $makerNotes->identity->mediaGroupUuid);
    }

    /**
     * Provides a dictionary with ContentIdentifier and MediaGroupUUID.
     * Ensures both identity fields are populated together.
     */
    #[Test]
    public function buildExtractsContentIdentifierAndMediaGroupUuid(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $dictionary = [
            'ContentIdentifier' => 'ABCD-1234',
            'MediaGroupUUID'    => 'E621E1F8-C36C-495A-93FC-0C247A3E6E5F',
        ];

        $makerNotes = $builder->build($dictionary);

        self::assertNotNull($makerNotes);
        self::assertNotNull($makerNotes->identity);
        self::assertSame('ABCD-1234', $makerNotes->identity->contentIdentifier);
        self::assertSame('E621E1F8-C36C-495A-93FC-0C247A3E6E5F', $makerNotes->identity->mediaGroupUuid);
    }

    /**
     * Provides an empty dictionary. Confirms the builder returns null when no fields are populated.
     */
    #[Test]
    public function buildReturnsNullForEmptyDictionary(): void
    {
        $builder    = new AppleMakerNotesBuilder();
        $makerNotes = $builder->build([]);

        self::assertNull($makerNotes);
    }

    /**
     * Provides a dictionary with only MediaGroupUUID.
     * Confirms the identity group is created and all other identity fields are null.
     */
    #[Test]
    public function buildCreatesIdentityWithOnlyMediaGroupUuid(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $dictionary = [
            'MediaGroupUUID' => 'AABB-CCDD',
        ];

        $makerNotes = $builder->build($dictionary);

        self::assertNotNull($makerNotes);
        self::assertNotNull($makerNotes->identity);
        self::assertNull($makerNotes->identity->contentIdentifier);
        self::assertNull($makerNotes->identity->imageCaptureRequestId);
        self::assertNull($makerNotes->identity->burstUuid);
        self::assertNull($makerNotes->identity->imageUniqueId);
        self::assertNull($makerNotes->identity->photoIdentifier);
        self::assertSame('AABB-CCDD', $makerNotes->identity->mediaGroupUuid);
    }
}
