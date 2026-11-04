=== Florank ===
Contributors: florank
Tags: seo, ai, content, schema, indexnow
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish articles from Florank to WordPress as drafts, with Yoast / Rank Math SEO fields, images in the Media Library, JSON-LD and IndexNow.

== Description ==

[Florank](https://florank.ai) is an SEO + GEO platform: site audits, fixes, articles and monitoring of how ChatGPT, Gemini, Perplexity and Google AI mention your brand.

This plugin connects your WordPress site to your Florank account. Articles you prepare in Florank appear on your site as WordPress posts, without copy-paste. You connect the site once with a single connection code.

What the plugin does:

* **Publishes articles as drafts.** Florank creates and updates posts. By default they arrive as drafts, so you review them in WordPress and publish yourself. You can switch to direct publishing in Florank.
* **Fills SEO fields.** The SEO title and meta description go to Yoast SEO or Rank Math, if one of them is active.
* **Imports images.** The featured image and the images inside the article are saved to your Media Library, and the article uses the local copies. Updating an article reuses images that are already imported.
* **Adds structured data.** Article JSON-LD (BlogPosting, FAQPage) is printed in the page head with the real link and dates, unless Yoast SEO or Rank Math already output schema.
* **Speeds up indexing.** The plugin serves your Florank IndexNow key at `/{key}.txt`, so Bing and other IndexNow search engines learn about new URLs right after publishing.
* **Keeps the author.** When the email of the Florank author matches a WordPress user, that user becomes the post author.

Safety:

* Florank can only call the plugin with the API key you create on the plugin page. The key is stored as a password hash and can be revoked at any time.
* Requests from Florank run as the WordPress user you choose on the plugin page (by default, the administrator who created the key), with that user's permissions.
* Only JPEG, PNG, WebP, GIF and AVIF images up to 10 MB are imported. Images are downloaded only over HTTPS from Florank file storage.

A Florank account is required. See [florank.ai](https://florank.ai).

== Installation ==

1. Install the plugin from Plugins → Add New (search for "Florank") or upload `florank.zip`, then activate it.
2. Open **Florank** in the WordPress menu and click **Create key**. Copy the connection code — it is shown only once.
3. In Florank (https://app.florank.ai), open Content → Integrations → WordPress and paste the code. The plugin page shows **Connected** after the first request from Florank.

To change the key, click **New key** and paste the new code in Florank; the old key stops working immediately. Older Florank versions ask for the site address and the key separately: use **Show the key separately** on the plugin page.

== Frequently Asked Questions ==

= Do I need a Florank account? =

Yes. The plugin only receives articles from Florank and does nothing without an account. Sign up at [florank.ai](https://florank.ai).

= What data is sent and where? =

The plugin does not send data on its own. Florank servers call your site's REST API with your key to check the connection and to create, update and list the posts it publishes. The full list of data is in the **External services** section below.

= Will Florank publish posts without my approval? =

No. By default Florank sends articles as drafts, and you publish them in WordPress. Posts go live right away only if you turn on direct publishing in Florank or choose it for a specific article.

= How do I disconnect Florank? =

Click **Revoke key** on the Florank page in WordPress: Florank can no longer reach the site. Or deactivate the plugin. Posts already published stay on your site.

= Do I need Yoast SEO or Rank Math? =

No. With either plugin active, Florank fills its SEO title and description fields. Without them, the plugin still publishes posts and prints article JSON-LD.

= Which WordPress user publishes the articles? =

The user shown under **Publishing as** on the Florank page — by default, the administrator who created the key. You can choose any user who can edit posts and upload files. If that user is deleted or loses these permissions, Florank gets a clear error until you choose another user.

= Can I allow images from another host? =

Yes, with the `florank_allowed_image_hosts` filter (array of host names; `*.example.com` matches subdomains). The size limit can be changed with `florank_max_image_bytes`.

= I lost the connection code. =

Click **New key** on the Florank page in WordPress and paste the new code in Florank. The code cannot be shown again because only a hash of the key is stored.

= What happens when I delete the plugin? =

The API key, the selected user, the IndexNow key and the data stored by Florank (JSON-LD, markers of Florank articles and images) are removed. Your posts and images stay.

== Screenshots ==

1. Before connecting: create a key on the Florank page.
2. The connection code to paste in Florank, shown once.
3. Connected: status, the user Florank publishes as and the latest articles from Florank.
4. Choose the WordPress user Florank publishes as.

== External services ==

This plugin connects your site to Florank (https://florank.ai), an SEO and content service operated by Florank. It is needed to receive articles from your Florank account.

The plugin does not send data on its own and does not track visitors. All requests come from Florank servers (api.florank.ai) to your site's REST API at `/wp-json/florank/v1/`, signed with the API key you created, and only while the key is active:

* **Connection check** (`GET /health`, then `POST /posts` and `DELETE /posts/{id}`), when you connect the site in Florank or check the connection. The plugin returns its version, the detected SEO plugin (Yoast SEO, Rank Math or none) and the site address. To make sure publishing works, Florank creates an empty test draft and deletes it permanently right away.
* **Publishing** (`POST /posts`, `POST /posts/{id}`), when you publish or update an article in Florank. Florank sends the article title, slug, content, excerpt, status, SEO title and description, categories, images, JSON-LD, the IndexNow key and the email of the article author in Florank. The plugin returns the post ID, title, slug, status, link, modification date and featured image ID.
* **Post list** (`GET /posts`, `GET /posts/{id}`), when Florank shows or syncs your articles. The plugin returns the same post fields, for up to 50 recent posts.

When Florank sends an image as a link instead of file bytes, your site downloads it over HTTPS from Florank file storage: Vercel Blob (`*.blob.vercel-storage.com`), florank.ai or api.florank.ai. Images on other hosts are not downloaded.

After publishing, Florank submits the post URL to IndexNow. Search engines that support IndexNow (for example Bing) then read the key file at `/{key}.txt` on your site.

To check the SEO tags of published articles, Florank may also open their public pages, like any visitor.

* Florank Terms of Service: https://florank.ai/en/terms
* Florank Privacy Policy: https://florank.ai/en/privacy

== Credits ==

The settings page uses the fonts Geist (Vercel), Wix Madefor Text (Wix) and Anonymous Pro (Mark Simonson), bundled in `assets/fonts` under the SIL Open Font License 1.1 (license files next to the fonts). No fonts are loaded from external servers.

== Changelog ==

= 2.1.1 =
* Prepared for the WordPress.org plugin directory: plain-language description, installation in three steps, FAQ, screenshots, a more detailed External services section.
* No code changes: the REST API, the key and the connection code work as in 2.1.0.

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

= 2.1.1 =
Description and documentation update. No changes to how the plugin works.

= 2.1.0 =
Security update: Florank publishes as a user you choose instead of the first administrator. Existing keys keep working.

= 2.0.0 =
New internal structure and translations. The API key and the connection to Florank keep working — no action needed.
