<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Model\Seo;

use Iranimij\OpenFilter\Api\FilterStateInterface;
use Iranimij\OpenFilter\Api\UrlCodecInterface;
use Iranimij\OpenFilter\Model\Url\PathUrl;

/**
 * Canonical URL of a filtered category page: an indexable page points to itself (canonical filter parameters and the
 * page number; sort, limit and view mode dropped), every other filtered page points to the category URL.
 */
class CanonicalResolver
{
    /**
     * @param UrlCodecInterface $codec
     * @param PathUrl $pathUrl path filter URLs (1.2)
     */
    public function __construct(
        private readonly UrlCodecInterface $codec,
        private readonly PathUrl $pathUrl
    ) {
    }

    /**
     * @param FilterStateInterface $state
     * @param bool $indexable
     * @param string $categoryUrl the category's URL without a query
     * @param int $page current page number (1 when absent)
     * @param int $storeId
     * @return string
     */
    public function resolve(
        FilterStateInterface $state,
        bool $indexable,
        string $categoryUrl,
        int $page,
        int $storeId
    ): string {
        if (!$indexable) {
            return $categoryUrl;
        }

        $keep = $page > 1 ? ['p' => (string) $page] : [];

        return $this->pathUrl->isActive($storeId)
            ? $this->pathUrl->build($state, $categoryUrl, $keep, $storeId)
            : $this->codec->urlFor($state, $categoryUrl, $keep, $storeId);
    }
}
