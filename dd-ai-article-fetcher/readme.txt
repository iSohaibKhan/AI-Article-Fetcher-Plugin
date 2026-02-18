=== Digital Doer AI Article Fetcher ===
Contributors: your-name
Tags: ai, news, rest api, external images, categories, cron
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bridge WordPress with an external AI micro‑service to fetch, rewrite, and publish news articles. Provides clean REST endpoints (/import, /categories), external (FIFU‑style) featured images, optional queue/cron pull mode, and a simple admin UI.

== Description ==

**Digital Doer AI Article Fetcher** lets you connect any AI content generator (Python, Node, etc.) with WordPress:

* **POST /import** – send ready HTML (title, content, meta_desc, tags, image URL) and instantly publish.
* **GET/POST /categories** – list or create WP categories from your service.
* **External thumbnail (FIFU-style)** – no media sideload; store URL and auto-render.
* **Optional Pull Mode** – queue URLs in WP, cron calls AI endpoint, publishes posts.

**No hardcoded URLs**: Every site can set its own Bearer token / API base URL in Settings.

== Features ==

* Custom REST API:
  * `POST /wp-json/dd-ai-article-fetcher/v1/import`
  * `GET  /wp-json/dd-ai-article-fetcher/v1/categories`
  * `POST /wp-json/dd-ai-article-fetcher/v1/categories`
* External featured image via meta key `ddaaf_image_url`.
* Admin pages: Fetch form, History log, Settings.
* Queue table `wp_dd_requests` with cron processor (if you choose pull mode).
* Bearer token authentication for all plugin routes.

== Installation ==

1. Upload the folder `dd-ai-article-fetcher` to `/wp-content/plugins/`.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. Go to **AI News Hub → Settings** and:
   * (Pull mode) Set **API Base URL** + **Bearer Token** (issued by your AI service).
   * (Push mode) Just set **Bearer Token** (the AI service will call you).
4. Optionally visit **AI News Hub → Fetch Article** to queue a URL manually.
5. Share the REST spec with your AI developer (see below).

== REST API Spec (Quick) ==

**Create Post**

- **POST** `/wp-json/dd-ai-article-fetcher/v1/import`  
- **Headers:**  
  `Authorization: Bearer <TOKEN>`  
  `Content-Type: application/json`  

- **Body Example:**
{
"title": "Rewritten headline",
"content_html": "<p>Full HTML body…</p>",
"meta_desc": "Short description…",
"tags": ["tech","ai"],
"category_id": 12,
"category_name": "Technology",
"featured_image_url": "https://cdn.com/img.jpg"
}

- **Response:**
{ "ok": true, "post_id": 123, "message": "Published" }


**List Categories**

- **GET** `/wp-json/dd-ai-article-fetcher/v1/categories`  
- **Headers:** `Authorization: Bearer <TOKEN>`

**Create Category**

- **POST** `/wp-json/dd-ai-article-fetcher/v1/categories`  
- **Body:** `{ "name":"World", "slug":"world" }`

== Frequently Asked Questions ==

= Do I need FIFU plugin installed? =  
No. This plugin injects an `<img>` tag when `_thumbnail_id` is missing but `ddaaf_image_url` meta exists.

= Can I skip the cron/queue? =  
Yes. Use only the `/import` route (push mode). Disable or ignore the queue page.

= How do I secure the API? =  
Use the Bearer token field in Settings. The plugin rejects requests without the correct token.

= What about get_posts / update_post / delete_post? =  
Use WordPress core REST endpoints `/wp-json/wp/v2/posts`. Add wrappers only if your AI client can’t change.

== Screenshots ==

1. Fetch Article screen
2. History log of queued jobs
3. Settings page (API URL & token)
4. External thumbnail visible in post list

== Changelog ==

= 1.0.0 =
* Initial release of Digital Doer AI Article Fetcher.

== Upgrade Notice ==

= 1.0.0 =
First public version. Review the REST spec before upgrading from any prototype.

