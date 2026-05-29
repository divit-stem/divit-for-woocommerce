<?php

defined( 'ABSPATH' ) || exit;

return array(
	"dev" => array(
    "name" => "dev",
		"api_url" =>  "https://dev-api.divit.dev",
    "web_url" => "https://dev-consumer.divit.dev",
    "static_url" => "https://static.divit.dev",
    "partner_portal_url" => "https://dev-admin.divit.dev"
  ),
  "sandbox" => array(
    "name" => "sandbox",
		"api_url" =>  "https://sandbox-api.divit.dev",
    "web_url" => "https://sandbox-consumer.divit.dev",
    "static_url" => "https://static.divit.dev",
    "partner_portal_url" => "https://sandbox-admin.divit.dev"
  ),
	"production" => array(
    "name" => "production",
		"api_url" =>  "https://api.divit.com.hk",
    "web_url" => "https://app.divit.com.hk",
    "static_url" => "https://static.divit.com.hk",
    "partner_portal_url" => "https://admin.divit.com.hk"
  ),
);
