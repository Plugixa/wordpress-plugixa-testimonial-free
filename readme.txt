=== Plugixa Testimonial – Customer Reviews, Star Ratings & Social Proof ===
Contributors: zakaria04, freemius
Tags: testimonials, customer reviews, star ratings, reviews, social proof
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage and showcase customer testimonials with star ratings, photos, an approval workflow, grid and list layouts and review schema.

== Description ==

⭐ Approve and showcase what your customers say with **Plugixa Testimonial** - star ratings, photos and an approval workflow, in a fast React admin, displayed in grid or list layouts.

📚 Read the [**Documentation**](https://www.plugixa.com/documentation/plugixa-testimonial)
🌐 Visit the official website [**Plugixa**](https://www.plugixa.com/)
✉️ Questions or support requests: [**Contact us**](https://www.plugixa.com/contact)

[__Documentation__](https://www.plugixa.com/documentation/plugixa-testimonial) | [__Contact__](https://www.plugixa.com/contact) | [__Support Forum__](https://wordpress.org/support/plugin/plugixa-testimonial/) | [__Upgrade to Pro!__](https://www.plugixa.com/plugins/plugixa-testimonial#pricing)

Show your first testimonials in **3 simple steps**:

1. Add your testimonials
2. Approve the ones you want to publish
3. Build a display with the live preview and place it with a shortcode

Testimonials live in the plugin's **own database tables**, not in posts and post meta, so lists, filters and displays stay fast as your collection grows.

### Testimonials You Have Actually Approved

Nothing appears on your site until you say so. Every testimonial moves through a clear workflow, and visitors only ever see the approved ones.

✅ **Pending** - waiting for your decision
✅ **Approved** - published, and the only status any display can show
✅ **Rejected** - kept for your records, never shown
✅ **Draft** - testimonials you are still writing up

= Key Features of Plugixa Testimonial =

* Fast, Material UI-based admin application
* Unlimited testimonials, categories and saved displays (views) - no cap
* Star ratings, client photos, role, company and website
* Approval workflow: pending, approved, rejected and draft
* Categories to organize and filter testimonials
* Grid and list layouts
* Display builder with a live preview, colours, typography and spacing
* Category and rating filters, and pagination
* Shortcodes for placing testimonials anywhere
* Social-proof notification popups
* Review schema (JSON-LD) for SEO
* Multilingual support via Polylang and WPML
* Works with your WordPress roles: authors can approve, editors can delete, settings stay with administrators
* Fonts are served from your own site, never from Google's servers

= Plugixa Testimonial Pro Features =

* Slider and masonry layouts
* Video testimonials, custom fields, and CSV and JSON import and export
* Frontend submission form with reCAPTCHA, testimonial request emails and email notifications
* Elementor widget, block editor block and a WooCommerce product tab
* Review import from Google, Facebook and WooCommerce, with an optional daily sync
* PDF export, demo data and priority support

[Upgrade to Plugixa Testimonial Pro](https://www.plugixa.com/plugins/plugixa-testimonial#pricing)

= 🎨 One Display Builder =

Choose grid or list, then adjust colours, typography, photo position, ratings, category and rating filters and pagination with a live preview beside you. Each display is saved, so the same look can be placed on any number of pages, and changing it once updates it everywhere.

= 🔎 Review Schema for Search Engines =

Displays can print Review and AggregateRating structured data (JSON-LD) built from your approved testimonials, so search engines can understand your ratings.

= 🌍 Multilingual From the Start =

With Polylang or WPML active, each testimonial, category and display can be translated, and translations of the same testimonial are grouped together. Visitors see the version in their language.

### 👨‍💻 Author

[**Plugixa Testimonial**](https://www.plugixa.com/plugins/plugixa-testimonial) is developed by [**Plugixa**](https://www.plugixa.com/), building focused, well-documented WordPress plugins with an emphasis on performance, clean code and correct data.

### 🛟 Documentation & Support

Plugixa Testimonial is built to be usable without a manual, but it is documented in full.

👉 Read the [Documentation](https://www.plugixa.com/documentation/plugixa-testimonial) - getting started, displays and the shortcodes
👉 Ask on the [WordPress.org support forum](https://wordpress.org/support/plugin/plugixa-testimonial/)
👉 Or [contact us directly](https://www.plugixa.com/contact)
👉 Browse more plugins at [plugixa.com](https://www.plugixa.com/)

= Shortcode Usage =

Create a display under Testimonials > Shortcodes. The screen shows the exact tag for each display, ready to copy.

Show a saved display:
`[plugixa_testimonial id="1"]`

Or skip the saved display and pass attributes directly:
`[plugixa_testimonial view="grid" limit="6" min_rating="4" orderby="rating" order="DESC"]`


== Installation ==

= MINIMUM REQUIREMENTS =

* WordPress 6.5 or greater
* PHP version 8.2 or greater

= AUTOMATIC INSTALLATION (EASIEST WAY) =

1. In your WordPress admin, go to Plugins > Add New
2. Search for "Plugixa Testimonial"
3. Click Install Now, then Activate
4. Open "Testimonials" in the admin menu and add your first testimonial

= MANUAL INSTALLATION =

1. Download the plugin ZIP
2. Go to Plugins > Add New > Upload Plugin, choose the ZIP and click Install Now
   (or upload the `plugixa-testimonial` folder to `/wp-content/plugins/` over FTP)
3. Activate the plugin through the Plugins menu. The database tables are created on activation
4. Open "Testimonials" in the admin menu to add your first testimonial and create a display
5. Place the display on your site with its shortcode

= GIVING OTHER PEOPLE ACCESS =

The plugin follows the WordPress roles you already use:

* Anyone who can edit posts (Contributor and up) can open the plugin and add and change testimonials, categories and displays
* Anyone who can publish posts (Author and up) can also approve and reject testimonials
* Anyone who can delete other people's posts (Editor and up) can also delete testimonials
* Settings stay with administrators

To give a role one of these rights without the matching WordPress capability, grant it `plugixa_testimonial_edit`, `plugixa_testimonial_approve` or `plugixa_testimonial_delete`, for example with a role editor plugin.

== Frequently Asked Questions ==

= Q. How do I display testimonials on my site? =

Create a display (view) in the admin, then add it to any page with the shortcode the Shortcodes screen gives you. You can choose a grid or list layout.

= Q. What attributes does the shortcode take? =

`id`, `view`, `view_id`, `category`, `limit`, `orderby`, `order`, `featured_only`, `min_rating`, `show_photo`, `show_rating`, `show_role`, `show_company`, `show_date`, `show_website`, `show_quote_icon`, `dark_mode`, `pagination`, `pagination_style`, `per_page` and `schema`.

For example: `[plugixa_testimonial view="list" category="clients" limit="5"]`

`[plugixa_testimonials]` (plural) still works as a deprecated alias of `[plugixa_testimonial]`.

= Q. Can visitors submit their own testimonials? =

Not in the free plugin. The submission form, with reCAPTCHA, and request emails are part of Pro.

= Q. Is it compatible with multilingual sites? =

Yes. Plugixa Testimonial integrates with Polylang and WPML, grouping translations of the same testimonial together.

= Q. Does it work with the block editor and page builders? =

Yes. The shortcode works in the block editor's Shortcode block, the classic editor and any page builder that accepts shortcodes.

= Q. Does it store testimonials as posts or custom post types? =

No. It uses its own database tables, so lists, filters and displays stay fast however many testimonials you collect.

= Q. What happens to my testimonials if I delete the plugin? =

Deleting the plugin from the Plugins screen removes everything it created: its database tables with every testimonial, category and display, its settings, its capabilities and its downloaded fonts. Deactivating the plugin removes nothing.

= Q. What is the difference between the free and Pro versions? =

The free plugin gives you unlimited testimonials and displays, grid and list layouts, filters, pagination, social-proof popups and review schema. Pro adds the slider and masonry layouts, video testimonials, custom fields, the submission form and request emails, import and export, the Elementor, block and WooCommerce integrations, and review import from Google, Facebook and WooCommerce. Upgrading never moves or changes your data. See the [Pro features](https://www.plugixa.com/plugins/plugixa-testimonial#pricing).

= Q. Where can I find the documentation? =

Full documentation - getting started, displays and the shortcodes - is at https://www.plugixa.com/documentation/plugixa-testimonial

== External services ==

This plugin can connect to the third-party services listed below. Apart from the Freemius opt-in, every connection is optional: each must be enabled by the site administrator — supplying their own API key where one is needed — and is only made in the situations described. No data is sent to any of these services unless you turn the relevant feature on.

= Freemius =

We use Freemius to handle licensing, software updates, and - only if you opt in - anonymous usage diagnostics. On activation you are shown an opt-in screen; if you skip it, no identifiable data is sent. When you opt in, or when you manage a license or purchase, data such as your site URL, WordPress and PHP versions, active theme/plugins, and the email address used at opt-in may be transmitted. This occurs on activation, plugin updates, and license actions.
Provided by Freemius, Inc. - Terms: https://freemius.com/terms/ - Privacy: https://freemius.com/privacy/

= Google Fonts =

If an administrator selects a Google font for a display's typography, the plugin downloads that font's stylesheet and font files from the hosts fonts.googleapis.com and fonts.gstatic.com to your server and then serves them locally. Neither the frontend nor the admin ever loads fonts from Google's servers — no visitor or administrator data is sent to Google when a page or the admin preview is viewed. When you browse and select fonts in the admin, the font list is fetched from the Google Fonts Web API (host www.googleapis.com, path /webfonts/v1/webfonts) using your Google Fonts API key. The request carries only that key and the sort order; no visitor data.
Terms of service: https://policies.google.com/terms
Privacy policy: https://policies.google.com/privacy

== Screenshots ==

1. Grid layout with star ratings, photos, roles and companies
2. Masonry layout for testimonials of different lengths (Pro)
3. Slider layout with dots and autoplay (Pro)
4. List layout for longer testimonials
5. A dark card style, made in the display builder
6. A colourful masonry style, made in the display builder (Pro)
7. A minimal list style with muted tones
8. A corporate slider style with larger photos (Pro)
9. A compact two-column style
10. Category filter pills and numbered pagination
11. Rating filter tabs
12. Photo beside the text, in two columns
13. Coloured initials for people without a photo
14. Frontend testimonial submission form (Pro)
15. The testimonials list, with ratings, statuses and categories
16. Display builder - layouts, colours and typography with a live preview
17. Testimonial editor - client details, text and photo (the video field is Pro)
18. Dashboard - totals, average rating, charts and the latest testimonials
19. Shortcode builder - pick a display and its filters, copy the tag, and preview the result
20. Import - bring testimonials in from a CSV or JSON file, with column mapping (Pro)

== Changelog ==

= 1.2.0 =
* Changed: the slider and masonry layouts, video testimonials, custom fields, CSV and JSON import and export, the submission form with reCAPTCHA, testimonial requests, email notifications, the Elementor widget, the block and the WooCommerce product tab are now part of Pro
* Changed: a display saved with the slider or masonry layout shows as a grid in the free plugin
* Your data is kept: videos, custom-field values and requests stay in the database and come back as they were if you upgrade to Pro

= 1.1.1 =
* Improved: French translation updated

= 1.1.0 =
* New in Pro: import reviews from Google (Business Profile or Places search), Facebook Pages and WooCommerce products, with a list to select the ones you want
* New in Pro: for each source, choose manual selection, or an automatic daily import of all new reviews or positive reviews only
* Changed: review schema (JSON-LD) now covers only reviews collected on your own site, as Google's guidelines require; reviews imported from other platforms are displayed but not marked up

= 1.0.3 =
* New: a display can give each person's initials (or icon) placeholder a colour of its own, picked from their name, under Photo > Fallback Colors
* Fixed: the display builder's preview showed coloured placeholder photos that the site never rendered; it now shows the real placeholder

= 1.0.2 =
* Initial release
* React admin application for testimonials, categories, custom fields and displays
* Approval workflow: pending, approved, rejected and draft
* Grid, list, masonry and slider layouts with a live-preview display builder
* Gutenberg block, Elementor widget, WooCommerce product tab and shortcodes
* Frontend submission form with optional reCAPTCHA, and testimonial request emails
* Video testimonials, social-proof popups and review schema (JSON-LD)
* CSV and JSON import and export
* Multilingual support via Polylang and WPML

== Upgrade Notice ==

= 1.2.0 =
Several features move to Pro: slider and masonry layouts, video, custom fields, import and export, the submission form, requests, notifications and the Elementor, block and WooCommerce integrations. Slider and masonry displays fall back to a grid. No data is deleted.

= 1.1.1 =
Maintenance release.

= 1.1.0 =
Pro can now import reviews from Google, Facebook and WooCommerce. Review schema now covers only reviews collected on your own site.

= 1.0.3 =
Adds per-person placeholder colours. Existing displays look exactly as before until you turn it on.

= 1.0.2 =
Initial release of Plugixa Testimonial.
