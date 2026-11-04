=== Florank ===
Contributors: florank
Tags: seo, ai, content, schema, indexnow
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish Florank articles to WordPress with Yoast / Rank Math SEO fields, images in the Media Library, JSON-LD and IndexNow.

== Description ==

[Florank](https://florank.ai) is an SEO + GEO platform: site audits, fixes, articles and monitoring of how ChatGPT, Gemini, Perplexity and Google AI mention your brand.

This plugin connects your WordPress site to your Florank account, so articles prepared in Florank land on your site without copy-paste.

* **Drafts or live posts.** Florank creates and updates posts as drafts, published or scheduled.
* **SEO fields.** SEO title and meta description are written for Yoast SEO and Rank Math.
* **Images.** The featured image and images inside the article are imported into the Media Library; the article links to the local copies. Updating an article reuses images that are already imported.
* **Structured data.** Article JSON-LD (BlogPosting, FAQPage) is printed in the page head with the real permalink and dates, unless Yoast SEO or Rank Math already output schema.
* **IndexNow.** The plugin serves your Florank IndexNow key at `/{key}.txt`, so new URLs are submitted to Bing and other IndexNow engines right after publishing.
* **Author.** When the Florank author's email matches a WordPress user, that user becomes the post author.

The plugin only accepts requests signed with the API key you generate on the plugin page. The key is stored as a password hash.

Requests from Florank run as a WordPress user you choose on the plugin page (by default, the administrator who created the key), with that user's permissions. Only JPEG, PNG, WebP, GIF and AVIF images up to 10 MB are imported, and images are downloaded only from Florank file storage over HTTPS.

A Florank account is required. See [florank.ai](https://florank.ai).

== Installation ==

1. In WordPress, go to Plugins → Add New → Upload Plugin and upload `florank.zip`. Activate the plugin.
2. Open the **Florank** menu item and click **Create key**. Copy the connection code — it is shown only once.
3. In Florank (https://app.florank.ai), open Content → Integrations → WordPress and paste the code. Older Florank versions ask for the site URL and the key: use **Show the key separately**.
4. The plugin page shows **Connected** after the first request from Florank.

To rotate the key, click **New key** and paste the new code in Florank. The old key stops working immediately. **Revoke key** disconnects Florank.

== Frequently Asked Questions ==

= Do I need Yoast SEO or Rank Math? =

No. With either plugin active, Florank writes its SEO title and description fields. Without them, the plugin still publishes posts and prints article JSON-LD.

= Will Florank publish without my approval? =

Florank publishes only what you send from your Florank account, as a draft or as a live post — you choose in Florank.

= Which WordPress user publishes the articles? =

The user shown under **Publishing as** on the Florank page — by default, the administrator who created the key. You can choose any user who can edit posts and upload files. If that user is deleted or loses these permissions, Florank gets a clear error until you choose another user.

= Can I allow images from another host? =

Yes, with the `florank_allowed_image_hosts` filter (array of host names; `*.example.com` matches subdomains). The size limit can be changed with `florank_max_image_bytes`.

= I lost the API key. =

Generate a new key on the Florank page in WordPress and paste it in Florank. Keys cannot be shown again because only a hash is stored.

= What happens when I delete the plugin? =

The API key, the selected user, the IndexNow key and the data stored by Florank (JSON-LD, markers of Florank articles and images) are removed. Your posts and images stay.

== Screenshots ==

1. Florank page: create a key, paste it in Florank, connection status, the user Florank publishes as and the latest articles.

== External services ==

This plugin works together with the Florank service (https://florank.ai), operated by Florank.

The plugin does not send data on its own. Florank servers (api.florank.ai) call this site's REST API at `/wp-json/florank/v1/` with the API key you generated:

* **Connection check** (`GET /health`): the plugin returns its version, the detected SEO plugin (Yoast SEO, Rank Math or none) and the site URL.
* **Posts** (`GET`, `POST`, `DELETE /posts`): Florank sends article title, slug, content, excerpt, status, SEO title and description, categories, images, JSON-LD, IndexNow key and author email. The plugin returns post ID, title, slug, status, link, modification date and featured image ID, and lists up to 50 recent posts with the same fields.

When Florank sends an image as a URL instead of file bytes, WordPress downloads it over HTTPS from Florank file storage (Vercel Blob, `*.blob.vercel-storage.com`) or florank.ai. Images on other hosts are not downloaded.

The IndexNow key file at `/{key}.txt` is read by search engines that support IndexNow (for example Bing) after Florank submits your URLs.

* Florank Terms of Service: https://florank.ai/en/terms
* Florank Privacy Policy: https://florank.ai/en/privacy

== Credits ==

The settings page uses the fonts Geist (Vercel), Wix Madefor Text (Wix) and Anonymous Pro (Mark Simonson), bundled in `assets/fonts` under the SIL Open Font License 1.1 (license files next to the fonts). No fonts are loaded from external servers.

== Changelog ==

= 2.1.0 =
* Security: requests from Florank no longer run as the first administrator. They run as the user linked to the key (the administrator who created it, changeable on the plugin page) and WordPress permissions apply. Keys created with 1.x are linked to the first administrator once, on the first request or admin page load after the update; the plugin page shows and lets you change that user.
* Security: images are downloaded only over HTTPS from Florank hosts (filter `florank_allowed_image_hosts`); other image URLs are left as they are.
* Security: imported images are limited to JPEG, PNG, WebP, GIF and AVIF up to 10 MB (filter `florank_max_image_bytes`); the real file type is checked.
* Updating an article reuses images imported before instead of adding copies to the Media Library.
* New Florank page: create a key and copy one connection code (site address + key) for Florank, connection status, the user Florank publishes as, the latest 10 articles from Florank, revoke the key.
* Ukrainian translation.
* Posts created by Florank now have the linked user as author (unless the Florank author email matches another user).
* No changes to the REST API routes or the key: existing connections keep working after the update.

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

= 2.1.0 =
Security update: Florank publishes as a user you choose instead of the first administrator. Existing keys keep working.

= 2.0.0 =
New internal structure and translations. The API key and the connection to Florank keep working — no action needed.
