=== Request a Quote for WooCommerce ===
Contributors: innovativehub
Tags: woocommerce, request a quote, quote, b2b, catalogue
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.9
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn a WooCommerce store into a B2B request-a-quote catalogue - non-destructively.

== Description ==

Request a Quote for WooCommerce keeps WooCommerce for products and replaces the
buy/checkout layer with an Add-to-Quote flow: hide prices, swap Add to Cart for
Add to Quote, capture B2B enquiries into their own records, and give sales a
backend to manage and measure quotes.

Ground rules:

* Rides on WooCommerce - products, categories, variations stay 100% native.
* Non-destructive - a single Master Switch disables e-commerce at runtime via
  hooks/filters. Turn it off (or deactivate the plugin) and the store returns
  exactly as it was.
* Reusable - no hardcoded client names or fields; everything is configured in
  Settings.
* Own data - quotes are stored in a dedicated `raq_quote` custom post type, not
  WooCommerce orders.

Built once, deployable to any no-price B2B store.

== Changelog ==

= 1.3.0 =
* Guide tab (Quotes > Settings > Guide): a plain-language FAQ for whoever runs
  the site - setup, the two submission modes, what happens after submit,
  working the quotes, updates, troubleshooting, and a full section on GA4
  tracking: which events fire (add_to_quote, generate_lead) and with what,
  Measurement ID vs GTM, wiring the GTM trigger, marking generate_lead as a
  Key Event, verifying in DebugView, and what to check when events are missing.
* "FAQ" link on the plugin row (next to "Check for updates") opens the Guide.
* Automatic updates can be switched off per site under Settings > Advanced >
  Updates (on by default). When off, new versions are still offered on the
  Plugins screen for a manual update. RAQ_DISABLE_AUTO_UPDATE in wp-config.php
  still overrides the setting either way.

= 1.2.0 =
* Choose what happens after a quote is submitted (Quotes > Settings > General
  > After submit): show the thank-you message and return to the homepage
  after N seconds (the default, unchanged from before; 0 = stay), redirect to
  a page on this site, or redirect to a custom URL. Applies to the Quote Page
  form and the drawer form alike.
* "Create a Thank-you page for me" builds a published "Quote Submitted" page
  carrying the new [raq_thank_you] shortcode and selects it. The shortcode
  renders the confirmation panel and, when the URL carries ?raq_ref=, the
  quote reference - filled in by the browser so nothing per-visitor is ever
  written into cacheable HTML.
* The generate_lead analytics event is handed off before the browser leaves
  the page in every mode (gtag event_callback / GTM eventCallback, 300 ms
  cap), and the form turns into a "sent, taking you there" panel with a
  spinner and a Continue link the moment the server confirms.
* The no-JavaScript fallback follows the same setting; an off-site custom
  URL is not followed there and goes to the homepage.
* Fixed: in the drawer form, the thank-you panel's button was greyed out and
  unclickable (the empty-list CTA rule caught it). Focus now moves onto the
  thank-you panel when the form is replaced, so keyboard and screen-reader
  users are not dropped.

= 1.1.0 =
* The Country field and the phone country-code picker are now searchable: open
  the dropdown, type a country name or calling code, pick from the filtered
  list. Keyboard: arrows, Enter, Esc. Country options show their flag.