== License ==

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License version 2, as published by the Free Software Foundation.

============================================================
== SECURITY AUDIT REPORT: DIGITAL DOER AI ARTICLE FETCHER ==
============================================================
Date: 2026-02-10 Plugin Version: 1.0 (Analyzed) Verdict: SAFE for production use.

EXECUTIVE SUMMARY:
The "Digital Doer AI Article Fetcher" plugin adheres to WordPress security best practices. The codebase demonstrates a strong understanding of input sanitization, output escaping, and database security. No critical vulnerabilities (SQL Injection, XSS, RCE, or CSRF) were found. The authentication mechanism for the REST API is secure, using standard Bearer tokens and timing-safe comparisons.

DETAILED FINDINGS:
1. Authentication & Authorization (Score: Low Risk)
- Method: The plugin uses a simplistic but effective Bearer token system (DDAAF_Auth).
- Security: Token comparison uses hash_equals(), which prevents timing attacks. This is a positive find.
- Access Control: REST endpoints correctly use a permission_callback. Admin users (manage_options capability) are allowed bypass, which is standard for plugin administration but means an admin account compromise leads to full plugin control (expected behavior).
- Observation: The token is stored as a plain text option (ddaaf_api_token). While hashing it in the DB would be "better," it is retrievable by admins anyway, so the risk is minimal unless the database alone is leaked.

2. SQL INJECTION (SCORE: SECURE)
- Dynamic Tables: The plugin creates tables dynamically (wp_dd_queue_{id}). The table names are constructed using intval($cat_id), ensuring no malicious SQL can be injected via the table name.
- Queries: All database queries use $wpdb->prepare() or strictly typed variables (e.g., intval()) before interpolation.
- Cleanup: uninstall.php carefully drops tables by looking up the map, preventing accidental deletion of other tables.

3. CROSS-SITE SCRIPTING (XSS) (SCORE: SECURE)
- Input Handling: User inputs are sanitized using sanitize_text_field and sanitize_title.
- HTML Content: The imported article content is passed through wp_kses_post(). This is the WordPress standard function that strips dangerous tags (scripts, iframes, etc.) while allowing safe formatting. This effectively neutralizes Stored XSS attacks from a potentially compromised AI source.
- Output Escaping: Admin pages consistently use esc_html(), esc_attr(), and esc_url() when outputting variables.

4. CROSS-SITE REQUEST FORGERY (CSRF) (SCORE: SECURE)
- Admin Actions: Form submissions (Settings, Fetch, Run Now) are protected with wp_nonce_field() and verified with check_admin_referer() or wp_verify_nonce().
- REST API: The REST endpoints are intended for server-to-server communication (Bearer token). Standard cookie-based access (if used) is protected by WordPress's built-in REST nonce system.

5. SERVER-SIDE REQUEST FORGERY (SSRF) (SCORE: LOW RISK)
- Mechanism: The plugin connects to an API URL defined in settings.
- Risk: An administrator can set this to any URL. This allows the server to make requests to internal networks if the admin ignores safety. This is an "Admin-only" feature and considered a configuration choice, not a vulnerability.

RECOMMENDATIONS (OPTIONAL HARDENING):
While the plugin is safe, the following minor improvements could further harden it:

1. Rate Limiting: The POST /import endpoint doesn't seem to have specific rate limiting beyond standard server limits. If the token is leaked, an attacker could flood the site with posts. Mitigation: rely on WAF or add a simple internal rate limiter.
2. API Token Rotation: There is no UI to "roll" the token (generate a new random one). The admin has to manually type/paste a new one. Mitigation: Add a "Generate New Token" button in the future.
3. Strict Type Checking for API URL: Ensure ddaaf_api_url is not just esc_url_raw but also validates against a known allowlist if the AI service domain is static. If the user can bring their own endpoint, then the current implementation is correct.

CONCLUSION:
The Digital Doer AI Article Fetcher is well-engineered from a security perspective. It avoids common pitfalls and leverages WordPress core security API correctly. It is SAFE to install and use.
