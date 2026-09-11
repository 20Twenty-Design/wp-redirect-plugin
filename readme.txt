=== 20Twenty Redirect ===
Contributors: 20twenty
Tags: 410, gone, redirect, 301, csv
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Retire dead URLs in bulk. Import a CSV of links and serve them as 410 Gone, or flip any line item to a 301 redirect.

== Description ==

Import a CSV of retired links and 20Twenty Redirect answers every one of them with `410 Gone` — the status that tells search engines a URL is dead on purpose and is not coming back. Any line item can be changed to a 301, 302, 307 or 308 redirect with a destination, or to 403, 404 or 451.

Everything lives on one screen under **Settings → 20Twenty Redirect**.

* **Master switch.** Pause every rule at once without deleting anything.
* **CSV import.** Upload a file or paste a list. Headers are detected, delimiters are sniffed (comma, semicolon, tab, pipe), and re-importing a source updates the existing rule instead of duplicating it.
* **Manual rules.** Add one URL at a time, edit any row inline, delete rows individually or in bulk.
* **Per-rule responses.** Switch a rule between 410 Gone and a redirect whenever the plan changes.
* **Regular expressions.** Optional pattern rules with `$1` backreferences in the destination.
* **Hit tracking.** See how often each rule fires and when it last did.
* **CSV export** of everything, including hit counts.
* **URL tester** that shows which rule a given URL would match.

= CSV format =

    source,target,status,notes
    /old-campaign-2019,,410,Killed after the campaign ended
    https://example.com/discontinued-product,,410,
    /blog/old-post,/blog/new-post,301,Rewritten

Only `source` is required. A row with a destination but no status becomes a 301. A row with neither uses the default response from the Settings tab. Column names are flexible: `url`, `from` and `old` all mean `source`; `to`, `new` and `destination` all mean `target`.

Sources are stored host-relative, so `https://example.com/page`, `//example.com/page` and `/page` are the same rule.

= Theming the Gone page =

The plugin ships a plain, no-index page for 410, 403, 451 and 404 responses; its heading and message are editable in Settings. To take over completely, add a `410.php` template to your theme.

= Performance =

Matching is a single indexed lookup on an MD5 of the normalised request path, so the cost does not grow with the number of rules. Regex rules are cached for an hour. On very high-traffic sites, turn off hit tracking to remove the one write per matched request, or set matching to run only on 404s.

== Frequently Asked Questions ==

= Why 410 instead of 404? =

A 404 says "not here right now"; crawlers keep checking. A 410 says "deliberately removed", and search engines drop the URL faster.

= What happens to my rules if I deactivate the plugin? =

They stay in the database and come back on reactivation. Deleting the plugin from the plugins screen removes the table and its options.

= Does it work with a page that still exists? =

Yes, when matching runs on every request (the default). A rule always beats existing content. Choose the 404-only mode if you want existing pages to win.

= Can I redirect to another domain? =

Yes. Destinations are set by an administrator, so external URLs are allowed.

== Screenshots ==

1. The rules list with the master switch and hit counts.
2. Importing a CSV of retired URLs.
3. Settings.

== Changelog ==

= 1.1.1 =
* Maintenance release to confirm updates arrive from GitHub.

= 1.1.0 =
* Updates are now delivered from GitHub releases through the normal WordPress update screens.

= 1.0.0 =
* First release.
