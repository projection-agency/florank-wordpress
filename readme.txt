=== Florank ===
Contributors: florank
Tags: seo, ai, content, schema, indexnow
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish Florank articles to WordPress with Yoast / Rank Math SEO fields, images in the Media Library, JSON-LD and IndexNow.

== Description ==

[Florank](https://florank.ai) is an SEO + GEO platform: site audits, fixes, articles and monitoring of how ChatGPT, Gemini, Perplexity and Google AI mention your brand.

This plugin connects your WordPress site to your Florank account, so articles prepared in Florank land on your site without copy-paste.

* **Drafts or live posts.** Florank creates and updates posts as drafts, published or scheduled.
* **SEO fields.** SEO title and meta description are written for Yoast SEO and Rank Math.
* **Images.** The featured image and images inside the article are imported into the Media Library; the article links to the local copies.
* **Structured data.** Article JSON-LD (BlogPosting, FAQPage) is printed in the page head with the real permalink and dates, unless Yoast SEO or Rank Math already output schema.
* **IndexNow.** The plugin serves your Florank IndexNow key at `/{key}.txt`, so new URLs are submitted to Bing and other IndexNow engines right after publishing.
* **Author.** When the Florank author's email matches a WordPress user, that user becomes the post author.

The plugin only accepts requests signed with the API key you generate on the plugin page. The key is stored as a password hash.

A Florank account is required. See [florank.ai](https://florank.ai).

== Installation ==

1. In WordPress, go to Plugins → Add New → Upload Plugin and upload `florank.zip`. Activate the plugin.
2. Open the **Florank** menu item and click **Generate New API Key**. Copy the key — it is shown only once.
3. In Florank, open Content → Integrations → WordPress, enter your site URL and paste the key.

To rotate the key, generate a new one on the same page and paste it in Florank. The old key stops working immediately.

== Frequently Asked Questions ==

= Do I need Yoast SEO or Rank Math? =

No. With either plugin active, Florank writes its SEO title and description fields. Without them, the plugin still publishes posts and prints article JSON-LD.

= Will Florank publish without my approval? =

Florank publishes only what you send from your Florank account, as a draft or as a live post — you choose in Florank.

= I lost the API key. =

Generate a new key on the Florank page in WordPress and paste it in Florank. Keys cannot be shown again because only a hash is stored.

= What happens when I delete the plugin? =

The API key, the IndexNow key and the JSON-LD stored by Florank are removed. Your posts and images stay.

== Screenshots ==

1. Florank settings page with the API key.

== External services ==

This plugin works together with the Florank service (https://florank.ai), operated by Florank.

The plugin does not send data on its own. Florank servers (api.florank.ai) call this site's REST API at `/wp-json/florank/v1/` with the API key you generated:

* **Connection check** (`GET /health`): the plugin returns its version, the detected SEO plugin (Yoast SEO, Rank Math or none) and the site URL.
* **Posts** (`GET`, `POST`, `DELETE /posts`): Florank sends article title, slug, content, excerpt, status, SEO title and description, categories, images, JSON-LD, IndexNow key and author email. The plugin returns post ID, title, slug, status, link, modification date and featured image ID, and lists up to 50 recent posts with the same fields.

When Florank sends an image as a URL instead of file bytes, WordPress downloads it from that URL (normally Florank file storage).

The IndexNow key file at `/{key}.txt` is read by search engines that support IndexNow (for example Bing) after Florank submits your URLs.

* Florank Terms of Service: https://florank.ai/en/terms
* Florank Privacy Policy: https://florank.ai/en/privacy

== Changelog ==

= 2.0.0 =
* New plugin structure following the WordPress Plugin Handbook: classes under `src/`, uninstall cleanup, translations template.
* The plugin is now called "Florank".
* Text strings are translatable (text domain `florank`).
* Deleting the plugin removes its options, the IndexNow key and stored JSON-LD.
* No changes to the REST API: existing connections and API keys keep working after the update.

= 1.4.0 =
* Serve the Florank IndexNow key at /{key}.txt so published URLs are submitted to Bing instantly.
* Set the post author from the Florank author email when a matching WordPress user exists.

= 1.3.0 =
* Store Florank article JSON-LD (BlogPosting / FAQPage) and output it in <head> with the real permalink and dates when Yoast / Rank Math are not active.

= 1.2.0 =
* Import featured and inline images from Florank as file bytes (not a remote URL fetch). Sets an admin user so Media Library upload is allowed.

= 1.1.0 =
* Sideload featured and inline images from Florank into the Media Library and rewrite post HTML to local attachment URLs.

= 1.0.0 =
* API key, posts CRUD, Yoast / Rank Math meta, featured image sideload.

== Upgrade Notice ==

= 2.0.0 =
New internal structure and translations. The API key and the connection to Florank keep working — no action needed.
