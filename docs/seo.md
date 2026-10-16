# SEO rules

Filtered category pages multiply: ten colours and six sizes already make hundreds of URLs. OpenFilter keeps search engines on the pages worth indexing and out of the rest. The rules are conservative by default: only pages you mark can be indexed.

## Robots

| Page | Robots |
|---|---|
| No filter | your store's setting (unchanged) |
| Exactly one value of a filter with **Let Google index pages filtered by this** | your store's setting (unchanged) |
| Anything else: two values, two filters, a filter not marked, a category drill-down plus a filter | `NOINDEX,FOLLOW` |
| Search results with any filter | `NOINDEX,FOLLOW` |

Mark a filter in **Catalog › OpenFilter › Filters › (filter) › SEO**. Good candidates are filters whose single values match what people search for: brand, colour, material. `FOLLOW` stays everywhere, so crawlers still reach your products.

## Canonical

| Page | Canonical |
|---|---|
| Indexable filtered page | the page itself: `/women/tops.html?color=red` (page number kept, sorting, limit and view mode dropped) |
| Any other filtered page | the category: `/women/tops.html` |
| Search results | none (as Magento) |

OpenFilter prints the canonical on filtered category pages even when Magento's "Use Canonical Link Meta Tag For Categories" is off, and replaces Magento's tag when it is on: there is always exactly one.

## Nofollow

Once a page has **2** active values (setting: "Ask search engines not to follow filter links from this many active values"), the links that would add another value carry `rel="nofollow"`. Links that remove a value stay followable. `0` switches the rule off.

## Most values at once

A URL with more than **5** active values ("Most filter values active at once") answers with a **302** to the URL with its first five values (in canonical order) and an `X-Robots-Tag: noindex` header. Bots that combine every filter end on a page that already exists, and your page cache does not fill with combinations nobody visits. `0` means no limit.

## Canonical parameters (301)

Every filtered URL has one canonical spelling (filters in position order, values in option order, readable URL names, lower case). Other spellings, such as an old link with option ids, answer with a **301** to it. See [Filter URLs](url-format.md).

## Title, description and H1 (1.1)

**Stores › Configuration › Catalog › OpenFilter › SEO** has three templates for filtered category pages. Each is set per store view; empty keeps Magento's.

| Setting | Example | On `/women/tops.html?color=red,blue` |
|---|---|---|
| Page title of filtered pages | `{filters} {category} \| {store}` | `Red, Blue Tops \| Luma` |
| Meta description of filtered pages | `Shop {filters} {category} at {store}.` | `Shop Red, Blue Tops at Luma.` |
| Heading (H1) of filtered pages | `{filters} {category}` | `Red, Blue Tops` |

- `{category}` is the category name, `{filters}` the active values in the order of the page ("Red, Blue"), `{store}` the store name. Other text in braces stays as it is.
- Pages without filters, and search result pages, keep Magento's title, description and heading.
- Search results show about 60 characters of a title and 160 of a description; keep the templates short. The settings page counts the characters as you type.
- A title template does not change the heading; set the H1 template for that.

## XML sitemap (1.1)

Turn on **Add indexable filter pages** under **Stores › Configuration › Catalog › OpenFilter › XML sitemap**, then generate the sitemap as usual (**Marketing › SEO & Search › Site Map**, or Magento's cron). OpenFilter adds the pages its robots rule leaves indexable, and only those:

- one value of a filter marked **Let Google index pages filtered by this** (dropdown and multiple-select attributes), on each category page of the store view;
- only where the filter shows (**Where it shows**; a category counts with the categories below it);
- only with at least **Only pages with at least this many products** products (default 1), counted as shoppers see them;
- the pages with the most products first, up to **Most filter pages per store view** (default 5,000; 0 = no limit).

Each URL is the canonical one (`/women/tops.html?color=red`, the store view's URL names) with the categories' priority, change frequency and last modified date. Counting asks the search engine once per category, so a catalog with thousands of categories takes a little longer to generate.

## After AJAX updates

Filter clicks fetch the same URL a crawler would and copy the title, meta description, robots, canonical and prev/next links from it, and the H1 when a heading template is set, so the page in the browser always carries the head a direct load of that URL has.

## Path URLs (1.2)

Category pages can use path filter URLs (`/women/tops-women/color-black.html`), see [Filter URLs](url-format.md#path-urls-12-opt-in). Robots, nofollow, canonical and sitemap rules are the same; canonical URLs and sitemap entries use the path form, and the other form redirects (301) to it.
