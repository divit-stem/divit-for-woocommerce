<?php

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

function RoundDown($val, $precision) {
    $mult = pow(10, $precision); 
    return floor($val / $mult) * $mult;
}

function RoundUp($val, $precision) {
    $mult = pow(10, $precision); 
    return ceil($val / $mult) * $mult;
}

function RoundNormal($val, $precision) {
    $mult = pow(10, $precision); 
    return round($val / $mult) * $mult;
}

function get_miles_by_reward_statement($reward_statement, $amount) {
    // currently only support InstalmentAmount, RoundUp(), RoundDown(), Round()
    $InstalmentAmount = $amount * 100;
    $statement = $reward_statement;
    $statement = str_replace('Round(', 'RoundNormal(', $statement);
    $statement = str_replace('InstalmentAmount', '$InstalmentAmount', $statement);
    eval(wp_kses_post('$result = ' . $statement . ';'));
    return $result / 100;
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
    if ($method == 'GET') {
        $url = add_query_arg( $body, $url );
    }
    $args = array(
        "method" => $method,
        "headers" => $headers,
        "body" => $method == 'GET' ? array() : json_encode($body),
        'blocking' => true,
        "timeout" => 70,
    );
    $response = wp_remote_request( $url, $args );
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    try {
        $j = json_decode( $response_body, true );
        if ($j) {
            $response_body = $j;
        }
    } catch ( Exception $ex ) { }
    return array(
        "status_code" => $response_code,
        "body" => $response_body,
        "raw" => wp_remote_retrieve_body( $response ),
    );
}

function get_jwt_payload($token) {
    return json_decode(base64_decode(str_replace('_', '/', str_replace('-','+',explode('.', $token)[1]))));
}

function get_utc() {
    return gmdate("Y-m-d\TH:i:s\Z");
}

function isset_all_keys($keys, $arr) {
    foreach($keys as $key){
        if(!array_key_exists($key, $arr)) return false;
    }
    return true;
}

function get_current_url() {
    global $wp;
    return home_url(add_query_arg(array($_GET), $wp->request));
}

function get_redirect_url() {
    if (isset($_GET['redirect'])) {
        return sanitize_text_field($_GET['redirect']);
    }
    return home_url();
}

const COUNTRY_TELS = array(
    array( "countryCode" => "852", "length" => 8 ),
    array( "countryCode" => "853", "length" => 8 ),
    array( "countryCode" => "86", "length" => 11 ),
);

function to_tel($phone) {
    $phone = trim_all($phone);
    if (empty($phone)) return array("countryCode" => "", "tel" => "");
    if (str_starts_with($phone, "+")) {
        $phone = substr($phone, 1);
    }
    foreach(COUNTRY_TELS as $tel) {
        if (str_starts_with($phone, $tel['countryCode'])) {
            return array(
                "countryCode" => "+" . $tel['countryCode'], 
                "tel" => substr($phone, strlen($tel["countryCode"]))
            );
        }
    }
    foreach(COUNTRY_TELS as $tel) {
        if (strlen($phone) == $tel['length']) {
            return array("countryCode" => "+" . $tel['countryCode'], "tel" => $phone); 
        }
    }
    return array("countryCode" => "+852", "tel" => $phone);
}

function trim_all($str) {
    return str_replace(' ', '', $str);
}
