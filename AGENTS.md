# AGENTS.md

Welcome, AI Agent! This file is designed to help you quickly understand, explore, and work on the **Divit FPS Payment Gateway for WooCommerce** repository.

## 🚀 Project Overview
This repository contains the official WooCommerce payment gateway integration for **FPS by Divit**—a real-time, QR-code, Web-to-App, and App-to-App payment gateway for Hong Kong merchants using the Faster Payment System (FPS).

* **Active Plugin Directory**: `github/divit-for-woocommerce/`
* **Supported Currency**: HKD only (Hong Kong Dollar)
* **Target Environment**: WordPress 5.3+ to 7.0.0+, WooCommerce 3.9+, PHP 7.4+

---

## 📂 Key Codebase Files

| File | Purpose |
| :--- | :--- |
| `payment-gateway.php` | The main plugin bootstrap entry file. Registers actions, hooks, filters, and block compatibility. |
| `wc-divit-payment-gateway.php` | The core gateway settings, logic, admin fields, API request signing, and order checkout handlers. |
| `wc-divit-payment-gateway-blocks-support.php` | Declares support for modern WooCommerce Blocks-based checkout. |
| `assets/js/divit-gateway-blocks.js` | Client-side frontend script rendering the Divit payment option within the Checkout Block. |
| `assets/images/fps_by_divit.png` | **Frontend checkout icon**: Displayed next to the payment option on checkout pages. |
| `assets/images/icon.png` | **Backend icon**: Displayed in the WooCommerce Admin (Settings > Payments > Payment providers). |
| `languages/` | Standard `.pot`, `.po`, and `.mo` localization files. |

---

## ⚠️ Important Implementation Guidelines

1. **Keep Core Separation of Icons**:
   - Backend gateway icon (`$this->icon`) must use `assets/images/icon.png`.
   - Frontend classic checkout (`filter_woocommerce_gateway_icon` hook) and modern Blocks checkout (`get_payment_method_data`) must use `assets/images/fps_by_divit.png`.

2. **Gutenberg / Blocks script dependencies**:
   - `wc-divit-blocks-integration` script depends on standard WordPress packages: `'wc-blocks-registry'`, `'wc-settings'`, `'wp-element'`, and `'wp-html-entities'`.
   - Use `window.wp.htmlEntities.decodeEntities` to parse any dynamic localized or PHP-transferred variables containing entities (such as currency symbols or custom copy).

3. **Ignore Staging Directory**:
   - Avoid modifying files inside the temporary or staging workspace directory (`./divit-rewards-and-payment-gateway/` if present in parent folders). Only perform modifications inside `github/divit-for-woocommerce/`.

4. **Compatibility Declarations**:
   - Declaring compatibility with modern features must be kept in the main plugin file (`payment-gateway.php`) via `FeaturesUtil::declare_compatibility()` on the `before_woocommerce_init` action hook:
     - `custom_order_tables` (High-Performance Order Storage / HPOS)
     - `cart_checkout_blocks` (WooCommerce Block Checkout)

---

## 🛠️ Testing Flows

* **To test block-based checkout compatibility**: Ensure block-based payment registration is active, and view a page using modern Cart & Checkout blocks.
* **To test classic checkout compatibility**: Switch your target WordPress checkout page to the classic `[woocommerce_checkout]` shortcode.
