<?php

if (!class_exists('DIVIT_UTILS'))
{
    class DIVIT_UTILS {

        static function create_wc_session_if_empty() {
            if ( ! WC()->session->has_session() ) {
                WC()->session->set_customer_session_cookie( true );
            }
            return WC()->session;
        }

        static function get_product_from_the_post() {
            global $post;
            $product = wc_get_product( $post->ID );
            return $product;
        }

        static function get_order_by_order_number($order_number) {
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

        static function RoundDown($val, $precision) {
            $mult = pow(10, $precision); 
            return floor($val / $mult) * $mult;
        }

        static function RoundUp($val, $precision) {
            $mult = pow(10, $precision); 
            return ceil($val / $mult) * $mult;
        }

        static function RoundNormal($val, $precision) {
            $mult = pow(10, $precision); 
            return round($val / $mult) * $mult;
        }

        static function get_miles_by_reward_statement($reward_statement, $amount) {
            // only allow variable: InstalmentAmount
            // only allow functions: RoundUp(), RoundDown(), Round()
            $replacer = '_____';
            $InstalmentAmount = $amount * 100;
            $statement = $reward_statement;
            $statement = str_replace('(', $replacer, $statement);
            $statement = str_replace('Round' . $replacer, 'self::RoundNormal(', $statement);
            $statement = str_replace('RoundUp' . $replacer, 'self::RoundUp(', $statement);
            $statement = str_replace('RoundDown' . $replacer, 'self::RoundDown(', $statement);
            $statement = str_replace('InstalmentAmount', '$InstalmentAmount', $statement);
            eval('$result = ' . $statement . ';');
            return $result / 100;
        }

        // validate `webhook_event` callback
        // t={ts}.s1={base64_hex_binary(ts.body)}
        static function validate_webhook($signature, $secret, $body) {
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

        static function http_request($url, $method, $body, $headers) {
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

        static function get_jwt_payload($token) {
            return json_decode(base64_decode(str_replace('_', '/', str_replace('-','+',explode('.', $token)[1]))));
        }

        static function get_utc() {
            return gmdate("Y-m-d\TH:i:s\Z");
        }

        static function isset_all_keys($keys, $arr) {
            foreach($keys as $key){
                if(!array_key_exists($key, $arr)) return false;
            }
            return true;
        }

        static function get_current_url() {
            $is_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            $protocol = $is_https ? 'https://' : 'http://';
            return $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }

        static function get_redirect_url() {
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

        static function to_tel($phone) {
            $phone = self::trim_all($phone);
            if (empty($phone)) return array("countryCode" => "", "tel" => "");
            if (str_starts_with($phone, "+")) {
                $phone = substr($phone, 1);
            }
            foreach(self::COUNTRY_TELS as $tel) {
                if (str_starts_with($phone, $tel['countryCode'])) {
                    return array(
                        "countryCode" => "+" . $tel['countryCode'], 
                        "tel" => substr($phone, strlen($tel["countryCode"]))
                    );
                }
            }
            foreach(self::COUNTRY_TELS as $tel) {
                if (strlen($phone) == $tel['length']) {
                    return array("countryCode" => "+" . $tel['countryCode'], "tel" => $phone); 
                }
            }
            return array("countryCode" => "+852", "tel" => $phone);
        }

        static function trim_all($str) {
            return str_replace(' ', '', $str);
        }

    }
}