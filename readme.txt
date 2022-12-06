=== Divit Rewards and Payment Gateway ===
Contributors: janderson852, kyktommy 
Tags: Divit,FPS,Hong Kong,Payment,Payment Gateway,Rewards,Air Miles,Miles,Earn,Spend
Requires at least: 5.0
Tested up to: 6.1
Stable tag: 1.4.9
Requires PHP: 5.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Divit Payment Gateway for WooCommerce

== Description ==

This plugin will add the Divit Rewards and Payment Gateway for your WooCommerce.

Divit is a Hong Kong based fintech company partnered with the worlds leading airlines. We integrate loyalty with real-time payments. 

Customers can:
- Make secure real-time bank transfers by QR Code or Manual Payment using their own banking app.
- Earn Miles for their purchase: at a fixed or variable rate or for selected products
- Spend Miles for a deduction in their cash spend at the checkout

You can find more details in our websites:

- [website](https://divit.com.hk)
- [shop](https://shop.divit.com.hk)
- [developer portal](https://developer.divit.com.hk/docs/platforms/woocommerce/overview).

Countries Supported:
- Hong Kong SAR, China

== Installation ==

To install Divit Payment Gateway, you need:

* WordPress Version 5.3 or newer (installed)
* WooCommerce Version 3.9 or newer (installed and activated)
* PHP Version 7.1 or newer
* Divit merchant account

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
7. Then select **Order campaign** for your reward miles campaign, eg: hk$2 to 1 mile.
8. Click **Save changes**.

== Frequently Asked Questions ==

= Who is divit? =

We are a Hong Kong based fintech company partnered with Cathay. We integrate loyalty into real-time payments, enabling consumers to earn and spend miles when they pay with FPS.

= What is FPS? =

The Hong Kong Monetary Authority (HKMA) launched the Faster Payment System (FPS) on 17 September 2018. FPS enables customers to make safe instant payments from their bank account or e-wallet on a 24/7 basis.

There are over 10 million FPS registrations in Hong Kong at the end of August 2022. All of the major banks in Hong Kong support FPS for the transfer of funds, both for Individual and SME accounts.

= What is a divit mile? = 

A divit mile is our loyalty award which you earn with every purchase at a divit merchant. It is converatble into other loyalty programs, which we are adding to all the time. 

= Contact divit =

contact us with [hello@divit.com.hk](mailto:hello@divit.com.hk) or [+85262708800](tel:+85262708800)

== Screenshots ==

1. Setup guide
2. WooCommerce Payment option
2. Checkout Payment option
3. Divit FPS checkout

== Changelog ==

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