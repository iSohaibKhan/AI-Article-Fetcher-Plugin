# Digital Doer AI Article Fetcher (Plugin)

A WordPress plugin that bridges your site with an external AI micro-service to automatically fetch, rewrite, and publish articles via REST API.

## What It Does

This plugin exposes secure REST API endpoints on your WordPress site, allowing an external AI service to:

- **Create categories** and **list existing ones**
- **Import articles** as published posts (with title, HTML content, excerpt, tags, and custom slug)
- **Set featured images** via external URL or attachment ID

It also includes a **cron-based queue system** — you can queue source URLs from the WP admin dashboard, and the plugin will automatically send them to your AI service for processing and publish the results.

## How It Works

```
┌─────────────┐        REST API          ┌──────────────────┐
│  External   │ ──────────────────────▶  │    WordPress     │
│  AI Service │  Bearer Token Auth       │  (This Plugin)   │
└─────────────┘                          └──────────────────┘
       ▲                                          │
       │         Cron Queue (Pull Mode)           │
       └──────────────────────────────────────────┘
```

**Push Mode:** Your AI service sends finished articles directly to the `/import` endpoint.

**Pull Mode:** You queue URLs in WP Admin → Plugin sends them to your AI service via cron → AI responds → Plugin publishes the post automatically.

## REST API Endpoints

All custom endpoints use **Bearer Token** authentication.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/dd-ai-article-fetcher/v1/categories` | List all categories |
| `POST` | `/dd-ai-article-fetcher/v1/categories` | Create a category |
| `POST` | `/dd-ai-article-fetcher/v1/import` | Import/publish a post |
| `POST` | `/dd-ai-article-fetcher/v1/featured/{post_id}` | Set featured image |

### Import Post Example

```bash
curl -X POST "https://your-site.com/wp-json/dd-ai-article-fetcher/v1/import" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "My AI Article",
    "content_html": "<p>Article content here.</p>",
    "meta_desc": "SEO description",
    "post_slug": "custom-url-slug",
    "category_name": "Technology",
    "tags": ["AI", "Tech"],
    "featured_image_url": "https://example.com/image.jpg"
  }'
```

> **Note:** If `/wp-json/` returns 404 on your server, use the fallback format:
> `https://your-site.com/?rest_route=/dd-ai-article-fetcher/v1/import`

## Features

- 🔐 **Secure** — Bearer token auth with timing-safe comparison (`hash_equals`)
- 📂 **Per-category queues** — Each category gets its own queue table
- 🖼️ **External featured images** — FIFU-style support without extra plugins
- 🏷️ **Auto-tagging** — Optional tag assignment for imported posts
- 🔗 **Custom slugs** — Pass `post_slug` to control the post URL
- ⏰ **Cron processing** — Hourly queue processing with manual "Run Now" option
- 🛡️ **WordPress standards** — Proper nonce verification, input sanitization, and output escaping

## Installation

1. Download or clone this repository
2. Upload the `dd-ai-article-fetcher` folder to `/wp-content/plugins/`
3. Activate the plugin in **Plugins** → **Installed Plugins**
4. Go to **AI News Hub** → **Settings** and configure your API URL and Bearer Token

## Requirements

- WordPress 5.0+
- PHP 7.4+

## License

GPL-2.0+ — See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)
