# Filter URLs

OpenFilter keeps Magento's routing and URL rewrites: filters are query parameters on the normal category or search URL, with readable, store-scoped names.

```text
/women/tops-women.html?color=blue,red&size=m&price=20-80&rating=4&stock=1
```

| Filter | Parameter | Value |
|---|---|---|
| Attribute (list, swatch) | the attribute's URL name (default: its code) | option URL names, comma-separated (`%2C` works too) |
| Price, decimal attributes | `price`, the attribute's URL name | `min-max` with `.` as decimal separator; open ends `20-` and `-80`; price in the display currency |
| Rating | `rating` | `4` = 4 stars and up (1–5) |
| In stock, on sale, new | `stock`, `sale`, `new` | `1` |
| Category | Magento's own (`cat` on search pages, the category URL on category pages) | |

## URL names

Option URL names are generated once from the option labels: lower case, German transliteration first (Grün → `gruen`, Weiß → `weiss`), any other script to ASCII, everything else becomes `-`. Collisions within one attribute and store view get `-2`, `-3` in option order. Store views get their own name only where it differs (German `rot` next to `red`).

Names never change because a label changed, so published URLs stay valid. New options get a name when the attribute is saved. Parameter names avoid Magento's own (`p`, `q`, `cat`, `product_list_*`, `___store`) and the built-in filters (`price`, `rating`, `stock`, `sale`, `new`): Luma's sample attributes `new` and `sale` become `new-2` and `sale-2`.

Old links keep working: a numeric option id is accepted in place of its name.

## Canonical URLs (301)

Every filter URL has one canonical form: filters in position order, values in option order, URL names, lower case, no duplicates or unknown values. Category and search pages answer other forms with a **301** to the canonical URL, except when only the order of the parameters differs (Magento's Varnish configuration sorts query parameters before Magento sees them, so a redirect for the order would loop; the canonical tag names the one URL); sort order, limit and view mode stay, the page number resets only when values were dropped. Other parameters (tracking, for example) may sit anywhere. POST requests are never redirected.

Links OpenFilter renders are canonical already, so shoppers and crawlers never hit the redirect; it catches hand-written, old and external links and keeps one cache entry per filtered page.

## Reading and writing URLs in code

`Iranimij\OpenFilter\Api\UrlCodecInterface` turns parameters into an `Api\FilterStateInterface` (`decode`) and back (`encode`, `urlFor`). Unknown parameters are ignored, values are checked against the attribute's options, numbers are clamped (price ≥ 0, ≤ 10⁹; rating 1–5).
