<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Model\Url;

use Iranimij\OpenFilter\Api\FilterStateInterface;
use Iranimij\OpenFilter\Api\UrlCodecInterface;

/**
 * Decides whether a layered page's filter parameters are canonical (Spec UrlCodec, "Normalisation"): same filters
 * in position order, values in option order, URL names instead of ids, lower case, no duplicates or junk.
 * Other parameters (sort, limit, mode, tracking) may sit anywhere. A range form submitted without JavaScript sends
 * <param>_from and <param>_to: they fold into <param>=from-to and leave the URL (URL names never contain "_").
 */
class ParamNormalizer
{
    /** Suffixes of the two fields of a range form (price_from, price_to). */
    public const RANGE_FROM = '_from';
    public const RANGE_TO = '_to';

    /**
     * @param UrlCodecInterface $codec
     */
    public function __construct(
        private readonly UrlCodecInterface $codec
    ) {
    }

    /**
     * @param array<string, mixed> $query request query parameters in request order (decoded)
     * @param int $storeId
     * @return array{redirect: bool, state: FilterStateInterface, changed: bool, drop: string[]} changed: the filter
     *     state differs from what the URL asked for (unknown or duplicate values dropped), so the page number resets;
     *     drop: range form fields to leave out of the redirect URL
     */
    public function check(array $query, int $storeId): array
    {
        $names = $this->codec->getParameterNames($storeId);
        [$query, $drop] = $this->foldRangeFields($query, array_keys($names));
        $actual = [];
        $tokens = 0;
        foreach ($query as $name => $value) {
            if (!isset($names[$name])) {
                continue;
            }
            $actual[$name] = is_scalar($value) ? (string) $value : null;
            $raw = is_array($value) ? $value : explode(',', (string) $value);
            $tokens += count(array_filter($raw, static fn ($t): bool => is_scalar($t) && trim((string) $t) !== ''));
        }
        $state = $this->codec->decode($query, $storeId);
        if ($actual === []) {
            return ['redirect' => $drop !== [], 'state' => $state, 'changed' => false, 'drop' => $drop];
        }
        $canonical = $this->codec->encode($state, $storeId);
        // The order of the parameters is not a reason to redirect: Magento's Varnish configuration sorts query
        // parameters alphabetically before the request reaches Magento, so a redirect to the filters' own order would
        // come back sorted and loop. The canonical tag still names one URL.
        ksort($actual);
        ksort($canonical);

        return [
            'redirect' => $drop !== [] || $actual !== $canonical,
            'state' => $state,
            'changed' => $state->count() !== $tokens,
            'drop' => $drop,
        ];
    }

    /**
     * Folds <param>_from / <param>_to into <param>=from-to; an empty pair just disappears.
     *
     * @param array<string, mixed> $query
     * @param array<int|string> $parameters filter parameter names of the store view
     * @return array{array<string, mixed>, string[]} the query and the folded field names
     */
    private function foldRangeFields(array $query, array $parameters): array
    {
        $drop = [];
        foreach ($parameters as $parameter) {
            $parameter = (string) $parameter;
            $fromName = $parameter . self::RANGE_FROM;
            $toName = $parameter . self::RANGE_TO;
            if (!array_key_exists($fromName, $query) && !array_key_exists($toName, $query)) {
                continue;
            }
            $from = is_scalar($query[$fromName] ?? null) ? trim((string) $query[$fromName]) : '';
            $to = is_scalar($query[$toName] ?? null) ? trim((string) $query[$toName]) : '';
            unset($query[$fromName], $query[$toName]);
            $drop[] = $fromName;
            $drop[] = $toName;
            if ($from !== '' || $to !== '') {
                $query[$parameter] = $from . '-' . $to;
            }
        }

        return [$query, $drop];
    }
}
