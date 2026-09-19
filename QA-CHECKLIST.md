# QA checklist - run in a real WordPress + WooCommerce install

Stages 0-6 are written and pass `php -l` / `node --check`, but have not yet been
executed in WordPress. Run this matrix once the local (or staging) environment
is up, before any client deploy. Tick each item.

## Smoke test (do this first)

- [ ] Activate the plugin (WooCommerce active). No PHP notices/warnings.
- [ ] Flip the Master Switch ON in **Quotes > Settings**.
- [ ] Open a simple product -> price hidden, button says "Add to Quote".
- [ ] Add it -> drawer opens, badge shows the count.
- [ ] Open the Quote Page, fill the form, submit -> success message.
- [ ] **Quotes** list shows a new record with a reference (e.g. `RAQ-0001`).

## Master Switch (the hard rule)

- [ ] Switch OFF -> prices, cart, checkout all return exactly as before.
- [ ] Deactivate the plugin -> store is a normal shop again (nothing left broken).
- [ ] Re-activate -> data (quotes/settings) still present.

## Front-end

- [ ] Prices hidden on shop, category, single, widgets, related products.
- [ ] Simple product add -> quote **from the single-product page**.
- [ ] **List-page "Add to Quote"** (shop grid, category archive, related-products row):
      clicking a simple product's button adds it and fires the toast - not only the
      single-product button. (Regression guard for the 1.3.1 data-attribute fix.)
- [ ] **Variable product**: attributes selectable, button appears after choosing,
      correct variation captured.
- [ ] Drawer: change qty, remove line, count updates, persists on reload (guest).
- [ ] Guest vs logged-in list both persist correctly.
- [ ] Cart / checkout URLs redirect to the Quote Page.
- [ ] My Account: payment-methods tab gone.

## Form + anti-spam

- [ ] Required-field validation blocks empty submits.
- [ ] Email validation works; phone country-code is saved with the number.
- [ ] Attachment: allowed type accepts; disallowed type + oversize both rejected.
- [ ] **Real target file types** (e.g. `.dwg/.dxf/.step/.igs`) upload successfully.
- [ ] Honeypot: a filled decoy is rejected.
- [ ] reCAPTCHA v3 (with keys set): passes for a human; token missing is rejected.
- [ ] On submit the list is cleared and the record is created.

## Emails

- [ ] Both emails appear under **WooCommerce > Settings > Emails**.
- [ ] Customer "Quote Received" is received, styled natively, correct content.
- [ ] Admin "New Quote Request" is received by the configured recipient(s).
- [ ] Plain-text variants render correctly.

## Backend management

- [ ] List columns correct (reference, customer, company, items, status, date).
- [ ] Row-action quick status change works and logs history.
- [ ] Detail view: line items, customer, attachment download, source.
- [ ] Status select New -> Quoted -> Won/Lost persists (not reset to Published).
- [ ] "Notify customer" sends the status email.
- [ ] Internal notes save and display newest-first.
- [ ] CSV export downloads with correct rows.
- [ ] Attachment download works and is blocked for logged-out users / bad paths.

## Analytics + GA4

- [ ] Analytics page: funnel, weekly bars, top products, by category all render.
- [ ] `add_to_quote` fires on add (dataLayer or gtag).
- [ ] `generate_lead` fires on submit (verify in GA4 DebugView / GTM preview).

## Responsive + a11y

- [ ] Drawer + form usable at 680px and 460px.
- [ ] Keyboard: drawer closes on Esc; form is tab-navigable; labels present.

## Deploy gates (before going live)

- [ ] **Caching vs nonce/count** - move the AJAX nonce + badge count to an
      uncached bootstrap fetch, or full-page cache will serve a stale count and
      403 real visitors past the nonce window.
- [ ] **nginx** - add a deny rule for `uploads/raq-quotes/` (the `.htaccess` is
      Apache-only).
- [ ] Confirm no PHP errors in `debug.log` across the whole flow.

## After submit (1.2.0)

Quotes > Settings > General > After submit. Test each with the Quote Page form
AND the drawer form (Submission = "inside the drawer").

- [ ] Default (fresh install / upgraded site): "Show a thank-you message, then
      return to the homepage", 5 s - behaves exactly as 1.1.0 did.
- [ ] Return after = 0 -> panel stays, no countdown line, Back button works.
- [ ] "Redirect to a page": pick a page -> submit -> panel flips to the spinner
      "taking you to the next page" instantly, then lands on the page with
      `?raq_ref=RAQ-xxxx`; `[raq_thank_you]` on that page shows the reference.
- [ ] "Create a Thank-you page for me" -> a published "Quote Submitted" page
      appears under Pages, is selected, and a second click does NOT create
      another one.
- [ ] Chosen page trashed / set to draft -> submit falls back to the countdown
      (never a 404).
- [ ] "Redirect to a custom URL": an off-site https URL is followed in the
      browser; the no-JS POST fallback lands on the homepage instead.
- [ ] `javascript:` / `data:` pasted into Redirect URL is discarded on save.
- [ ] GA4 DebugView (or GTM Preview): one `generate_lead` per submission in
      every mode, none lost on the redirect, none duplicated on a refresh of
      the thank-you page.
- [ ] Throttle the network (DevTools "Slow 3G"): the spinner panel is visible
      the whole time; Continue link works if clicked early.
- [ ] Page-cached site: open the thank-you URL with two different `raq_ref`
      values in a private window - each shows its own reference (filled by
      JS, not cached).

## Guide + updates switch (1.3.0)

- [ ] Plugins screen: the RAQ row meta reads "Version | By | View details | Check for updates | FAQ";
      FAQ opens Quotes > Settings > Guide.
- [ ] Guide tab: every section renders, every question opens, every in-text link lands on the
      right tab / screen (General, Form, Analytics, Emails, Advanced, Quotes, Analytics screen).
- [ ] Guide tab has no Save button and no form.
- [ ] Advanced > Updates: untick "Automatic updates", save, re-open - it stays unticked; with
      `define( 'RAQ_DISABLE_AUTO_UPDATE', true )` in wp-config.php the row shows the constant
      notice instead of a checkbox and saving the tab does not touch the stored value.
- [ ] With the switch off, Dashboard > Updates still lists the plugin update; WP's own
      "Enable auto-updates" column reflects the plugin's decision.
