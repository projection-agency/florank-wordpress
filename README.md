# Florank for WordPress

WordPress plugin that connects a site to [Florank](https://florank.ai) — the SEO + GEO platform for site audits, fixes, articles and AI visibility monitoring.

With the plugin installed, Florank can publish articles to WordPress as drafts or live posts, write Yoast / Rank Math SEO fields, import article images into the Media Library, output article JSON-LD and serve the IndexNow key.

The REST contract with the Florank backend is described in [docs/api-contract.md](docs/api-contract.md). Do not change it without updating the backend (`audit-back`, `wordpress.adapter.ts`) in a compatible way.

## Structure

```
florank.php            plugin header + bootstrap
uninstall.php          removes options, keys and stored JSON-LD
readme.txt             wordpress.org readme
src/                   PSR-4, namespace Florank\ (own autoloader in src/autoload.php — the zip ships without vendor/)
  Plugin.php           wires components to hooks
  Admin/SettingsPage   "Florank" admin page: generate / rotate the API key
  Rest/Controller      florank/v1 routes: /health, /posts CRUD
  Auth/ApiKey          key generation, hash storage, header parsing, verification
  Auth/ConnectionCode  connection code flc1_… (site address + key) shown on the settings page
  Posts/PostService    create / update / list / delete posts
  Media/ImageImporter  images (bytes or URL) → Media Library
  Seo/SeoBridge        Yoast / Rank Math detection and meta
  Seo/JsonLd           article JSON-LD storage and <head> output
  IndexNow/IndexNow    IndexNow key storage and /{key}.txt
languages/florank.pot  translation template
tests/Unit             PHPUnit without WordPress (stubs in tests/Unit/bootstrap.php)
tests/Integration      PHPUnit with the WordPress test library (wp-env)
```

## Develop

Requirements: PHP 8.0+, Composer; Docker + Node.js for wp-env.

```bash
composer install
composer lint                 # PHPCS with WordPress Coding Standards
composer test                 # unit tests, no WordPress needed

npx @wordpress/env start      # local WordPress at http://localhost:8888 with the plugin active (admin / password)
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/$(basename "$PWD") vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Translation template: `wp i18n make-pot . languages/florank.pot --slug=florank --domain=florank --exclude=vendor,tests,build,bin,docs` (CI checks it is up to date).

## Build and release

```bash
./bin/build-zip.sh            # build/florank.zip with a single florank/ folder, no dev files
```

The version lives in three places that must match (the build script checks): `Version:` header and `FLORANK_VERSION` in `florank.php`, `Stable tag:` in `readme.txt`.

Release: bump the version, add a changelog entry to `readme.txt`, merge to `main`, then push a tag `vX.Y.Z`. The Release workflow builds `florank.zip` and attaches it to the GitHub Release.

CI on every pull request: PHP syntax (8.0 and 8.4), PHPCS, unit tests, integration tests (wp-env), POT check, zip build, [Plugin Check](https://github.com/WordPress/plugin-check-action).

## Links

- Product: https://florank.ai
- Terms: https://florank.ai/en/terms
- Privacy: https://florank.ai/en/privacy

## License

GPLv2 or later. See [LICENSE](LICENSE).
