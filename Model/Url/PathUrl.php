<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Model\Url;

use Iranimij\OpenFilter\Api\FilterStateInterface;
use Iranimij\OpenFilter\Api\UrlCodecInterface;
use Iranimij\OpenFilter\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Path filter URLs of category pages (1.2, opt-in): the category's URL without its suffix, one segment per filter
 * ("<parameter>-<values>", values comma-separated as in the query format) in the codec's canonical order, then the
 * suffix: women/tops-women/color-black,blue/size-m.html. Other parameters (page, sort) stay in the query. A filter
 * whose segment would read back as another one (a parameter name that starts another, plus a dash) stays in the query,
 * so every URL decodes to the state it was built from.
 */
class PathUrl
{
    /** Request alias: the filter segments the path router took off the path ("color-black/size-m"). */
    public const ALIAS = 'openfilter_path';

    /** Magento's category URL suffix (CategoryUrlPathGenerator::XML_PATH_CATEGORY_URL_SUFFIX). */
    private const SUFFIX_PATH = 'catalog/seo/category_url_suffix';

    /**
     * @param Config $config
     * @param UrlCodecInterface $codec
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly Config $config,
        private readonly UrlCodecInterface $codec,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param int $storeId
     * @return bool
     */
    public function isActive(int $storeId): bool
    {
        return $this->config->getUrlFormat($storeId) === Config::URL_FORMAT_PATH;
    }

    /**
     * URL of a category page with this filter state: path segments for the filters, the query for the rest.
     *
     * @param FilterStateInterface $state
     * @param string $categoryUrl the category's URL (absolute or relative), without a query
     * @param array<string, string> $keep other query parameters (page, sort, limit)
     * @param int $storeId
     * @return string
     */
    public function build(FilterStateInterface $state, string $categoryUrl, array $keep, int $storeId): string
    {
        [$segments, $query] = $this->parts($state, $storeId);
        $stem = $segments === [] ? null : $this->stem($categoryUrl, $storeId);
        if ($stem === null) {
            return $this->codec->urlFor($state, $categoryUrl, $keep, $storeId);
        }
        $names = $this->codec->getParameterNames($storeId);
        foreach (array_diff_key($keep, $names) as $name => $value) {
            $query[(string) $name] = (string) $value;
        }
        $url = $stem . '/' . implode('/', array_map([$this, 'encode'], $segments)) . $this->suffix($storeId);

        return $query === [] ? $url : $url . '?' . implode('&', array_map(
            fn (string $name, string $value): string => rawurlencode($name) . '=' . $this->encode($value),
            array_keys($query),
            $query
        ));
    }

    /**
     * The filter segments and the filters left in the query for a state, in canonical order.
     *
     * @param FilterStateInterface $state
     * @param int $storeId
     * @return array{0: string[], 1: array<string, string>} segments, query parameters
     */
    public function parts(FilterStateInterface $state, int $storeId): array
    {
        $names = $this->codec->getParameterNames($storeId);
        $segments = [];
        $query = [];
        foreach ($this->codec->encode($state, $storeId) as $param => $value) {
            $segment = $param . '-' . $value;
            if (!str_contains($value, '/') && $this->split($segment, $names) === [$param, $value]) {
                $segments[] = $segment;
            } else {
                $query[$param] = $value;
            }
        }

        return [$segments, $query];
    }

    /**
     * Whether a request's filters are in the canonical path form: the segments the router took and the filters in
     * the real query string equal what build() would write for its state.
     *
     * @param FilterStateInterface $state the request's (decoded) filter state
     * @param string $taken segments the path router took off the path ("" = none)
     * @param array<string, mixed> $realQuery the query string as sent (not the values the router added)
     * @param int $storeId
     * @return bool
     */
    public function isCanonical(FilterStateInterface $state, string $taken, array $realQuery, int $storeId): bool
    {
        [$segments, $query] = $this->parts($state, $storeId);
        $sent = [];
        foreach (array_intersect_key($realQuery, $this->codec->getParameterNames($storeId)) as $name => $value) {
            $sent[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return $taken === implode('/', $segments) && $sent === $query;
    }

    /**
     * Splits a request path into the category path and the filter parameters at its end; null = no filter segment.
     *
     * @param string $path request path without leading slash (women/tops-women/color-black.html)
     * @param int $storeId
     * @return array{0: string, 1: array<string, string>, 2: string}|null category path, parameters, the segments taken
     */
    public function parse(string $path, int $storeId): ?array
    {
        $suffix = $this->suffix($storeId);
        if ($suffix !== '' && !str_ends_with($path, $suffix)) {
            return null;
        }
        $segments = explode('/', $suffix === '' ? $path : substr($path, 0, -strlen($suffix)));
        $names = $this->codec->getParameterNames($storeId);
        $params = [];
        $taken = [];
        while (count($segments) > 1) {
            // URL names are lower-case letters, digits and dashes; only the value separator may arrive encoded.
            $segment = str_ireplace('%2C', ',', (string) end($segments));
            $split = $this->split($segment, $names);
            if ($split === null || isset($params[$split[0]])) {
                break;
            }
            $params = [$split[0] => $split[1]] + $params;
            array_unshift($taken, $segment);
            array_pop($segments);
        }

        return $params === [] ? null : [implode('/', $segments) . $suffix, $params, implode('/', $taken)];
    }

    /**
     * A sitemap or link URL of one filter value on a category: path segment or query parameter.
     *
     * @param string $categoryUrl
     * @param string $param
     * @param string $value
     * @param int $storeId
     * @return string
     */
    public function oneValue(string $categoryUrl, string $param, string $value, int $storeId): string
    {
        $stem = $this->isActive($storeId) ? $this->stem($categoryUrl, $storeId) : null;
        $names = $this->codec->getParameterNames($storeId);
        if ($stem !== null && $this->split($param . '-' . $value, $names) === [$param, $value]) {
            return $stem . '/' . $this->encode($param . '-' . $value) . $this->suffix($storeId);
        }

        return $categoryUrl . '?' . rawurlencode($param) . '=' . $this->encode($value);
    }

    /**
     * The category URL without its suffix; null when it is not a rewritten category URL (no path format then).
     *
     * @param string $categoryUrl
     * @param int $storeId
     * @return string|null
     */
    private function stem(string $categoryUrl, int $storeId): ?string
    {
        $suffix = $this->suffix($storeId);
        if ($suffix === '') {
            $stem = rtrim($categoryUrl, '/');

            return str_contains($stem, '/catalog/category/view/') ? null : $stem;
        }

        return str_ends_with($categoryUrl, $suffix) ? substr($categoryUrl, 0, -strlen($suffix)) : null;
    }

    /**
     * "<parameter>-<values>" into its parameter (the longest known name) and values; null = not a filter segment.
     *
     * @param string $segment
     * @param array<string, string> $names parameter => filter key
     * @return array{0: string, 1: string}|null
     */
    private function split(string $segment, array $names): ?array
    {
        $best = null;
        foreach (array_keys($names) as $name) {
            $name = (string) $name;
            if (strlen($segment) > strlen($name) + 1 && str_starts_with($segment, $name . '-')
                && ($best === null || strlen($name) > strlen($best))
            ) {
                $best = $name;
            }
        }

        return $best === null ? null : [$best, substr($segment, strlen($best) + 1)];
    }

    /**
     * @param string $value
     * @return string
     */
    private function encode(string $value): string
    {
        return str_replace('%2C', ',', rawurlencode($value));
    }

    /**
     * @param int $storeId
     * @return string
     */
    private function suffix(int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(self::SUFFIX_PATH, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
