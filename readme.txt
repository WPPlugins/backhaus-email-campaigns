=== BackHAUS Email Campaigns for WooCommerce ===
Contributors: backhaus
Tags: email marketing, newsletter, woocommerce, customers, email campaigns
Requires at least: 6.0
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Email all your WooCommerce customers easily, from WordPress. Send campaigns through your own mail setup, with no monthly fee.

== Description ==

**Why I made it**

I run a WooCommerce store, and all I wanted was to email my customers easily: every one of them, quickly, without the tool falling over halfway through. Nothing I tried did that well, so I built this. It sends from your own WordPress, through the mail setup your store already uses, and it never charges you by the contact.

**How it works**

Write an email, choose who gets it, and send it or schedule it. BackHAUS Email Campaigns uses your store's customers and orders to decide who gets what, wraps each email in your WooCommerce email design, and hands it to WordPress's own mail function, so it works with whatever sends your store's email today (WP Mail SMTP, FluentSMTP, Mailgun, Amazon SES, Microsoft 365, Gmail and others).

**What you can do**

* Start from a layout (store news, a sale, new arrivals, a thank-you) or a blank page, and write in the WordPress editor.
* Add product cards with a photo, name, price and link, and personal details such as the customer's first name.
* Preview on desktop and phone, and send yourself a test.
* Choose who gets it: when they ordered, how many orders, how much they spent, what they bought, where they live, and people who signed up without ordering. You see how many people it reaches as you choose.
* Send now or schedule it. The confirmation tells you how many people it reaches, how many were emailed recently, and whether a campaign with the same subject went out lately.
* Watch it go out: progress, pause and stop, and what happened to each person.
* See opens, clicks and the orders that followed, on each campaign and on the Reports screen.

**Safe by design**

* People who unsubscribe are never emailed again unless they sign up again themselves. One-click unsubscribe works in Gmail, Yahoo and other mail apps.
* An over-mailing guard holds back emails to anyone emailed too often, so a mistake can't flood your customers. You decide whether held emails go out.
* When your mail provider asks you to slow down, sending pauses and resumes by itself. Speed limits keep you inside your provider's limits.
* An address whose mailbox doesn't exist is marked as bounced and gets no more email, which protects your standing with inboxes.
* The Status tab tells you whether email is working, and checks your sender domain's SPF, DKIM and DMARC records.

**Consent and sign-ups**

* A sign-up checkbox at checkout, or automatic opt-in for customers.
* A sign-up form for any page (`[bhec_signup]`), with optional confirmation by email (double opt-in) and optional bot protection (Google reCAPTCHA or Cloudflare Turnstile).
* An Email Preferences page in My Account, and GDPR export and erasure.

**Pro**

BackHAUS Email Campaigns Pro for WooCommerce adds automatic emails (abandoned carts, unpaid orders, follow-ups after an order, welcome and win-back emails) that can run as a series, back-in-stock and new-arrival emails, subject tests, a unique coupon for each person and saved guest carts. See https://backhaus.sg/email-campaigns.

== Installation ==

1. Install through Plugins > Add New, or upload the plugin folder to `/wp-content/plugins/backhaus-email-campaigns/`.
2. Activate it. WooCommerce must be active.
3. Open Email Campaigns > Settings > Status to check that email is working, then choose who gets marketing email under General.
4. Go to Email Campaigns > Campaigns and pick a layout for your first campaign.

== Frequently Asked Questions ==

= Does it send the emails itself? =

No. It hands each email to WordPress, which sends it the same way it sends your order emails. For more than a handful of emails, use a mail service (through a plugin such as WP Mail SMTP) rather than your web server.

= Will my emails land in spam? =

That depends mostly on your mail setup. Settings > Status checks it: whether a mail plugin sends your email, and whether your sender domain has SPF, DKIM and DMARC records. Send from an address at your own domain, not a free mailbox such as Gmail.

= Does it send my customer list anywhere? =

No. Everything stays in your WordPress database.

= Can I email people who haven't ordered? =

Yes. People who sign up through the `[bhec_signup]` form can be included in any campaign.

= How do people unsubscribe? =

Every campaign has an unsubscribe link, and mail apps such as Gmail show their own Unsubscribe button. Customers can also change their choice in My Account.

