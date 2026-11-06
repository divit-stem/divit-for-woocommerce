<?php

defined( 'ABSPATH' ) || exit;

if (!class_exists('DIVIT_UTILS'))
{
    class DIVIT_UTILS {


        // validate `webhook_event` callback
        // t={ts}.s1={base64_hex_binary(ts.body)}
        static function validate_webhook($signature, $secret, $body) {
            $strs = explode(',', $signature);
            $timestamp = '';
            $hmac = '';
            foreach ($strs as $str) {
                $str = trim($str);
                if (strpos($str, 't=') === 0) {
                    $timestamp = substr($str, 2);
                } elseif (strpos($str, 's1=') === 0) {
                    $hmac = substr($str, 3);
                }
            }
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
                "timeout" => 15,
            );
            $response = wp_remote_request( $url, $args );
            $response_code = wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );

            if (is_wp_error($response)) {
                return array(
                    "status_code" => 400,
                    "body" => 'timeout',
                );
            }

            try {
                $j = json_decode( $response_body, true );
                if ($j) {
                    $response_body = $j;
                }
            } catch ( Exception $ex ) { 
                return array(
                    "status_code" => 400,
                    "body" => 'response body decode error',
                );
            }

            return array(
                "status_code" => $response_code,
                "body" => $response_body,
                "raw" => wp_remote_retrieve_body( $response ),
            );
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

        static function deepclone($obj) {
            return unserialize(serialize($obj));
        }

        static function rand_id() {
            $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
            return substr(str_shuffle($chars), 0, 8);
        }
    }
}