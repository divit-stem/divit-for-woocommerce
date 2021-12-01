<?php

function get_wp_version() {
    global $wp_version;
    return $wp_version;
}

function get_plugin_version() {
    $plugin_dir = WP_PLUGIN_DIR . '/divit-woocommerce/payment-gateway.php';
    $plugin_data = get_plugin_data($plugin_dir);
    return $plugin_data['Version'];
}

function get_product_from_the_post() {
    global $post;
    $product = wc_get_product( $post->ID );
    return $product;
}

function get_order_by_order_number($order_number) {
  $order_id = NULL;
  $args = [
      "post_type" => "shop_order",
      "post_status" => "any",
      "meta_query" => [
          [
              "key" => "_order_number",
              "value" => $order_number,
              "compare" => "=",
          ],
      ],
  ];
  $query = new WP_Query($args);
  if (!empty($query->posts)) {
      $order_id = $query->posts[0]->ID;
  }
  return $order_id;
}

// validate `webhook_event` callback
// t={ts}.s1={base64_hex_binary(ts.body)}
function validate_webhook($signature, $secret, $body) {
    $strs = explode(',', $signature);
    $timestamp = preg_replace('/^t=/', '', $strs[0]);
    $hmac = preg_replace('/^s1=/', '', $strs[1]);
    if (!$timestamp || !$hmac) {
        return false;
    }
    $payload = $timestamp . '.' . $body;
    $calc_hmac = base64_encode(hex2bin(hash_hmac('sha256', $payload, $secret, false)));
    return $hmac == $calc_hmac;
}

function http_request($url, $method, $body, $headers) {
    $ch = curl_init();
    if ($method == 'GET') {
        $url .= '?' . http_build_query($body);
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method == 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
    } elseif ($method == 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!is_string($body)) {
            $body = json_encode($body);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 70);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $body = json_decode($result, true);

    $curl_errno = curl_errno($ch);
    if ($curl_errno > 0) {
        $body = curl_error($ch);
    }

    curl_close($ch);

    return array(
        "status_code" => $status_code,
        "body" => $body
    );
}

function get_jwt_payload($token) {
    return json_decode(base64_decode(str_replace('_', '/', str_replace('-','+',explode('.', $token)[1]))));
}