== External Services ==

The free plugin connects to other services only when you turn them on:

* **Google reCAPTCHA**, if you choose it under Settings > Sign-Ups > Bot Protection. The sign-up form loads Google's script, and the visitor's answer and IP address are sent to Google to check it. [Terms](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).
* **Cloudflare Turnstile**, if you choose it there instead. The sign-up form loads Cloudflare's script, and the visitor's answer and IP address are sent to Cloudflare to check it. [Terms](https://www.cloudflare.com/website-terms/), [Privacy Policy](https://www.cloudflare.com/privacypolicy/).

The Status tab looks up your sender domain's public DNS records through your server's usual DNS resolver.

Pro only: BackHAUS Email Campaigns Pro checks its license and looks for updates at backhaus.sg, sending the license key and the site's address. [Terms](https://backhaus.sg/terms), [Privacy Policy](https://backhaus.sg/privacy).

== Screenshots ==

1. Your first campaign: check that email works, pick a layout, send yourself a test.
2. The campaign editor: subject, message with product cards, and who gets it.
3. Before it goes out: how many people it reaches, and who was emailed recently.
4. Preview on a phone before you send.
5. A sent campaign: opens, clicks, orders, and what happened to each person.
6. Reports: how your emails did over the last 90 days.
7. Subscribers: everyone, their choice, and the emails they got.
8. Status: is email working, including your sender domain's records.

== Changelog ==

= 1.5.3 =
* Tested with WordPress 7.2, WooCommerce 11.3 and PHP 8.6.
* Status > Sender Domain finds DKIM under Mailgun's newer pdk1 and pdk2 names.

= 1.5.2 =
* Fix: choosing recipients by number of orders or amount spent together with a customer role, a registered date or an excluded segment mixed the values up, so the campaign reached the wrong people or nobody.

= 1.5.1 =
* Security hardening from the WordPress.org review: lists of ids in database queries go through prepared placeholders, markup the plugin's screens build is escaped where it is printed, and screen templates no longer read the address bar themselves.

= 1.5.0 =
* New name: BackHAUS Email Campaigns for WooCommerce (Pro: BackHAUS Email Campaigns Pro for WooCommerce).
* Everything the plugin stores or registers now starts with bhec_: its tables, settings, hooks and the sign-up form shortcode, now `[bhec_signup]`. Unsubscribe and tracking links in emails sent before 1.5 keep working.
* The free edition's menu no longer has an Automatic Emails page; Settings > Pro says what Pro adds.

= 1.4.3 =
* The unsubscribe and confirmation pages and the campaign CSV export name their database tables in the query itself, so WordPress.org's automated check can see they're safe.

= 1.4.2 =
* The sign-up form's style and script, and the bot protection scripts, now load the WordPress way, once per page however many forms it has. The form's error message can now be translated.
* Import Settings (Status) checks the uploaded file more strictly.
* The free edition no longer carries any code for Pro's features.

= 1.4.1 =
* Fix: UTM tags (Settings, General, Google Analytics) never reached the links in campaigns while opens and clicks tracking was on, which it is by default. They now go on every web link before tracking, and automatic emails get them too.
* Fix: a tag you set on a link yourself is kept, a payment link keeps its order details, and phone, email and in-page links are left alone.

= 1.4.0 =
* New: your first campaign starts from a layout (store news, a sale, new arrivals with your newest products, a thank-you) or a blank page, with a short guide on the empty Campaigns screen.
* New: product cards and confirmation emails (double opt-in) in the free edition, and bot protection for the sign-up form (Google reCAPTCHA or Cloudflare Turnstile).
* New: Status checks your sender domain's SPF, DKIM and DMARC records, and warns when you send from a free mailbox such as Gmail.
* New: an address whose mailbox doesn't exist is marked as bounced and gets no more email. You can subscribe it again from Subscribers.
* New (Pro): follow-up emails that make a series, sent only if they haven't ordered, didn't open or didn't click the last one.
* New (Pro): back-in-stock emails for customers who ask on a sold-out product's page, and new-arrival emails for customers who bought from that category.
* New (Pro): test two subjects on part of your list; the one more people open goes to everyone else.
* Security: customer details in emails (a name, say) can no longer add links or other markup, or turn into a coupon or product tag.
* Security: guest cart saving only runs while it's switched on, and one visitor can't save carts for a stream of addresses.
* Fix (Pro): paying for an order trashed the customer's other unpaid orders, even ones you made in wp-admin, and even with Trash Unpaid Orders at 0.
* Fix (Pro): a store that holds stock cancelled unpaid orders before their payment reminder could go out.
* Fix (Pro): win-back emails went to the same person every 30 days while they stayed away. Now once per absence.
* Fix (Pro): "always send" emails left out the unsubscribe link for subscribers.
* Fix: filtering by product or category could count an order's total twice in the spend filters, and date filters used UTC days instead of your store's.
* Fix: subjects showed "&amp;" for a store name with "&", and prices as code.
* Fix: times on some screens were off on servers whose database clock isn't UTC.
* Fix: every update re-applied every database column. Updates are quicker now.
* Deleting the plugin now also removes its order notes, expired unused coupons and temporary data.

= 1.2.2 =
* Security: in My Account, a customer could subscribe or unsubscribe an address that isn't theirs by typing it as their billing email. Email Preferences now covers the account email and the billing emails on the customer's own paid orders, and a billing address confirms by email before it subscribes.
* Security: only administrators can activate or deactivate the Pro license.
* Fix: opting out in My Account missed billing addresses on older orders.
* Fix: an unpaid order could count toward an email's revenue. Orders now count once they're paid.
* Privacy: the personal data export now includes the campaigns and automatic emails sent, opens, clicks, orders credited to an email, saved carts and logged email content. Erasing someone's data also deletes the unused coupons made for them.
* Fix: the email preview failed with an error when the newest completed record in the store was a refund.
* New: a campaign's recipient list shows whether each person opened, clicked and ordered.
* New: the License tab links to your backhaus.sg account for renewals, plan changes and invoices (Pro).
* Fix: the Settings number boxes were too narrow for a limit like 10000.
* Free: downloads from backhaus.sg never take an update from another plugin of the same name on WordPress.org.

= 1.2.1 =
* New: Unsubscribe Someone on the Subscribers page. Type an email address to unsubscribe it, whether or not they've ordered, without finding them in the list first.
* Fix: with some plugins installed (Slider Revolution, for one), each setting's help text ran into its field instead of sitting under it.
* Fix: with Dark Mode for WP Dashboard, status labels such as OK couldn't be read, and some text and buttons were too faint. The plugin's screens now follow its dark colors.
* Fix: switching on a newer copy of the plugin (the move to the new name, or from the free edition to Pro) skipped some of the database updates for the new version. They now run.

= 1.2.0 =
* New name: Email Campaigns for WooCommerce (Pro: Email Campaigns Pro for WooCommerce), in a new folder. Activating it switches off the old copy; your data carries over.
* New admin screens: Campaigns, Automatic Emails, Subscribers, Reports and Settings, built on standard WordPress screens and written in plain words.
* Campaigns: a new editor built around the subject, the message and how many people get it, with desktop and mobile previews, a clearer send confirmation, and a page for each sent campaign with progress, results and what happened to each person.
* Send Faster on a sending campaign's page replaces Live Processing. The Queue page is gone.
* Reports: charts and tables for campaigns and automatic emails, without loading scripts from other sites.
* Settings are in tabs. Logs and Status moved into Settings. The opt-out import moved to Subscribers.
* Fix: opens and clicks of automatic emails (Pro) weren't recorded, and a click could be credited to the wrong person. Existing links are repaired on update.
* Fix: an order within the attribution window could be missed by up to your time zone's offset.
* Fix: importing an opt-out list now records the opt-out for everyone on it.
* Fix: setting expired coupons to be kept (0 days) deleted them after a day.
* Fix: saving Settings could switch double opt-in on without anyone ticking it.
* Fix: the settings export no longer includes the CAPTCHA secret or license key, and only administrators can import settings.
* Fix: CSV exports can't carry spreadsheet formulas.
* Fix: an update loaded by two visitors at once is now applied once.
* Free: the "Sent with" credit line under emails is now optional and off by default.
* Removed: Reset and Resend All on campaigns. Duplicate a campaign to send it again; its history stays.

= 1.1.2 =
* New: sending adapts to your mail provider. When it asks you to slow down (rate limits, quotas), sending pauses and backs off by itself, and nobody is charged a failed attempt. A bad address fails at once instead of being retried. Other failures retry after 5, 30 and 120 minutes instead of straight away. If 10 emails in a row fail, sending pauses too.
* New: an Emails Per Minute setting, and the hourly and daily limits now count automatic emails as well as campaigns.
* New: an over-mailing guard. By default a customer gets at most one campaign every 3 days, at most 3 marketing emails a day, and the same automatic email at most once a day. Automatic emails set to always send (reminders) skip the daily limit but not the once-a-day check. Emails over a limit are held, not sent, and you can send them anyway. If many automatic emails are held at once (a rule firing on every order, say), automatic emails pause and you get an email.
* New: before a campaign goes out, the confirmation says how many customers it reaches, how many were emailed recently, and whether a campaign with the same subject went out lately.
* Fix: Force Send no longer says an email was sent to someone who had unsubscribed.
* Clearing the triggered queue keeps the last 30 days of sent emails, which the guard needs.

= 1.1.1 =
* Fix: the Unsubscribe button in Gmail, Yahoo and other mail apps now works (one-click unsubscribe, RFC 8058). Before, it left people subscribed.
* Fix: a campaign could send some emails twice when Live Processing and the background queue ran at the same time. Each email is now claimed by exactly one run.
* Fix: triggered emails could also go out twice when runs overlapped, and a first order could send two welcome emails.
* Fix: `{{ customer.first_name ?? 'there' }}` always printed the fallback. It now prints the real value when there is one. Tags also work without spaces, as in `{{customer.first_name}}`.
* Fix: scheduled campaigns now send at their scheduled time. Before, they finished with nothing sent.
* Fix: someone who unsubscribed can no longer be resubscribed by an old confirmation link, a checkbox at checkout (it now sends one confirmation email instead), or an admin action.
* Fix: after a personal data erasure, the address stays on the do-not-email list, so it isn't emailed again.
* Fix: unsubscribing in My Account now covers the billing email used on orders, not just the account email.
* Fix: a license server error no longer changes anything. In Pro, the license covers updates and support; features never switch off.
* Fix: Free and Pro active together no longer crash the site. Deleting one edition keeps your data while the other is installed.
* Fix: the email preview no longer shows a PHP warning or a real customer's unsubscribe link.
* Links now point to backhaus.sg.
* Tested with WordPress 7.1 and WooCommerce 11.1.

= 1.1.0 =
* Abandoned cart and abandoned order are now separate triggers, and paid orders cancel pending abandoned emails.
* New merge tags: `{{ order.items_card }}`, `{{ order.items_table }}`, fallbacks with `??`, and coupons in triggered emails.
* Open, click and conversion tracking for triggered emails; a queue table with Send Now, Cancel and bulk actions.
* The queue schedule repairs itself if WP-Cron drops it, with a Reschedule button on the Status page.
* Automations were removed.
* Many timezone, click tracking and Live Processing fixes.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.5.3 =
WordPress 7.2 compatibility, and the Sender Domain check finds Mailgun's newer DKIM records.

= 1.5.2 =
Fixes recipient filters that combine order count or spend with role, registered date or an excluded segment.

= 1.5.1 =
Security hardening from the WordPress.org review. Nothing changes in how the plugin works.

= 1.5.0 =
A new name, and a new bhec_ prefix for everything the plugin stores. If you run a copy from before 1.5, contact BackHAUS before updating so your data moves with it.

= 1.4.3 =
Tidier loading for the sign-up form and bot protection, and a stricter check on imported settings files. Sending is unchanged.

= 1.4.2 =
Tidier loading for the sign-up form and bot protection, and a stricter check on imported settings files. Sending is unchanged.

= 1.4.1 =
UTM tags now reach your links while click tracking is on, in campaigns and automatic emails.

= 1.4.0 =
Starter layouts, product cards and confirmation emails in the free edition, a sender domain check, bounce handling, and security fixes for customer details in emails.
