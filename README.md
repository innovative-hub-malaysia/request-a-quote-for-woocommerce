# Request a Quote for WooCommerce

Turn a WooCommerce store into a B2B request-a-quote catalogue - non-destructively.
Keep WooCommerce for products; replace the buy/checkout layer with an Add-to-Quote
flow; give sales a backend to manage and measure quotes.

**An Innovative Hub product** - built to be reusable across clients.
Website: https://www.innovativehub.com.my/

---

## What it does

- **Master Switch** - one toggle converts the store from selling to quoting. It
  disables prices, cart, checkout, payment, shipping, tax, coupons and the
  WooCommerce Analytics/Marketing menus **at runtime via hooks** - it never
  rewrites WooCommerce settings. Turn it off (or deactivate the plugin) and the
  store returns exactly as it was.
- **Add to Quote** - loop + single-product buttons (simple and variable
  products), a header quote icon with a count badge, and a slide-out mini-cart
  drawer.
- **Quote Page** - the `[raq_quote_form]` shortcode renders the quote summary +
  a configurable request form (name, company, email, phone with country code,
  country, message, custom fields), optional file attachments, honeypot and
  optional reCAPTCHA v3.
- **Own data** - quotes are stored in a dedicated `raq_quote` custom post type
  (not WooCommerce orders), with an auto reference (e.g. `RAQ-0001`).
- **Emails** - customer confirmation + sales notification, registered inside
  **WooCommerce > Settings > Emails** and fully customisable there.
- **Backend management** - quote list with status badges, quick status changes,
  a detail view (line items, customer, attachment download, internal notes),
  the New -> Quoted -> Won/Lost workflow with a history log, and CSV export.
- **Analytics + GA4** - an in-backend dashboard (status funnel, quotes over
  time, top products, by category) and a GA4 `generate_lead` event on submit
  (via an existing GTM dataLayer, or a configured Measurement ID).

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+

## Install

1. Copy the `request-a-quote-for-woocommerce` folder to `wp-content/plugins/`
   (or upload the zip from the latest GitHub Release via Plugins > Add New > Upload).
2. Activate it (WooCommerce must be active first).
3. Go to **Quotes > Settings**.

## Updates

Installed sites update themselves from this repository's GitHub Releases
(bundled `lib/plugin-update-checker`): the Plugins screen shows the update and
auto-updates are on by default. Switch them off per site under Quotes > Settings >
Advanced > Updates, or force it with `RAQ_DISABLE_AUTO_UPDATE` (`true` = off,
`false` = forced on) in `wp-config.php`, which overrides the setting. Sites on 1.0.4 or earlier need one manual
upload of a newer version first.

To ship a version: bump `Version:` + `RAQ_VERSION` + `Stable tag` (all three
must match), add the changelog entry to `readme.txt`, commit, then
`bin/build-release.sh --publish` - it builds the zip with the plugin slug as
the top folder, tags `vX.Y.Z` and creates the Release with the zip attached.
Every site picks it up within ~12 hours.

## Quick start

1. Create a page (e.g. "Request a Quote") containing the shortcode
   `[raq_quote_form]`.
2. **Quotes > Settings > General**: turn on the **Master Switch**, choose that
   page as the **Quote Page**, set the button label, pick page-vs-drawer submit.
3. **Fields / Advanced**: adjust the form fields, attachments, quote numbering.
4. **Anti-spam**: (optional) add reCAPTCHA v3 keys.
5. **Analytics**: enable GA4 and, if the site has no GTM, set a Measurement ID.
6. Add the header button with the `[raq_quote_button]` shortcode (or use the
   built-in floating button). Configure the two emails under
   **WooCommerce > Settings > Emails**.

## Deploying to a new client (reuse)

Nothing is hardcoded - every client-specific value lives in Settings. To reuse:

1. Install + activate on the client site.
2. Set the Quote Page, button label, fields, and email recipients for that
   client.
3. Add the header button to their theme (shortcode or menu).
4. Set their GA4 / GTM.

No code changes are needed per client.

## Shortcodes

| Shortcode            | Purpose                                             |
| -------------------- | --------------------------------------------------- |
| `[raq_quote_form]`   | The quote summary + request form (the Quote Page).  |
| `[raq_quote_button]` | The header quote icon + live count badge.           |

## Developer hooks

| Hook                         | Type   | Fires / filters                                  |
| ---------------------------- | ------ | ------------------------------------------------ |
| `raq_quote_created`          | action | after a quote is saved (`$quote_id`, `$customer`)|
| `raq_quote_status_changed`   | action | on status change (`$quote_id`, `$from`, `$to`)   |
| `raq_dial_codes`             | filter | the phone country-code list                      |

## Uninstall

By default your quotes and settings are **kept** on delete. To purge everything,
tick **Quotes > Settings > Advanced > On uninstall** before deleting the plugin.

## Attachments (deploy note)

Uploads are stored in `wp-content/uploads/raq-quotes/` with a deny `.htaccess`
(Apache) and are only downloadable through an authenticated admin endpoint. On
**nginx**, add a location rule denying direct access to that directory.

---

(c) Innovative Hub - https://www.innovativehub.com.my/

## Known issues (open)

- **Archive "Add to Quote" buttons do nothing (found 2026-09-19 on itoliceramic.com, present since 1.0.5).**
  `RAQ_Converter::render_loop_quote_button()` prints `data-product-id="…"`, but `readContext()` in
  `assets/js/raq-frontend.js` reads `$btn.data('product_id')` (every other button uses
  `data-product_id`). jQuery does not map the two, so the loop button resolves to product id 0 and
  `sendAdd()` returns silently - no request, no toast. Category / shop grids and related-products
  rows are affected on every site; single-product buttons (our own and the relabelled WooCommerce one,
  which carries the id in `value`) work. Fix for the next release: print `data-product_id` in
  `render_loop_quote_button()` AND read both spellings in `readContext()`; add a loop-button case to
  QA-CHECKLIST.md (the 09-09 QC only exercised the single-product path). Not shipped yet on Lee's call
  (2026-09-19: "我们今天不该 RAQ").
