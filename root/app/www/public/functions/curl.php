<?php

/*
----------------------------------
 ------  Created: 070221   ------
 ------  Austin Best	   ------
----------------------------------
*/

function curl($url, $headers = [], $method = 'GET', $payload = '', $userPass = [], $timeout = 60)
{
    $payload = !is_string($payload) ? '' : $payload;

    $curlHeaders    = [
                        'user-agent:' . APP_NAME,
                        'content-length:' . strlen($payload),
                        'Content-Type:application/json'
                    ];

    if ($headers) {
        foreach ($headers as $header) {
            $curlHeaders[] = $header;
        }
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    if (!str_contains($url, '/api/')) {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    }

    switch ($method) {
        case 'DELETE':
        case 'PATCH':
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            break;
        default:
            unset($payload);
            break;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

    if ($payload && $method != 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    if ($userPass) {
        $user = $userPass[0];
        $pass = $userPass[1];
        curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }

    $response       = curl_exec($ch);
    $jsonResponse   = json_decode($response, true);
    $response       = !empty($jsonResponse) ? $jsonResponse : $response;
    $error          = json_decode(curl_error($ch), true);
    $code           = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [
            'url'       => $url,
            'method'    => $method,
            'payload'   => $payload,
            'response'  => $response,
            'error'     => $error,
            'code'      => $code
        ];
}
