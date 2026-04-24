<?php

declare(strict_types=1);

require_once __DIR__ . '/MeasurementSnapshot.php';

/**
 * Maps module properties and snapshots to Shelly-compatible RPC payloads.
 */
final class ShellyResponseBuilder
{
    /**
     * @return string[]
     */
    public static function buildMethodList(): array
    {
        return [
            'Shelly.GetDeviceInfo',
            'Shelly.GetStatus',
            'Shelly.ListMethods',
            'Sys.GetConfig',
            'Sys.GetStatus',
            'EM.GetStatus',
        ];
    }

    /**
     * @param array<string, string> $stringProperties
     */
    public static function buildDeviceInfo(array $stringProperties): array
    {
        return [
            'name' => $stringProperties['deviceName'] ?? '',
            'id' => strtolower($stringProperties['hostname'] ?? ''),
            'mac' => $stringProperties['macAddress'] ?? '',
            'fw_id' => $stringProperties['firmwareVersion'] ?? '',
            'ver' => $stringProperties['firmwareVersion'] ?? '',
            'app' => 'MarstekShellyEmulator',
            'model' => 'Shelly Pro 3EM',
            'gen' => 2,
            'auth_en' => false,
            'profile' => 'triphase',
        ];
    }

    /**
     * @param array<string, string> $stringProperties
     */
    public static function buildSysStatus(array $stringProperties): array
    {
        return [
            'mac' => $stringProperties['macAddress'] ?? '',
            'hostname' => $stringProperties['hostname'] ?? '',
            'time' => date('H:i'),
            'unixtime' => time(),
            'uptime' => 0,
            'ram_size' => 0,
            'ram_free' => 0,
            'fs_size' => 0,
            'fs_free' => 0,
            'cfg_rev' => 1,
            'available_updates' => new stdClass(),
        ];
    }

    /**
     * @param array<string, string> $stringProperties
     */
    public static function buildSysConfig(array $stringProperties): array
    {
        return [
            'device' => [
                'name' => $stringProperties['deviceName'] ?? '',
                'hostname' => $stringProperties['hostname'] ?? '',
                'mac' => $stringProperties['macAddress'] ?? '',
                'fw_id' => $stringProperties['firmwareVersion'] ?? '',
                'discoverable' => true,
            ],
            'rpc_udp' => [
                'dst_addr' => '',
                'listen_port' => 1010,
            ],
        ];
    }

    /**
     * @param array<string, string> $stringProperties
     */
    public static function buildShellyStatus(array $stringProperties, MeasurementSnapshot $snapshot): array
    {
        return [
            'sys' => self::buildSysStatus($stringProperties),
            'cloud' => [
                'connected' => false,
            ],
            'mqtt' => [
                'connected' => false,
            ],
            'ble' => new stdClass(),
            'eth' => new stdClass(),
            'em:0' => self::buildEmStatus($snapshot),
        ];
    }

    public static function buildEmStatus(MeasurementSnapshot $snapshot): array
    {
        return [
            'id' => 0,
            'total_act_power' => $snapshot->totalActivePowerW,
            'a_act_power' => $snapshot->phaseAActivePowerW,
            'b_act_power' => $snapshot->phaseBActivePowerW,
            'c_act_power' => $snapshot->phaseCActivePowerW,
            'a_voltage' => $snapshot->phaseAVoltageV,
            'b_voltage' => $snapshot->phaseBVoltageV,
            'c_voltage' => $snapshot->phaseCVoltageV,
            'a_current' => $snapshot->phaseACurrentA,
            'b_current' => $snapshot->phaseBCurrentA,
            'c_current' => $snapshot->phaseCCurrentA,
            'a_freq' => $snapshot->frequencyHz,
            'b_freq' => $snapshot->frequencyHz,
            'c_freq' => $snapshot->frequencyHz,
            'total_imported' => $snapshot->totalImportedEnergyWh,
            'total_exported' => $snapshot->totalExportedEnergyWh,
            'errors' => [],
        ];
    }
}
