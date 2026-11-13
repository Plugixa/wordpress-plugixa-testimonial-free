=== Plugixa Testimonial ===
Contributors: zakaria04, freemius
Tags: testimonials, reviews, ratings, customer reviews, social proof
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collect, manage, and showcase customer testimonials with star ratings, photos, an approval workflow, and grid, slider, masonry, and list layouts.

== Description ==

⭐ Collect, approve and showcase what your customers say with **Plugixa Testimonial** - star ratings, photos, videos and an approval workflow, in a fast React admin, displayed in grid, slider, masonry or list layouts.

📚 Read the [**Documentation**](https://www.plugixa.com/documentation/plugixa-testimonial)
🌐 Visit the official website [**Plugixa**](https://www.plugixa.com/)
✉️ Questions or support requests: [**Contact us**](https://www.plugixa.com/contact)

[__Documentation__](https://www.plugixa.com/documentation/plugixa-testimonial) | [__Contact__](https://www.plugixa.com/contact) | [__Support Forum__](https://wordpress.org/support/plugin/plugixa-testimonial/) | [__Upgrade to Pro!__](https://www.plugixa.com/plugins/plugixa-testimonial#pricing)

Show your first testimonials in **3 simple steps**:

1. Add testimonials yourself, or let customers send them through the submission form
2. Approve the ones you want to publish
3. Build a display with the live preview and place it with the block or a shortcode

Testimonials live in the plugin's **own database tables**, not in posts and post meta, so lists, filters and displays stay fast as your collection grows.

### Testimonials You Have Actually Approved

Nothing appears on your site until you say so. Every testimonial moves through a clear workflow, and visitors only ever see the approved ones.

✅ **Pending** - everything sent through the submission form arrives here first
✅ **Approved** - published, and the only status any display can show
✅ **Rejected** - kept for your records, never shown
✅ **Draft** - testimonials you are still writing up

= Key Features of Plugixa Testimonial =

* Fast, Material UI-based admin application
* Unlimited testimonials, categories and saved displays (views) - no cap
* Star ratings, client photos, role, company and website
* Video testimonials - YouTube, Vimeo or self-hosted
* Approval workflow: pending, approved, rejected and draft
* Categories to organize and filter testimonials
* Four display layouts: grid, list, masonry and slider
* Display builder with a live preview, colours, typography and spacing
* Gutenberg block and shortcodes for placing testimonials anywhere
* Frontend submission form, with optional Google reCAPTCHA
* Testimonial request emails with single-use submission links
* Custom fields for capturing extra testimonial data
* Import from CSV and JSON, and export to CSV and JSON
* Social-proof notification popups
* Review schema (JSON-LD) for SEO
* Elementor widget and a WooCommerce product tab
* Multilingual support via Polylang and WPML
* Works with your WordPress roles: authors can approve, editors can delete, settings stay with administrators
* Fonts are served from your own site, never from Google's servers

= Plugixa Testimonial Pro Features =

* Google Reviews import - find your business and bring its Google reviews in as testimonials, using your own Google Places API key
* PDF export - download your testimonials as a formatted PDF document
* Demo data - fill a test site with sample testimonials, categories and displays in one click, and remove them again just as easily
* Priority support

[Upgrade to Plugixa Testimonial Pro](https://www.plugixa.com/plugins/plugixa-testimonial#pricing)

= 🎨 Four Layouts, One Display Builder =

Choose grid, list, masonry or slider, then adjust colours, typography, photo position, ratings and pagination with a live preview beside you. Each display is saved, so the same look can be placed on any number of pages, and changing it once updates it everywhere.

= 📝 Let Your Customers Write Them =

Add `[plugixa_testimonial_form]` to a page and visitors can send a testimonial with a rating and a photo. Everything they send is stored as pending and never appears on the site until you approve it. Turn on Google reCAPTCHA to keep spam out.

= ✉️ Ask for a Testimonial by Email =

Send a request to a customer straight from the admin. The email carries a single-use link to the submission form, with their name and email already filled in, so leaving a testimonial takes them less than a minute.

= 🔎 Review Schema for Search Engines =

Displays can print Review and AggregateRating structured data (JSON-LD) built from your approved testimonials, so search engines can understand your ratings.

= 🌍 Multilingual From the Start =

With Polylang or WPML active, each testimonial, category and display can be translated, and translations of the same testimonial are grouped together. Visitors see the version in their language.

### 👨‍💻 Author

[**Plugixa Testimonial**](https://www.plugixa.com/plugins/plugixa-testimonial) is developed by [**Plugixa**](https://www.plugixa.com/), building focused, well-documented WordPress plugins with an emphasis on performance, clean code and correct data.

### 🛟 Documentation & Support

Plugixa Testimonial is built to be usable without a manual, but it is documented in full.

👉 Read the [Documentation](https://www.plugixa.com/documentation/plugixa-testimonial) - getting started, displays, the submission form, requests, import and export, and the shortcodes
👉 Ask on the [WordPress.org support forum](https://wordpress.org/support/plugin/plugixa-testimonial/)
👉 Or [contact us directly](https://www.plugixa.com/contact)
👉 Browse more plugins at [plugixa.com](https://www.plugixa.com/)

= Shortcode Usage =

Create a display under Testimonials > Shortcodes. The screen shows the exact tag for each display, ready to copy.

Show a saved display:
`[plugixa_testimonial id="1"]`

Or skip the saved display and pass attributes directly:
`[plugixa_testimonial view="grid" limit="6" min_rating="4" orderby="rating" order="DESC"]`

Show the frontend submission form:
`[plugixa_testimonial_form]`

A Gutenberg block ("Plugixa Testimonial") is also available if you prefer the editor.

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
5. Place the display on your site with the "Plugixa Testimonial" block or a shortcode

= GIVING OTHER PEOPLE ACCESS =

The plugin follows the WordPress roles you already use:

* Anyone who can edit posts (Contributor and up) can open the plugin and add and change testimonials, categories and displays
* Anyone who can publish posts (Author and up) can also approve and reject testimonials
* Anyone who can delete other people's posts (Editor and up) can also delete testimonials
* Settings, testimonial requests and import and export stay with administrators

To give a role one of these rights without the matching WordPress capability, grant it `plugixa_testimonial_edit`, `plugixa_testimonial_approve` or `plugixa_testimonial_delete`, for example with a role editor plugin.

== Frequently Asked Questions ==

= Q. How do I display testimonials on my site? =

Create a display (view) in the admin, then add it to any page with the "Plugixa Testimonial" Gutenberg block or the shortcode the Shortcodes screen gives you. You can choose grid, list, masonry or slider layouts.

= Q. What attributes does the shortcode take? =

`id`, `view`, `view_id`, `category`, `limit`, `orderby`, `order`, `featured_only`, `min_rating`, `show_photo`, `show_rating`, `show_role`, `show_company`, `show_date`, `show_website`, `show_quote_icon`, `dark_mode`, `pagination`, `pagination_style`, `per_page` and `schema`.

For example: `[plugixa_testimonial view="slider" category="clients" limit="5"]`

`[plugixa_testimonials]` (plural) still works as a deprecated alias of `[plugixa_testimonial]`.

= Q. Can visitors submit their own testimonials? =

Yes. Add `[plugixa_testimonial_form]` to a page. Testimonials sent through the form arrive as pending and only appear on the site once you approve them from the admin.

= Q. How do I stop spam submissions? =

Turn on Google reCAPTCHA in the plugin's settings and enter your reCAPTCHA keys. The submission form then verifies every visitor before a testimonial is accepted.

= Q. Is it compatible with multilingual sites? =

Yes. Plugixa Testimonial integrates with Polylang and WPML, grouping translations of the same testimonial together.

= Q. Does it work with the block editor and page builders? =

Yes. A dedicated Gutenberg block is included, along with an Elementor widget and shortcodes for the classic editor and any other page builder.

= Q. Does it store testimonials as posts or custom post types? =

No. It uses its own database tables, so lists, filters and displays stay fast however many testimonials you collect.

= Q. What happens to my testimonials if I delete the plugin? =

Deleting the plugin from the Plugins screen removes everything it created: its database tables with every testimonial, category, display and request, its settings, its capabilities and its downloaded fonts. Export your testimonials to CSV or JSON first if you want to keep them. Deactivating the plugin removes nothing.

= Q. What is the difference between the free and Pro versions? =

The free plugin is complete - unlimited testimonials and displays, all four layouts, the submission form, request emails, custom fields, CSV and JSON import and export, video testimonials, review schema, and the block, Elementor and WooCommerce integrations. Pro adds the Google Reviews import, PDF export and demo data. Upgrading never moves or changes your data. See the [Pro features](https://www.plugixa.com/plugins/plugixa-testimonial#pricing).

= Q. Where can I find the documentation? =

Full documentation - getting started, displays, the submission form, requests, import and export, and the shortcodes - is at https://www.plugixa.com/documentation/plugixa-testimonial

== External services ==

This plugin can connect to the third-party services listed below. Apart from the Freemius opt-in, every connection is optional: each must be enabled by the site administrator — supplying their own API key where one is needed — and is only made in the situations described. No data is sent to any of these services unless you turn the relevant feature on.

= Freemius =

We use Freemius to handle licensing, software updates, and - only if you opt in - anonymous usage diagnostics. On activation you are shown an opt-in screen; if you skip it, no identifiable data is sent. When you opt in, or when you manage a license or purchase, data such as your site URL, WordPress and PHP versions, active theme/plugins, and the email address used at opt-in may be transmitted. This occurs on activation, plugin updates, and license actions.
Provided by Freemius, Inc. - Terms: https://freemius.com/terms/ - Privacy: https://freemius.com/privacy/

= Google reCAPTCHA =

Used only if an administrator enables reCAPTCHA on the frontend testimonial submission form. When a visitor submits the form, a reCAPTCHA token is generated in the visitor's browser via the script at https://www.google.com/recaptcha/api.js, and the token plus your reCAPTCHA secret key are sent to https://www.google.com/recaptcha/api/siteverify for verification. As part of reCAPTCHA, Google also receives information about the visitor's browser and interaction.
Terms of service: https://policies.google.com/terms
Privacy policy: https://policies.google.com/privacy

= Google Fonts =

If an administrator selects a Google font for a display's typography, the plugin downloads that font's stylesheet and font files from the hosts fonts.googleapis.com and fonts.gstatic.com to your server and then serves them locally. Neither the frontend nor the admin ever loads fonts from Google's servers — no visitor or administrator data is sent to Google when a page or the admin preview is viewed. When you browse and select fonts in the admin, the font list is fetched from the Google Fonts Web API (host www.googleapis.com, path /webfonts/v1/webfonts) using your Google Fonts API key. The request carries only that key and the sort order; no visitor data.
Terms of service: https://policies.google.com/terms
Privacy policy: https://policies.google.com/privacy

= YouTube =

Used only if an administrator attaches a YouTube video to a testimonial. On any page that displays that testimonial, the video's preview thumbnail is loaded in the visitor's browser from the host img.youtube.com, so YouTube receives the visitor's IP address and browser information on page view. The video itself is not loaded until the visitor clicks play, at which point it is embedded from the privacy-enhanced domain www.youtube-nocookie.com. If no testimonial has a YouTube video, no request is made.
Terms of service: https://www.youtube.com/t/terms
Privacy policy: https://policies.google.com/privacy

= Vimeo =

Used only if an administrator attaches a Vimeo video to a testimonial, and only after the visitor clicks play — nothing is loaded from Vimeo on page view. On click, the player is embedded from https://player.vimeo.com, and Vimeo receives the visitor's IP address and browser information. If no testimonial has a Vimeo video, no request is made.
Terms of service: https://vimeo.com/terms
Privacy policy: https://vimeo.com/privacy

== Screenshots ==

1. Testimonials - the list, with ratings, statuses and filters
2. Display builder - layouts, colours and typography with a live preview
3. Grid layout on the frontend
4. Slider layout on the frontend
5. Frontend testimonial submission form
6. Dashboard - totals, average rating, rating and status charts, and the latest testimonials
7. Testimonial editor - client details, text, photo, video and translations
8. Shortcode builder - pick a display and its filters, copy the tag, and preview the result
9. Masonry layout on the frontend
10. Import - bring testimonials in from a CSV or JSON file, with column mapping

== Changelog ==

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

= 1.0.2 =
Initial release of Plugixa Testimonial.
