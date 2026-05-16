=== News Translator for LibreTranslate ===

Contributors: thedemocracyadvocate
Tags: translation, libretranslate, multilingual, seo, sitemap, hreflang
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 2.7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

# News Translator Pro

Translates English news posts to `/es/` (Spanish), `/fr/` (French), and `/pt/` (Portuguese) subdirectory URLs with full SEO support.

---

## What This Plugin Does

| Feature | Detail |
|---|---|
| **Indexable subdirectory URLs** | `/es/mi-articulo/`, `/fr/mon-article/`, and `/pt/meu-artigo/` — real crawlable pages |
| **RankMath integration** | Translates title, meta description, OG tags, Twitter card, focus keyword, JSON-LD schema |
| **hreflang tags** | `<link rel="alternate" hreflang="es-ES">` on every English + translated page |
| **Canonical URLs** | Each translated page has its own canonical pointing to itself (not the English URL) |
| **XML sitemap** | Translated URLs injected into RankMath's post sitemap + a standalone `/nt-sitemap.xml` |
| **Google News sitemap** | Translated posts appear in RankMath's `news-sitemap.xml` (last 2 days window) |
| **Auto-translate on publish** | Optional: translate Spanish, French, and Portuguese the moment you hit Publish |
| **Manual translate** | Sidebar panel in the post editor per-language or "Translate All" |
| **Batch tools** | Translate missing content or force retranslate all existing articles from Settings > News Translator |
| **Re-translate** | Force a fresh translation to pick up edits |
| **Translation caching** | Stored in DB — LibreTranslate only called once per post per language |
| **Language switcher** | Clean article nav bar plus floating homepage language picker |

---

## Installation

1. Upload the `news-translator-pro/` folder to `/wp-content/plugins/`
2. Activate **News Translator Pro** in **Plugins → Installed Plugins**
3. Go to **Settings > News Translator** and configure your API
4. Click **Test LibreTranslate** to confirm connectivity
5. If `/es/`, `/fr/`, or `/pt/` URLs return 404, click **Flush Rewrite Rules**

---

## Configuration

### LibreTranslate API URL

| Option | Value |
|---|---|
| Hosted (requires API key) | `https://libretranslate.com` |
| Docker internal (free, no key) | `http://libretranslate:5000` |

### Self-hosting LibreTranslate (recommended for news blogs)

Self-hosting removes API costs, rate limits, and latency:

```bash
pip install libretranslate
libretranslate --load-only en,es,fr,pt
```

Set API URL to `http://libretranslate:5000` when WordPress and LibreTranslate share a Docker network and leave API Key blank.

### Auto-Translate on Publish

When enabled, the plugin calls LibreTranslate for ES, FR, and PT immediately when you publish a post. Translation happens synchronously — if your LibreTranslate instance is slow, expect a short delay on publish. For very long posts on a remote API, consider leaving this off and translating manually from the editor sidebar.

---

## How URL Routing Works

WordPress rewrite rules intercept `/es/`, `/fr/`, and `/pt/` before standard routing:

```
/es/                    → Spanish archive (latest translated posts)
/es/page/2/             → Paginated archive
/es/mi-titulo-articulo/ → Single translated post
/fr/                    → French archive
/fr/mon-titre-article/  → Single translated post
/pt/                    → Portuguese archive
/pt/meu-artigo/         → Single translated post
```

Translated slugs are generated from the translated title via `sanitize_title()`.

---

## SEO Details

### hreflang

Every English post and its translated counterparts output a full set of hreflang annotations:

```html
<link rel="alternate" hreflang="x-default" href="https://yoursite.com/my-post/" />
<link rel="alternate" hreflang="en"        href="https://yoursite.com/my-post/" />
<link rel="alternate" hreflang="es-ES"     href="https://yoursite.com/es/mi-articulo/" />
<link rel="alternate" hreflang="fr-FR"     href="https://yoursite.com/fr/mon-article/" />
<link rel="alternate" hreflang="pt-BR"     href="https://yoursite.com/pt/meu-artigo/" />
```

### Canonical

Translated pages have their own canonical:
```html
<link rel="canonical" href="https://yoursite.com/es/mi-articulo/" />
```

### RankMath Fields Translated

