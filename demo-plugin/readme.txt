=== Blockendar Demo Content ===
Contributors: philhoyt
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fills a site with demo events and a guided tour of Blockendar's blocks. For demos and testing, not production.

== Description ==

Companion plugin for Blockendar. Activating it creates event types, venues, 31
events and a six-page guided tour linking the plugin's features together.

Every date is generated relative to the moment you seed, so the demo never goes
stale the way an imported WXR export does.

Manage it from Tools > Blockendar Demo, or with `wp blockendar-demo seed` and
`wp blockendar-demo reset`.

Reset removes only what the demo created, and restores your previous front page
setting. Content of your own is never touched.

Requires Blockendar 1.7.0 or newer.

== Changelog ==

= 1.7.0 =
* Changed: the tour pages are rebuilt. The calendar, the filtered grid and the venue maps render at wide width, Type, Venue and Dates share one row with the view switcher at the end, and each page is laid out in groups with consistent spacing.
* Added: every demo event has a featured image. The bundled set grew from eight images to sixteen so thumbnails repeat less often.
* Changed: seeding over an existing demo rebuilds the tour pages rather than doing nothing, so a demo installed before this release picks up the new layout. The events are left alone; reset first if you want a fresh dataset.
* Changed: the demo now needs Blockendar 1.7.0 or newer. The filter row relies on the filter blocks being valid inside a Group, which earlier versions did not allow.

= 1.6.0 =
* No changes. The version tracks Blockendar so the two ship together from the same tag.

= 1.5.0 =
* Initial release.
