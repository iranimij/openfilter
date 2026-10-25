# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/): `Api/` interfaces, the filter URL format, the storefront markup contract (`docs/storefront.md`) and the CLI JSON format change only in a major version.

## [1.1.1] - 2026-10-25

### Fixed
- Filter URLs with their parameters in another order than the filters' no longer redirect (301). Magento's Varnish configuration sorts query parameters alphabetically before Magento sees them, so on stores behind it the redirect came back sorted and looped ("too many redirects") whenever the filter order was not alphabetical, for example after *Sort filters*. The canonical tag still names one URL.
- The desktop apply bar (*When results update on desktop* = button) had no background, border or button colour: it is moved to the end of the page, outside the elements that define OpenFilter's colours, so products showed through it and *Show N products* was white on nothing.

## [1.1.0] - 2026-10-16

No changes since 1.1.0-rc1.

## [1.1.0-rc1] - 2026-10-16

Everything new is opt-in: with the 1.0 settings, the storefront, URLs, head tags and counts stay as they were.

### Added
- **Products must match all of the picked values** per multi-select filter (AND): one clause per value in the same search request; such filters count with their own picks, the others without theirs.
- **Ranges** display type for price and decimal filters: the merchant's list (`0-50,50-100,100-`) or automatic round ranges, several picks with any of, counted in the same request.
- **Category tree** display type: the categories below the page's category, 1–5 levels, several picks narrow the same page (`?cat=21,25`); cached, fewer SQL queries than Magento's drill-down.
- **Where it shows**: every category, only in some, or all except some (a category counts with the ones below it); table `openfilter_filter_category`.
- **Sort filters** admin page (drag handle, or Move up / Move down by keyboard); a storefront preview link in the "Why is this filter not showing?" panel; tooltips per store view.
- Filter form per store view through Magento's store switcher (no column per store view); "Generate again" per store view.
- Store settings for the Hyvä package: horizontal layout, desktop apply mode ("Show N products"), sticky sidebar; dropdown display type.
- **SEO templates** for filtered category pages: page title, meta description and H1 with `{filters}`, `{category}`, `{store}`.
- **XML sitemap** of the filter pages the robots rule leaves indexable (Magento_Sitemap item provider): only where the filter shows, a minimum product count, most products first, a cap per store view. Off by default.
- **GraphQL** `products { openfilter_aggregations }` for headless storefronts: the store view's filters in order, disjunctive counts from the same request, picks, URL names, swatches, slider bounds, ranges, categories; opt-in per query, Magento's GraphQL modules stay optional. Headless example page in `examples/headless`.
- **REST**: `GET/PUT /V1/openfilter/filters[/:code]` (a PUT changes only the sent settings), `GET/POST /V1/openfilter/config` (the export document; import is a dry run unless asked).
- Config export schema 2: match all, ranges, tree levels, tooltips, where it shows (schema 1 files still import).
- `docs/benchmark.md`: OpenFilter against Magento's layered navigation on the same store (1 engine request per page instead of 4, no server time added).
- Admin (Review #1): grid columns *Order* (the storefront place), *Products must match* and *Where it shows*; mass actions *Show / Hide on search pages*; warnings for filters sharing a name and for search pages without attribute filters (also a doctor check); option labels as text in the form, the "Why" panel last (it opens and scrolls into view from the grid's link); *Sort filters* with a search box and a save bar that stays in view; a character count under the title and description templates; settings sections *SEO* and *Filter counts*.
- New swatch filters show up to 12 values before "Show more" (saved filters keep their setting).

## [1.0.0] - 2026-10-16

No changes since 1.0.0-rc1.

## [1.0.0-rc1] - 2026-10-15

First release: layered navigation for Magento 2.4.7–2.4.9 on OpenSearch (Elasticsearch 8 on 2.4.7).

### Added
- Filters: multi-select per attribute (OR) and single-select, disjunctive counts in one search request (`Api\FacetCountStrategyInterface`: `single_query` with `post_filter` and filtered aggregations, `per_attribute` fallback, chosen per engine), hide empty options (selected ones stay), hide single-option filters, sort by position, name or count.
- Price and decimal sliders whose bounds come from the same search request, in the display currency and tax display mode.
- Built-in filters rating ("4 stars and up"), in stock (MSI-aware), on sale (per customer group and website) and new (dates at query time), as search index fields with reindex triggers for reviews, prices and stock.
- Readable store-scoped URL names (transliteration incl. German umlauts, collisions, never changed silently), `Api\UrlCodecInterface`, 301 to the canonical filter URL, option-id fallback for old links.
- SEO: robots (`NOINDEX,FOLLOW` except single values of filters marked indexable), canonical (indexable pages themselves, others the category; one tag, also when Magento's category canonical is off), `rel="nofollow"` from 2 active values, at most 5 values per URL (302 with `X-Robots-Tag: noindex`).
- Admin: Catalog › OpenFilter › Filters grid (inline edit, show/hide/reset), filter form (how it looks, how it behaves, SEO with URL names per store view, labels per store view), OpenFilter fieldset on the attribute page, "Why is this filter not showing?" panel, first-run notice, settings under Stores › Configuration › Iranimij › OpenFilter.
- `Api\FilterConfigRepositoryInterface` (incl. `getList(SearchCriteria)`), `Api\SlugRepositoryInterface`, `Api\FilterStateInterface`.
- Command line: `openfilter:doctor`, `openfilter:slugs:generate`, `openfilter:config:export`, `openfilter:config:import` (JSON schema 1, dry run, all or nothing).
- View models for theme packages (`FilterBlock`, `State`, `Slider`, `Swatch`, `Debug`) and the structural stylesheet `openfilter.css` (of-* classes, theme tokens, logical properties).
- Coexistence: OpenFilter steps aside when ElasticSuite or Adobe Live Search provides the layered navigation, with an admin notice.
- Log channel `var/log/openfilter.log`, debug mode (`data-of-debug-*` attributes, strategy choice and redirects logged), uninstall class, English and German translations.
