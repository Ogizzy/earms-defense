<?php
// config/wings.php — WINGS conferencing integration.
// Host starts a moderated meeting via the WINGS API; participants join by URL.
// Docs: POST {WINGS_API_BASE}/meetings/start  (header: API-Key)
//   params: roomName, displayName, email, userId
//   returns: { "adminUrl": "https://meetings.bdic.ng/<room>?jwt=..." }

require_once __DIR__ . '/config.php';

if (!function_exists('wings_room_name')) {
    function wings_room_name(string $reference): string {
        // Deterministic, URL-safe room per defense.
        return WINGS_ROOM_PREFIX . '-' . preg_replace('/[^A-Za-z0-9]/', '', $reference);
    }
}

if (!function_exists('wings_join_url')) {
    function wings_join_url(string $roomName, string $displayName = ''): string {
        $url = rtrim(WINGS_MEET_BASE, '/') . '/' . rawurlencode($roomName);
        if ($displayName !== '') {
            $url .= '#userInfo.displayName=' . rawurlencode($displayName);
        }
        return $url;
    }
}

if (!function_exists('wings_start_meeting')) {
    /* Calls the WINGS API to start/host a meeting.
     * Returns ['ok'=>true,'adminUrl'=>...] or ['ok'=>false,'error'=>...,'raw'=>...,'code'=>...]. */
    function wings_start_meeting(string $roomName, string $displayName, string $email, $userId): array {
        $endpoint = rtrim(WINGS_API_BASE, '/') . '/meetings/start';
        $params = [
            'roomName'    => $roomName,
            'displayName' => $displayName,
            'email'       => $email,
            'userId'      => (string)$userId,
        ];
        $headers = ['API-Key: ' . WINGS_API_KEY, 'Accept: application/json'];

        // Preferred: cURL (present on virtually all cPanel PHP installs).
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $endpoint,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($params),
                CURLOPT_HTTPHEADER     => array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);
            if ($body === false) return ['ok' => false, 'error' => 'Could not reach WINGS: ' . $cerr, 'code' => 0];
        } else {
            // Fallback: stream context.
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", array_merge($headers, ['Content-Type: application/x-www-form-urlencoded'])),
                'content'       => http_build_query($params),
                'timeout'       => 20,
                'ignore_errors' => true,
            ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
            $body = @file_get_contents($endpoint, false, $ctx);
            $code = 0;
            if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
            if ($body === false) return ['ok' => false, 'error' => 'Could not reach WINGS (stream).', 'code' => 0];
        }

        $json = json_decode($body, true);
        if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['adminUrl'])) {
            return ['ok' => true, 'adminUrl' => $json['adminUrl']];
        }
        return ['ok' => false, 'error' => "WINGS API error (HTTP $code)", 'code' => $code, 'raw' => is_string($body) ? substr($body, 0, 500) : ''];
    }
}
