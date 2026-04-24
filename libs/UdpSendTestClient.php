<?php

declare(strict_types=1);

/**
 * Sends Shelly-style JSON-RPC UDP requests and returns a structured result
 * so the same implementation can be reused from CLI tests and Symcon scripts.
 */
final class UdpSendTestClient
{
    public static function send(
        string $host = '127.0.0.1',
        int $port = 1010,
        string $method = 'Shelly.GetDeviceInfo',
        int $requestId = 1,
        int $timeoutSeconds = 2
    ): array {
        $request = [
            'id' => $requestId,
            'src' => 'php-udp-test',
            'method' => $method,
            'params' => new stdClass(),
        ];

        $payload = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $socket = @stream_socket_client(
            sprintf('udp://%s:%d', $host, $port),
            $errorCode,
            $errorMessage,
            $timeoutSeconds
        );
        if ($socket === false) {
            return [
                'ok' => false,
                'error' => sprintf('stream_socket_client failed: [%s] %s', (string) $errorCode, $errorMessage),
                'request' => $request,
                'payload' => $payload,
            ];
        }

        stream_set_timeout($socket, $timeoutSeconds);

        $sent = @fwrite($socket, $payload);
        if ($sent === false) {
            fclose($socket);

            return [
                'ok' => false,
                'error' => 'fwrite failed while sending UDP payload.',
                'request' => $request,
                'payload' => $payload,
            ];
        }

        $responseBuffer = @fread($socket, 8192);
        $metadata = stream_get_meta_data($socket);
        fclose($socket);

        $timedOut = ($metadata['timed_out'] ?? false) === true;
        $decoded = null;

        if (is_string($responseBuffer) && $responseBuffer !== '') {
            $decoded = json_decode($responseBuffer, true);
            if (!is_array($decoded)) {
                $decoded = null;
            }
        }

        return [
            'ok' => true,
            'request' => $request,
            'payload' => $payload,
            'sentBytes' => (int) $sent,
            'response' => is_string($responseBuffer) ? $responseBuffer : '',
            'responseBytes' => is_string($responseBuffer) ? strlen($responseBuffer) : 0,
            'decodedResponse' => $decoded,
            'timedOut' => $timedOut,
        ];
    }
}

function MarstekUdpSendTest(
    string $host = '127.0.0.1',
    int $port = 1010,
    string $method = 'Shelly.GetDeviceInfo',
    int $requestId = 1,
    int $timeoutSeconds = 2
): array {
    return UdpSendTestClient::send($host, $port, $method, $requestId, $timeoutSeconds);
}
