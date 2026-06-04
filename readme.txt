=== Zeroa Analytics ===

Contributors: webguyio
Donate link: https://webguy.io/donate
Tags: analytics, statistics, privacy, gdpr, ccpa
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1
License: CC0
License URI: https://creativecommons.org/public-domain/cc0/

Lightweight, GDPR-compliant analytics. No cookies, no personal data, no consent banner required.

== Description ==

[💬 Ask Question](https://github.com/webguyio/zero-analytics/issues) | [📧 Email Me](mailto:webguywork@gmail.com)

Zeroa Analytics gives you the traffic insights that matter: unique visitors, pageviews, top pages, referrer sources, devices, and countries (without ever collecting personal data).

No one ever said tracking people HAD to be creepy. By definition, most analytics tools are in fact super creepy (and in many cases, actually illegal), but it's also possible to simply want to know how much monthly traffic you get, what content people like, and what countries you're popular in, generally, without violating personal data.

**Privacy by Design**

* No cookies, no local storage, no fingerprinting
* IP addresses are never stored
* Unique visitor detection uses a salted SHA-256 hash that rotates and is deleted daily
* Referrers are classified into known categories only (raw referrer URLs are never stored)
* Query strings are stripped from all recorded URLs
* Respects Global Privacy Control (visitors who opt out are not tracked)
* No external services or third-party calls
* No consent banner required (GDPR, CCPA, PECR compliant)

**Features**

* Unique visitors, pageviews, errors, and bot traffic summary
* Top pages ranked by pageviews
* Referrer breakdown: search engines, social platforms, internal, and direct
* Country tracking via CDN/server headers (Cloudflare, mod_geoip, etc.)
* Device type detection (desktop, mobile, tablet)
* Bot traffic tracked separately and excluded from main stats
* Date range filters: last 7, 30, 90 days, and all time
* Dashboard widget with 30-day summary
* Lightweight tracking pixel
* Compatible with all caching configurations including full-page cache

== Installation ==

1. Upload the `zeroa-analytics` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Visit *Dashboard > Analytics* in your WordPress admin menu to view your stats.

No configuration is required. Analytics begins collecting immediately on activation.

**Country tracking:** Country data is collected automatically if your server or CDN provides a country header (e.g. Cloudflare's `CF-IPCountry`, Apache's `GEOIP_COUNTRY_CODE`, or similar). If no such header is available, country will show as "Unavailable".

== Frequently Asked Questions ==

= How accurate are the stats? =

No analytics tool is 100% accurate. Even Google Analytics can undercount by 20-40% on tech-savvy audiences due to ad blockers and consent banner declines. Zeroa Analytics captures traffic that cookie-based tools miss entirely, but some visitors will never be counted regardless of which tool you use.

What the stats are reliably good for is identifying signals: which pages are most popular, where your traffic comes from, which referrers are sending visitors, and how trends change over time. These relative patterns are consistent and actionable even when the overall numbers aren't perfect.

= Why aren't my visits being recorded? =

A few common reasons: you may be logged in as an editor or admin (test logged out in a private window); your browser may have Global Privacy Control enabled (Firefox, Brave, and some privacy add-ons have this feature); or your IP has already been counted as unique today and revisits reset at midnight UTC.

= Do I need a cookie consent banner? =

No. Zeroa Analytics does not use cookies, localStorage, or any client-side storage. No personal data is processed or stored. You do not need a consent banner for this plugin under GDPR, CCPA, or PECR.

= How are unique visitors counted without cookies? =

A temporary SHA-256 hash is generated from a daily rotating salt and the visitor's IP. This hash is used only to deduplicate requests within a single day and is never stored in the database. The salt is deleted and replaced at midnight each day, making cross-day tracking impossible.

= Is any personal data ever collected? =

No. The only time anything resembling personal data is touched is during unique visitor detection, where a visitor's IP is briefly combined with a daily-rotating salt and your site URL to produce a one-way SHA-256 hash. This hash cannot be reversed, decoded, or used to identify anyone. It is stored temporarily as a transient and expires at midnight. Neither the IP nor the hash ever touch the analytics database. Site administrators cannot see visitor IPs or any other personal data, even if they wanted to.

= Why is my country showing as "Unavailable"? =

Country detection relies on your server or CDN injecting a country code header. This works automatically on Cloudflare, and on servers running Apache with mod_geoip. If neither is present, the country cannot be determined without storing IP addresses, which would compromise privacy.

= Are bots tracked? =

Yes, but they are excluded from all main statistics. Bot traffic is recorded separately and visible in the Bots section at the bottom of the Analytics page.

= Does this plugin work with caching plugins? =

Yes. Zeroa Analytics uses a lightweight tracking pixel that fires on every page load. Error pages (404s, 500s) are additionally captured server-side for full status code context.

== Changelog ==

= 0.1 =
* New