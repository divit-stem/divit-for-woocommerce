<?php

// increase this version, settings will be reset to default.
define('DIVIT_SETTING_VERSION', '5');

if (!class_exists('DIVIT_CONST'))
{
    class DIVIT_CONST {
      // osm
      const SETTING_OSM_ENABLED = 'osm-enabled' . DIVIT_SETTING_VERSION;
      // osm miles
      const SETTING_OSM_SINGLE_PRODUCT_PAGE = 'osm-single-product-page' . DIVIT_SETTING_VERSION;
      const SETTING_OSM_LOOP_PRODUCT_PAGE = 'osm-loop-product-page' . DIVIT_SETTING_VERSION;
      const SETTING_OSM_MINI_CART_PAGE = 'osm-mini-cart-page' . DIVIT_SETTING_VERSION;
      const SETTING_OSM_CART_PAGE = 'osm-cart-page' . DIVIT_SETTING_VERSION;
      const SETTING_OSM_CHECKOUT_PAGE = 'osm-checkout-page' . DIVIT_SETTING_VERSION;
      const SETTING_OSM_ORDER_RECEIVE_PAGE = 'osm-order-receive-page' . DIVIT_SETTING_VERSION;
      const SETTING_OSM_ORDER_EMAIL = 'osm-order-email' . DIVIT_SETTING_VERSION;
      // osm tickets
      const SETTING_OSM_SINGLE_PRODUCT_PAGE_TICKETS = 'osm-single-product-page-tickets' . DIVIT_SETTING_VERSION;
      const SETTING_OSM_LOOP_PRODUCT_PAGE_TICKETS = 'osm-loop-product-page-tickets' . DIVIT_SETTING_VERSION;
      const SETTING_OSM_MINI_CART_PAGE_TICKETS = 'osm-mini-cart-page-tickets' . DIVIT_SETTING_VERSION;
      const SETTING_OSM_CART_PAGE_TICKETS = 'osm-cart-page-tickets' . DIVIT_SETTING_VERSION;
      const SETTING_OSM_ORDER_RECEIVE_PAGE_TICKETS = 'osm-order-receive-page-tickets' . DIVIT_SETTING_VERSION;
      const SETTING_OSM_ORDER_EMAIL_TICKETS = 'osm-order-email-tickets' . DIVIT_SETTING_VERSION;
      // sso
      const SETTING_SSO_ENABLED = 'sso-enabled' . DIVIT_SETTING_VERSION;
      const SETTING_SSO_LOGIN_BUTTON_MENU = 'sso-login-button-menu' . DIVIT_SETTING_VERSION;
      // express checkout
      const SETTING_EXPRESS_ENABLED = 'express-enabled' . DIVIT_SETTING_VERSION;
      // promotion
      const SETTING_PROMOTION_CODE_ENABLED = 'promotion-code-enabled' . DIVIT_SETTING_VERSION;
      // checkout button
      const SETTING_CHECKOUT_BUTTON_REPLACEMENT = 'checkout-button-replacement' . DIVIT_SETTING_VERSION;
    }
}