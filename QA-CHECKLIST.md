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
- [ ] Simple product add -> quote.
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