* Server-side: the phone country code and the Country value are validated
  against the lists the form offers, not just sanitised. WooCommerce country
  names that carry HTML entities (Curaçao, Côte d'Ivoire) are decoded on both
  sides so the same text is offered, posted and accepted.
* Accessible: the toggles announce their current value, the search box is a
  combobox and the keyboard cursor is exposed via aria-activedescendant.
* The Country field posts the same value as before (the country name), so
  stored quotes, emails and the admin list are unchanged.
* Markup change for theme overrides: the `.raq-cc*` classes are replaced by
  `.raq-dd*` (shared by both pickers).

= 1.0.5 =
* Updates now come from Innovative Hub's GitHub repository through the normal
  WordPress update flow: the Plugins screen shows "update available" and the
  plugin auto-updates by default (define RAQ_DISABLE_AUTO_UPDATE to opt out).
  Sites on 1.0.4 or earlier need one manual upload of this version first.

= 1.0.4 =
* The closed quote drawer no longer paints its shadow down the right edge of
  every page.

= 1.0.3 =
* Fix a cross-visitor quote-list leak on page-cached sites: the footer now
  ships empty and the visitor's own count, drawer and Quote Page summary are
  fetched once on load. The Quote Page is cacheable again.
* Add-to-cart is blocked at validation on a converted store, not only relabelled.
* An archive button is rendered when the theme removed WooCommerce's own (Divi).
* Text domain loads on init (WordPress 6.7 notice).

= 1.0.2 =
* Plugins screen: the auto-updates column is now simply empty for this plugin
  (no toggle, no note).
* Shorter plugin description (under 200 characters).

= 1.0.1 =
* The "View details" popup no longer shows the unrelated same-named
  wordpress.org plugin - the foreign slug data is stripped and the details
  screen now serves this plugin's own information.
* The auto-updates toggle on the Plugins screen is replaced with a note
  (updates are distributed by Innovative Hub; auto-update never applies).

= 1.0.0 =
First stable release, after a full security + code-quality + functional QC
(inline review plus an independent adversarial review).
* Security: CSV export formula-injection escaping on all customer-influenced
  columns; hard blocklist of executable/script file extensions on attachment
  upload; per-IP submission rate limit (5 per 10 min) and per-recipient email
  throttle (3 per hour) so the confirmation email cannot be abused to spam a
  victim's inbox.
* Fix: the no-JS submission fallback now initialises the WooCommerce session so
  it works for guests, not only logged-in users.
* Fix: when the quantity selector is disabled, quantity is enforced as 1 on the
  server, not just hidden in the UI.
* Fix: analytics time windows used a mixed time base and skewed by the site's
  UTC offset.
* Cleanup: the honeypot toggle (which was always on regardless) is now an
  honest "always on" note; the Emails tab explains itself when the Master
  Switch is off; guards on malformed item meta in list columns and CSV export.

= 0.1.11 =
* Analytics redesigned around the sales workflow: KPI tiles (last-30-days
  volume with trend vs previous period, win rate, awaiting-action and
  in-progress counts that link to the filtered list), a "Needs attention" list
  of quotes still New after 3 days with direct links, a 12-week trend, the
  status funnel with stage shares, and ranked bars for top products,
  categories and source pages.

= 0.1.10 =
* Fix: the quote detail screen rendered empty (a critical error killed the page
  right after our side box). The native Publish box - meaningless for quotes
  and unhappy with custom statuses - is removed; the Status & history box now
  carries its own Update button and Move to Trash link.
* Diagnostics: any fatal on the site is captured (message, file, line, URL) and
  shown to admins as a notice on the Quotes screens, with a dismiss link.

= 0.1.9 =
* Hardening: the quote detail screen no longer risks a critical error from
  empty/malformed notes, history or item meta (all loops now guard entries).

= 0.1.8 =
* Fix (complete): quotes now actually appear in the backend list - the statuses
  additionally needed 'protected' for the admin "All" view, and our own
  analytics/CSV queries now pass explicit statuses instead of 'any'.
* Email diagnostics: every submission writes the outcome of each email into the
  quote's Status & history ("sent OK to x@y" / "FAILED - reason" / "skipped").
  If it says sent OK but nothing arrives, the issue is SMTP/spam, not the plugin.

= 0.1.7 =
* Fix: customer/admin emails could silently not send - quote emails are now
  triggered directly (no reliance on the WooCommerce transactional queue).
* Quote Page: description no longer wraps early; larger product images.
* Backend restructured into five clear tabs: General, Form (fields +
  attachments + anti-spam together), Emails (live status of both notifications
  with per-email edit links + sales recipient), Analytics, Advanced.

= 0.1.6 =
* Fix: submitted quotes were invisible in the backend (statuses were registered
  as internal, which WordPress hides from admin lists). Existing submissions
  reappear after updating - no data was lost.
* Fix: the phone field collapsed and its input dropped to the next row (page and
  drawer) - the field wrapper is now a div so the country-code list cannot break
  it.
* Phone number: digits only - letters/spaces/symbols are stripped as you type
  and rejected server-side with a clear error.
* Quote Page redesign: large bold title + configurable description
  (Quotes > Settings > General), section headings, product images shown whole
  (no cropping), consistent extensible two-column form grid.
* Removed the theme's ">" hover animation on quote buttons - simple hover only.

= 0.1.5 =
* Fix: the drawer submit button was rendered disabled when the list was empty at
  page load and never re-enabled after adding items via AJAX - it is no longer
  disabled, so submitting (and its validation error notes) works.
* Error notes now show clearly for required/invalid fields.
* Country-code selector redesigned: closed shows flag + code (e.g. flag +60);
  open shows the full country names for easy finding.
* Quote button colour/size settings now force-apply to the Add-to-Quote button.
* Quote Page summary shows product thumbnails; product title no longer aligns
  right when the quantity column is hidden; submit button aligned right.
* Empty drawer CTA is greyed out instead of showing the brand colour.
* Block direct access to the WooCommerce Payments settings URL in quote mode.

= 0.1.4 =
* Thank-you flow: after submit, a thank-you panel with a countdown auto-redirects
  to the homepage, plus a "Back to homepage" button (brand-coloured).
* Quote form: default Country field removed (phone already has a country code);
  Phone now sits half-width beside Email.
* Quantity feature unified - when the quantity selector is off it is hidden on
  the product page, the drawer, and the Quote Page summary (quantity = 1).
* Quote mode admin cleanup: hide Payments settings and the Orders, Coupons and
  Reports menus (Home, Settings, Status, Extensions remain).

= 0.1.3 =
* Fix: Add-to-Quote button no longer disappears on product pages - the plugin
  renders its own button again (with a setting to turn it off and place it via
  the new [raq_add_to_quote] shortcode, e.g. inside a Divi module).
* Self-contained form + drawer design: inputs no longer inherit the theme's
  tiny fields; proper sizing, focus states, two-column Quote Page, styled drawer.
* Drawer now layers above the theme header/secondary menu.

= 0.1.2 =
* Theme compatibility: keep the Add-to-Quote button wherever the theme (incl.
  Divi Theme Builder) places it, and intercept the click - no more mispositioned
  button.
* Fix WooCommerce Analytics/Marketing not hiding in quote mode (timing).
* Appearance settings: quantity-selector toggle, button colours + size,
  floating-button position (or hide it), and a Custom CSS box.
* Remove the backend "Add New Quote" (quotes are created by customers only).
* Quote Page is now auto-detected from the shortcode - the page selector is gone.
* Drawer form is scrollable and styled; Quote Page redesigned (2-column form).
* Country-code selector shows flags; client-side form validation.
* Email preview shows sample data so the template is not blank.

= 0.1.1 =
* Add a Fields editor (add / remove / reorder form fields, set required).
* Add a Settings link on the Plugins screen.
* Guard against a wordpress.org slug collision offering a false update or
  auto-overwriting the plugin.

= 0.1.0 =
* Stage 0 scaffold: plugin skeleton, WooCommerce dependency guard + HPOS
  compatibility, tabbed settings framework, `raq_quote` CPT + statuses,
  conditional converter load-spine.
