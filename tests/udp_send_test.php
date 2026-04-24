<?php

declare(strict_types=1);

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
    2
);
if ($socket === false) {
    writeErr("stream_socket_client failed: [{$errorCode}] {$errorMessage}");
    exit(1);
}

stream_set_timeout($socket, 2);

$sent = @fwrite($socket, $payload);
if ($sent === false) {
    writeErr('fwrite failed while sending UDP payload.');
    fclose($socket);
    exit(1);
}

writeOut("Sent {$sent} bytes to {$host}:{$port}");
writeOut("Request: {$payload}");

$responseBuffer = @fread($socket, 8192);
$metadata = stream_get_meta_data($socket);

if ($responseBuffer === false || ($responseBuffer === '' && ($metadata['timed_out'] ?? false))) {
    writeOut('No response received within timeout.');
    fclose($socket);
    exit(0);
}

writeOut('Received ' . strlen($responseBuffer) . " bytes.");
writeOut("Response: {$responseBuffer}");

$decoded = json_decode($responseBuffer, true);
if (is_array($decoded)) {
    writeOut('Decoded response:');
    writeOut(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

fclose($socket);
