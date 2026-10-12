# HD Accordion FAQ Schema

A WordPress plugin by Highrise Digital that adds an "FAQ" block style to the core Accordion block and outputs FAQPage schema for accordions using it. All the code is in `hd-accordion-faq-schema.php` and is commented throughout. `README.md` covers installing and releasing.

Public repo: https://github.com/highrisedigital/hd-accordion-faq-schema

## Code style

- Plain functions prefixed `hd_faq_`, no classes.
- Comments explain the why in plain English, for a reader new to WordPress. Keep that density when adding code.
- Requires WordPress 6.9+ (the core Accordion block) and PHP 7.4+.

## SEO plugin support

- The active SEO plugin is detected in `hd_faq_get_integration()`. Yoast, Rank Math, All in One SEO and The SEO Framework 5.0+ get the FAQ added to their own schema graph. If several are active, the first in that order wins, so the FAQ is never output twice.
- SEOPress deliberately gets the standalone JSON-LD, because it has no single schema graph to join.
- If an SEO plugin is detected but its graph filter never runs on a page (schema output turned off), the standalone JSON-LD is printed in the footer as a safety net.
- Only Yoast has been tested on a real site. Rank Math, AIOSEO and The SEO Framework were only tested against mocked graphs, so check real output (Google's Rich Results Test) before relying on them.

## Updates and releases

- Updates come from GitHub releases through WordPress core's `Update URI` header and the `update_plugins_github.com` filter. No third-party updater library: this was a deliberate choice, and it works because the repo is public.
- The updater needs a `hd-accordion-faq-schema.zip` asset on each release. GitHub's own source zips can't be used, because they unpack to a differently named folder.
- `.github/workflows/release.yml` builds that zip and publishes the release when a `v*` tag is pushed. It fails if the tag doesn't match the `Version:` header.
- To release: bump `Version:` in the plugin header, commit, push, then tag `vX.Y.Z` and push the tag.
- `.gitattributes` keeps `.github`, `.gitattributes`, `README.md` and `CLAUDE.md` out of the release zip.

## Local development

- On a local site that loads this git checkout (for example through a symlink in its plugins folder), clicking "Update" in WordPress would overwrite the checkout with the release zip. Don't update the plugin from the dashboard on such a site.
- The jobrelay-site repo (highrisedigital/jobrelay-site) used to track this plugin. It now gitignores it, and the site installs it from releases like any other plugin.
