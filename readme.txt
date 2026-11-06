=== Divit Payment Gateway ===
Contributors: janderson852, kyktommy, samlin-ws
Tags: Divit,FPS,Hong Kong,Payment,Payment Gateway
Requires at least: 5.0
Tested up to: 7.0.0
Stable tag: 1.8.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Divit Payment Gateway for WooCommerce

== Description ==

This plugin will add the divit Real-time pay by bank account payment gateway for your WooCommerce.

Divit is a Hong Kong based fintech company building the best integrations with local RTP (real time payment) systems globally. We are live in Hong Kong with FPS, and are adding further countries rapidly. 

Customers can:
- Make secure real-time bank transfers by QR Code or Manual Payment using their own banking app.
- Mobile Payments: Web-to-App payments, meaning customers don't need to scan a QR code, they just choose their preferred bank app
- App based payments: Seamless in-app payments via FPS App-to-App, allowing customers to pay directly from their bank app within your iOS and Android applications

You can find more details in our websites:

- [sign-up](https://admin.divit.com.hk/en/signup)
- [website](https://divit.com.hk)
- [developer portal](https://developer.divit.com.hk/docs/platforms/woocommerce/overview).

Countries Supported:
- Hong Kong SAR, China (FPS, Faster Payments System)

== Installation ==

To install Divit Payment Gateway, you need:

* WordPress Version 5.3 or newer (installed)
* WooCommerce Version 3.9 or newer (installed and activated)
* PHP Version 7.4 or newer
* Divit merchant account - Signup at (https://admin.divit.com.hk/en/signup)

= Instructions =

1. Log in to WordPress admin.
2. Go to **Plugins > Add New**.
3. Search for the **Divit Payment Gateway** plugin.
4. Click on **Install Now** and wait until the plugin is installed successfully.
5. You can activate the plugin immediately by clicking on **Activate** now on the success page. If you want to activate it later, you can do so via **Plugins > Installed Plugins**.

= Setup and Configuration =

Follow the steps below to connect the plugin to your Divit merchant account:

1. After you have activated the Divit Payment Gateway plugin, go to **WooCommerce  > Settings**.
2. Click the **Payments** tab.
3. The Payment methods list will include a Divit Payment option. Click on **divit payment gateway**.
4. Select **Sandbox (for testing) / Production** in **environment** field.
5. Input your <API-Key> which we will send you by email, and enter it in **Api key** field.
6. Click **Save changes**, you will see merchant account details in the top of current page.
7. Done

== Frequently Asked Questions ==

= Who is divit? =

We are a Hong Kong based fintech company. We integrate loyalty into real-time payment platforms around the world. 

= What is FPS? =

The Hong Kong Monetary Authority (HKMA) launched the Faster Payment System (FPS) on 17 September 2018. FPS enables customers to make safe instant payments from their bank account or e-wallet on a 24/7 basis.

There are over 13 million FPS registrations in Hong Kong at the end of Dec 2023. All of the major banks in Hong Kong support FPS for the transfer of funds, both for Individual and SME accounts.

= Contact divit =

contact us with [hello@divit.com.hk](mailto:hello@divit.com.hk) or [+85262708800](tel:+85262708800)

== Screenshots ==

1. Setup guide
2. WooCommerce Payment option
2. Checkout Payment option
3. Divit FPS checkout

== Changelog ==

= 1.8.1 =
* Fix: Payment amount rounding.
* Fix: Checkout total display, including Block checkout updates.
* Fix: Webhook handling.
* Fix: Refund reference handling.

= 1.8.0 =
* Security: Hardened admin AJAX endpoints with CSRF nonce verification and privilege capability checks.
* Security: Implemented direct execution protections (ABSPATH checks) across all plugin script files.
* Security: Ensured robust sanitization and escaping of administrative and checkout page outputs.
* Cleanup: Removed deprecated and unused legacy features (including Express Checkout, on-site messaging, miles configurations, and Cron tasks).
* Optimization: Fully updated and synchronized localization translation files, stripping out obsolete entries.

= 1.7.16 =
* Fix: webhook url

= 1.7.15 =
* Refactor: webhook validation error handling

= 1.7.14 =
* Fix: get cart total

= 1.7.13 =
* Fix: refund function update

= 1.7.12 =
* Feat: payment page shows Pay now text

= 1.7.11 =
* Chore - plugin name and translation

= 1.7.10 =
* Fix - remove unnecessary warning logs

= 1.7.9 =
* Fix - setting default values warning

= 1.7.7 =
* Fix - prefill signature key 

= 1.7.6 =
* Feat - customize signature key 

= 1.7.5 =
* Feat - better setting behaviour

= 1.7.4 =
* Feat - support maintenance mode
* Fix - user login

= 1.7.3 =
* Feat - checkout block support

= 1.7.2 =
* Feat - add FPS guide link
* Feat - updated checkout ui

= 1.7.0 =
* Breaking - disable miles rewards
* Feat - simpify admin UI
* Feat - installation status feedback

= 1.6.11 =
* Fix - order links to divit new admin-portal

= 1.6.10 =
* Updated - new create order payload

= 1.6.9 =
* Fix - disable unused product level campaign settings

= 1.6.8 =
* Fix - hpos compatibility

= 1.6.7 =
* Fix - update reward message

= 1.6.6 =
* Fix - create order parameter

= 1.6.5 =
* Fix - check order data at thank you page

= 1.6.4 =
* Fix - prevent using session variable in email hook

= 1.6.3 =
* Fix - avoid getting rate when campaign is not defined

= 1.6.2 =
* Fix - avoid using short php open tag

= 1.6.1 =
* Fix - get production variation logic and javascript to calculate miles

= 1.6.0 =
* Feat - promotion message on products, cart, checkout page

= 1.5.2 =
* Fix - new promotion message

= 1.5.1 =
* Fix - promotion message date comparison

= 1.5.0 =
* Feat - promotion message at checkout

= 1.4.12 =
* Fix - coupon discount calculation logic

= 1.4.11 =
* Fix - redirect to divit payment page with the same locale language as woocommerce
* Fix - translation

= 1.4.10 =
* Feat - partial multi-language support
* Fix - order total calculation when applying coupon

= 1.4.9 =
* Feat - add extra data to order

= 1.4.8 =
* Fix - spend miles checkout method error

= 1.4.7 =
* Fix - payment failed webhook handling

= 1.4.6 =
* Fix - single sign on redirect url

= 1.4.5 =
* Fix - single sign on redirect url
* Fix - promotion code display

= 1.4.4 =
* Fix - promotion code display correct miles rate

= 1.4.3 =
* Feat - more controlable settings

= 1.3.15 =
* Feat - express checkout, single sign on