- `rank_math_title` → `seo_title`
- `rank_math_description` → `seo_desc`
- `rank_math_keywords` → `seo_keywords`
- `rank_math_og_title` + `rank_math_og_description`
- `rank_math_twitter_title` + `rank_math_twitter_description`
- `rank_math_focus_keyword`
- JSON-LD `@graph` Article/NewsArticle entity

### Sitemaps

- Translated URLs injected into **RankMath's post sitemap** via `rank_math/sitemap/urlset`
- Translated posts appear in **RankMath's Google News sitemap** via `rank_math/sitemap/news_sitemap`
- Standalone sitemap available at `/nt-sitemap.xml` — submit this to Google Search Console alongside RankMath's sitemap
- Sitemap index entry added to RankMath's sitemap index automatically

---

## Submitting to Google Search Console

1. Go to Google Search Console → Sitemaps
2. Submit `https://yoursite.com/sitemap_index.xml` (RankMath's index — includes translated URLs)
3. Also submit `https://yoursite.com/nt-sitemap.xml` directly as a supplemental sitemap
4. For Google News, submit `https://yoursite.com/news-sitemap.xml` (RankMath handles this)

---

## File Structure

```
news-translator/
├── news-translator.php             ← Bootstrap, constants, auto-translate hook
├── includes/
│   ├── class-db.php                ← All DB queries (translations table)
│   ├── class-libretranslate-api.php ← LibreTranslate HTTP client
│   ├── class-post-translator.php   ← Translation orchestration + RankMath field harvesting
│   ├── class-rewrite.php           ← /es/ /fr/ /pt/ URL routing + permalink helpers
│   ├── class-frontend.php          ← Language switcher bar
│   ├── class-hreflang.php          ← hreflang + canonical head tags
│   ├── class-sitemap.php           ← RankMath sitemap injection + /nt-sitemap.xml
│   └── class-rankmath.php          ← RankMath filter overrides for translated pages
├── admin/
│   ├── class-settings-page.php     ← Settings > News Translator Pro
│   └── class-meta-box.php          ← Post editor sidebar translation panel
├── assets/
│   └── frontend.css                ← Language switcher styles
└── README.md
```

---

## Database Schema

```sql
wp_news_translations (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  post_id        BIGINT,          -- FK to wp_posts
  language       VARCHAR(10),     -- 'es', 'fr', or 'pt'
  title          LONGTEXT,
  content        LONGTEXT,
  excerpt        LONGTEXT,
  slug           VARCHAR(255),    -- translated slug for URL routing
  seo_title      VARCHAR(255),    -- rank_math_title translated
  seo_desc       TEXT,            -- rank_math_description translated
  seo_keywords   TEXT,
  og_title       VARCHAR(255),
  og_desc        TEXT,
  twitter_title  VARCHAR(255),
  twitter_desc   TEXT,
  focus_kw       VARCHAR(255),
  translated_at  DATETIME,
  UNIQUE (post_id, language)
)
```

---

## Extending

**Add more languages** — add to `NT_LANGS`, `NT_LOCALES`, `NT_FLAGS` in `news-translator.php`. Make sure your LibreTranslate instance has those language models loaded.

**Custom post types** — in `class-rewrite.php` change `'post_type' = 'post'` queries to include your CPT. Also update the `post_type` check in `nt_auto_translate_on_publish`.

**Disable auto-translate delay** — use `wp_schedule_single_event()` in `nt_auto_translate_on_publish` to push translation to a background cron job instead of running synchronously on publish.

---

## Changelog

See `CHANGELOG.txt` included in this ZIP for the full version history.

### 2.6.1
- Moved homepage floating language picker to the compact upper-right position.
- Reduced size and visual weight so it does not cover article/homepage text as aggressively.


- Added **Force Retranslate All Existing Articles** to Settings > News Translator.
- Added per-language force retranslation for Spanish, French, and Portuguese.
- Added cursor-based AJAX batching so large retranslation jobs can run safely without one giant browser request.
- Preserved the existing **Translate Missing Content** behavior.
- Force mode overwrites stored translated article fields from the current English source.


## Support Development

If this plugin helps your multilingual publishing workflow, you can support continued development here:

https://www.paypal.com/donate/?hosted_button_id=G69AUBDK36GD8
