# HD Accordion FAQ Schema

A WordPress plugin that adds an **FAQ** style to the core Accordion block. Accordions with that style are added to the page's schema as an `FAQPage`, with full blocks (paragraphs, lists, links) allowed in answers.

## SEO plugin support

The plugin detects the active SEO plugin and adds the FAQ to its schema graph:

- Yoast SEO
- Rank Math
- All in One SEO
- The SEO Framework 5.0+

With SEOPress, or no SEO plugin, the FAQ is printed as standalone FAQPage JSON-LD. The standalone output is also used if the SEO plugin's schema output is turned off.

## Installing

Download `hd-accordion-faq-schema.zip` from the [latest release](https://github.com/highrisedigital/hd-accordion-faq-schema/releases/latest) and upload it under Plugins > Add New > Upload Plugin. Later versions then show as updates in the WordPress dashboard.

## Releasing a new version

1. Change the `Version:` line in `hd-accordion-faq-schema.php`, for example to `1.3.0`.
2. Commit, then tag and push:

   ```sh
   git tag v1.3.0
   git push origin main --tags
   ```

The release workflow checks the tag matches the plugin version, builds `hd-accordion-faq-schema.zip` and publishes the GitHub release. Sites pick up the update within about 12 hours, or straight away from Dashboard > Updates > Check again.
