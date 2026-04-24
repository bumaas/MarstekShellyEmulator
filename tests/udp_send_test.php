<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/UdpSendTestClient.php';

/**
 * Sends a Shelly-style JSON-RPC UDP request to a local or remote Symcon UDP socket.
 *
 * Usage:
 *   php .\tests\udp_send_test.php
 *   php .\tests\udp_send_test.php 192.168.178.86 1010 Shelly.GetDeviceInfo
 *   php .\tests\udp_send_test.php 127.0.0.1 1010 EM.GetStatus
 *   php .\tests\udp_send_test.php 127.0.0.1 1010 Sys.GetStatus
 */

$host = $argv[1] ?? '127.0.0.1';
$port = isset($argv[2]) ? (int) $argv[2] : 1010;
$method = $argv[3] ?? 'Shelly.GetDeviceInfo';
$requestId = isset($argv[4]) ? (int) $argv[4] : 1;

function writeOut(string $message): void
{
    echo $message . PHP_EOL;
}

function writeErr(string $message): void
{
    $stream = @fopen('php://stderr', 'wb');
    if ($stream !== false) {
        fwrite($stream, $message . PHP_EOL);
        fclose($stream);
        return;
    }

    echo $message . PHP_EOL;
}

try {
    $result = UdpSendTestClient::send($host, $port, $method, $requestId);
} catch (Throwable $exception) {
    writeErr('Unexpected exception: ' . $exception->getMessage());
    exit(1);
}

if (!$result['ok']) {
    writeErr((string) $result['error']);
    exit(1);
}

writeOut("Sent {$result['sentBytes']} bytes to {$host}:{$port}");
writeOut('Request: ' . $result['payload']);

if (($result['response'] === '') && $result['timedOut']) {
    writeOut('No response received within timeout.');
    exit(0);
}

writeOut('Received ' . $result['responseBytes'] . ' bytes.');
writeOut('Response: ' . $result['response']);

if (is_array($result['decodedResponse'])) {
    writeOut('Decoded response:');
    writeOut(json_encode($result['decodedResponse'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}
