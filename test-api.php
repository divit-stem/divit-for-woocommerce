<?php

$post_fields =  "client_id=123&client_secret=123";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://devwoo.free.beeceptor.com');
curl_setopt($ch, CURLOPT_HTTPHEADER, array('content-type: application/x-www-form-urlencoded'));
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 70);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_VERBOSE, true);
$streamVerboseHandle = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $streamVerboseHandle);
$result = curl_exec($ch);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
$status_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$body = json_decode($result, true);
curl_close($ch);

if ($curl_errno > 0) {
  echo $curl_error;
}

echo print_r($result);

rewind($streamVerboseHandle);
$verboseLog = stream_get_contents($streamVerboseHandle);

echo "cUrl verbose information:\n", 
     "<pre>", htmlspecialchars($verboseLog), "</pre>\n";