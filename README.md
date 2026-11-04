# Florank for WordPress

WordPress plugin that connects a site to [Florank](https://florank.ai) — the SEO + GEO platform for site audits, fixes, articles and AI visibility monitoring.

With the plugin installed, Florank can publish articles to WordPress as drafts or live posts, write Yoast / Rank Math SEO fields, import article images into the Media Library, output article JSON-LD and serve the IndexNow key.

## Build

Requirements: PHP 8.0+, Composer.

```bash
composer install          # dev tools: PHPCS + WordPress Coding Standards, PHPUnit
composer lint             # PHPCS
composer test             # unit tests
./bin/build-zip.sh        # creates build/florank.zip with a florank/ folder inside
```

Releases: pushing a `v*` tag builds `florank.zip` and attaches it to the GitHub Release.

## Links

- Product: https://florank.ai
- Terms: https://florank.ai/en/terms
- Privacy: https://florank.ai/en/privacy

## License

GPLv2 or later. See [LICENSE](LICENSE).
