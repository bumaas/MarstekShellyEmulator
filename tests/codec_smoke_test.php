<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/MeasurementSnapshot.php';
require_once dirname(__DIR__) . '/libs/UdpRpcCodec.php';
require_once dirname(__DIR__) . '/libs/ShellyResponseBuilder.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$meterName = json_decode('"\u005a\u00e4hler \u00dc"', true, 512, JSON_THROW_ON_ERROR);
$kitchenName = json_decode('"\u005a\u00e4hler K\u00fcche"', true, 512, JSON_THROW_ON_ERROR);
$hostName = json_decode('"\u006b\u00fcche-pro-3em"', true, 512, JSON_THROW_ON_ERROR);
$firmwareVersion = json_decode('"1.0.0-\u00e4\u00f6\u00fc"', true, 512, JSON_THROW_ON_ERROR);

$decoded = UdpRpcCodec::decode('{"id":1,"method":"Shelly.GetDeviceInfo","params":{"name":"Zähler Ü"}}');
assertTrue($decoded['ok'] === true, 'valid request should decode successfully');
assertTrue($decoded['message']['method'] === 'Shelly.GetDeviceInfo', 'method should be extracted');
assertTrue($decoded['message']['params']['name'] === $meterName, 'Umlaute must survive decoding');

$invalid = UdpRpcCodec::decode('{"id":1,"params":{}}');
assertTrue($invalid['ok'] === false, 'request without method should fail');
assertTrue(($invalid['error']['error']['code'] ?? null) === -32600, 'invalid request code should be returned');

$stringProperties = [
    'deviceName' => $kitchenName,
    'hostname' => $hostName,
    'macAddress' => '001122334455',
    'firmwareVersion' => $firmwareVersion,
];

$deviceInfo = ShellyResponseBuilder::buildDeviceInfo($stringProperties);
$encoded = UdpRpcCodec::encode(UdpRpcCodec::buildSuccessResponse(7, $deviceInfo));

assertTrue(str_contains($encoded, $kitchenName), 'encoded JSON should contain literal UTF-8 device name');
assertTrue(str_contains($encoded, $hostName), 'encoded JSON should contain literal UTF-8 hostname');
assertTrue(!str_contains($encoded, '\\u00'), 'encoded JSON should not escape UTF-8 characters');

$snapshot = new MeasurementSnapshot();
$snapshot->isValid = true;
$snapshot->totalActivePowerW = 1234.5;
$snapshot->phaseAActivePowerW = 411.5;
$snapshot->phaseBActivePowerW = 411.5;
$snapshot->phaseCActivePowerW = 411.5;

$emStatus = ShellyResponseBuilder::buildEmStatus($snapshot);
assertTrue(($emStatus['total_act_power'] ?? null) === 1234.5, 'EM status should expose total power');

fwrite(STDOUT, "codec_smoke_test: ok\n");
