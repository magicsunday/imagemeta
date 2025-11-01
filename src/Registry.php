<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta;

use MagicSunday\ImageMeta\Contracts\EnricherInterface;

/**
 * Registry that stores extension-provided enrichers.
 */
final class Registry
{
    /** @var list<EnricherInterface> */
    private array $enrichers = [];

    public function withEnricher(EnricherInterface $enricher): void
    {
        $this->enrichers[] = $enricher;
    }

    /**
     * @return list<EnricherInterface>
     */
    public function enrichers(): array
    {
        return $this->enrichers;
    }
}
