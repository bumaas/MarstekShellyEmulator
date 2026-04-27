<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/MeasurementSnapshot.php';
require_once dirname(__DIR__) . '/libs/MeasurementCalculator.php';
require_once dirname(__DIR__) . '/libs/UdpRpcCodec.php';
require_once dirname(__DIR__) . '/libs/ShellyResponseBuilder.php';
require_once dirname(__DIR__) . '/libs/DiscoveryHandler.php';
require_once dirname(__DIR__) . '/libs/Validation.php';

/**
 * Symcon module entry point for the Shelly Pro 3EM emulator.
 *
 * The module owns configuration, request dispatch, and transport integration.
 * Protocol payload generation and meter-state calculation stay in dedicated
 * helper classes to keep transport code separated from domain logic.
 */
class ShellyEmulator extends IPSModuleStrict
{
    private const string UDP_DATAFLOW_TX                  = '{8E4D9B23-E0F2-1E05-41D8-C21EA53B8706}';
    private const string UDP_SOCKET_MODULE_ID             = '{82347F20-F541-41E1-AC5B-A636FD3AE2D8}';
    private const int    DEFAULT_PARENT_BIND_PORT         = 1010;
    private const string PROP_DEVICE_NAME                 = 'deviceName';
    private const string PROP_HOSTNAME                    = 'hostname';
    private const string PROP_MAC_ADDRESS                 = 'macAddress';
    private const string PROP_FIRMWARE_VERSION            = 'firmwareVersion';
    private const string PROP_POWER_TOTAL_VAR_ID          = 'powerTotalVarId';
    private const string PROP_GRID_IMPORT_POWER_VAR_ID    = 'gridImportPowerVarId';
    private const string PROP_GRID_EXPORT_POWER_VAR_ID    = 'gridExportPowerVarId';
    private const string PROP_POWER_L1_VAR_ID             = 'powerL1VarId';
    private const string PROP_GRID_IMPORT_POWER_L1_VAR_ID = 'gridImportPowerL1VarId';
    private const string PROP_GRID_EXPORT_POWER_L1_VAR_ID = 'gridExportPowerL1VarId';
    private const string PROP_POWER_L2_VAR_ID             = 'powerL2VarId';
    private const string PROP_GRID_IMPORT_POWER_L2_VAR_ID = 'gridImportPowerL2VarId';
    private const string PROP_GRID_EXPORT_POWER_L2_VAR_ID = 'gridExportPowerL2VarId';
    private const string PROP_POWER_L3_VAR_ID             = 'powerL3VarId';
    private const string PROP_GRID_IMPORT_POWER_L3_VAR_ID = 'gridImportPowerL3VarId';
    private const string PROP_GRID_EXPORT_POWER_L3_VAR_ID = 'gridExportPowerL3VarId';
    private const string PROP_VOLTAGE_L1_VAR_ID           = 'voltageL1VarId';
    private const string PROP_VOLTAGE_L2_VAR_ID           = 'voltageL2VarId';
    private const string PROP_VOLTAGE_L3_VAR_ID           = 'voltageL3VarId';
    private const string PROP_CURRENT_L1_VAR_ID           = 'currentL1VarId';
    private const string PROP_CURRENT_L2_VAR_ID           = 'currentL2VarId';
    private const string PROP_CURRENT_L3_VAR_ID           = 'currentL3VarId';
    private const string PROP_IMPORT_ENERGY_VAR_ID        = 'importEnergyVarId';
    private const string PROP_EXPORT_ENERGY_VAR_ID        = 'exportEnergyVarId';
    private const string PROP_MAX_MEASUREMENT_AGE         = 'maxMeasurementAge';
    private const string PROP_PHASE_MODE                  = 'phaseMode';
    private const string PROP_ENERGY_SCALE_FACTOR         = 'energyScaleFactor';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString(self::PROP_DEVICE_NAME, 'Shelly Pro 3EM');
        $this->RegisterPropertyString(self::PROP_HOSTNAME, 'shellypro3em-emulator');
        $this->RegisterPropertyString(self::PROP_MAC_ADDRESS, '001122334455');
        $this->RegisterPropertyString(self::PROP_FIRMWARE_VERSION, '1.0.0');
        $this->RegisterPropertyInteger(self::PROP_POWER_TOTAL_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_GRID_IMPORT_POWER_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_GRID_EXPORT_POWER_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_POWER_L1_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_GRID_IMPORT_POWER_L1_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_GRID_EXPORT_POWER_L1_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_POWER_L2_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_GRID_IMPORT_POWER_L2_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_GRID_EXPORT_POWER_L2_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_POWER_L3_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_GRID_IMPORT_POWER_L3_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_GRID_EXPORT_POWER_L3_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_VOLTAGE_L1_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_VOLTAGE_L2_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_VOLTAGE_L3_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_CURRENT_L1_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_CURRENT_L2_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_CURRENT_L3_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_IMPORT_ENERGY_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_EXPORT_ENERGY_VAR_ID, 0);
        $this->RegisterPropertyInteger(self::PROP_MAX_MEASUREMENT_AGE, 10);
        $this->RegisterPropertyString(self::PROP_PHASE_MODE, 'direct');
        $this->RegisterPropertyFloat(self::PROP_ENERGY_SCALE_FACTOR, 1.0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->registerReferences();

        $this->SetSummary($this->ReadPropertyString(self::PROP_HOSTNAME));

        $energyScaleFactor = $this->ReadPropertyFloat(self::PROP_ENERGY_SCALE_FACTOR);
        if (!Validation::isPositiveFactor($energyScaleFactor)) {
            $this->SendDebug(__FUNCTION__, 'Configured energy scale factor is invalid; using 1.0 internally', 0);
        }

        if ($this->shouldWarnAboutDirectPhaseMode()) {
            $this->SendDebug(
                __FUNCTION__,
                'No phase power values are configured while phaseMode=direct. Configure L1-L3 or select split_total/total_on_l1.',
                0
            );
        }
    }

    public function GetCompatibleParents(): string
    {
        return json_encode([
                               'type'      => 'connect',
                               'moduleIDs' => [
                                   self::UDP_SOCKET_MODULE_ID,
                               ],
                           ],
                           JSON_THROW_ON_ERROR);
    }

    public function GetConfigurationForParent(): string
    {
        return json_encode([
                               'BindPort' => self::DEFAULT_PARENT_BIND_PORT
                           ], JSON_THROW_ON_ERROR);
    }

    public function ReceiveData(string $JSONString): string
    {
        $this->SendDebug(__FUNCTION__ . ' (raw)', $JSONString, 0);

        // Symcon's UDP socket forwards payload plus sender metadata in a JSON envelope.
        [$payload, $remoteIp, $remotePort] = $this->extractTransportContext($JSONString);
        if ($payload === '') {
            return '';
        }

        $this->SendDebug(__FUNCTION__ . ' (payload)', $payload, 0);

        $response = $this->handleRpcRequest($payload, $remoteIp, $remotePort);
        if ($response === null) {
            return '';
        }

        $encodedResponse = UdpRpcCodec::encode($response);
        $this->sendUdpReply($response, $remoteIp, $remotePort);

        return $encodedResponse;
    }

    private function buildSnapshot(): MeasurementSnapshot
    {
        return MeasurementCalculator::fromConfiguration(
            [
                self::PROP_POWER_TOTAL_VAR_ID          => $this->ReadPropertyInteger(
                    self::PROP_POWER_TOTAL_VAR_ID
                ),
                self::PROP_GRID_IMPORT_POWER_VAR_ID    => $this->ReadPropertyInteger(
                    self::PROP_GRID_IMPORT_POWER_VAR_ID
                ),
                self::PROP_GRID_EXPORT_POWER_VAR_ID    => $this->ReadPropertyInteger(
                    self::PROP_GRID_EXPORT_POWER_VAR_ID
                ),
                self::PROP_POWER_L1_VAR_ID             => $this->ReadPropertyInteger(
                    self::PROP_POWER_L1_VAR_ID
                ),
                self::PROP_GRID_IMPORT_POWER_L1_VAR_ID => $this->ReadPropertyInteger(
                    self::PROP_GRID_IMPORT_POWER_L1_VAR_ID
                ),
                self::PROP_GRID_EXPORT_POWER_L1_VAR_ID => $this->ReadPropertyInteger(
                    self::PROP_GRID_EXPORT_POWER_L1_VAR_ID
                ),
                self::PROP_POWER_L2_VAR_ID             => $this->ReadPropertyInteger(
                    self::PROP_POWER_L2_VAR_ID
                ),
                self::PROP_GRID_IMPORT_POWER_L2_VAR_ID => $this->ReadPropertyInteger(
                    self::PROP_GRID_IMPORT_POWER_L2_VAR_ID
                ),
                self::PROP_GRID_EXPORT_POWER_L2_VAR_ID => $this->ReadPropertyInteger(
                    self::PROP_GRID_EXPORT_POWER_L2_VAR_ID
                ),
                self::PROP_POWER_L3_VAR_ID             => $this->ReadPropertyInteger(
                    self::PROP_POWER_L3_VAR_ID
                ),
                self::PROP_GRID_IMPORT_POWER_L3_VAR_ID => $this->ReadPropertyInteger(
                    self::PROP_GRID_IMPORT_POWER_L3_VAR_ID
                ),
                self::PROP_GRID_EXPORT_POWER_L3_VAR_ID => $this->ReadPropertyInteger(
                    self::PROP_GRID_EXPORT_POWER_L3_VAR_ID
                ),
                self::PROP_VOLTAGE_L1_VAR_ID           => $this->ReadPropertyInteger(
                    self::PROP_VOLTAGE_L1_VAR_ID
                ),
                self::PROP_VOLTAGE_L2_VAR_ID           => $this->ReadPropertyInteger(
                    self::PROP_VOLTAGE_L2_VAR_ID
                ),
                self::PROP_VOLTAGE_L3_VAR_ID           => $this->ReadPropertyInteger(
                    self::PROP_VOLTAGE_L3_VAR_ID
                ),
                self::PROP_CURRENT_L1_VAR_ID           => $this->ReadPropertyInteger(
                    self::PROP_CURRENT_L1_VAR_ID
                ),
                self::PROP_CURRENT_L2_VAR_ID           => $this->ReadPropertyInteger(
                    self::PROP_CURRENT_L2_VAR_ID
                ),
                self::PROP_CURRENT_L3_VAR_ID           => $this->ReadPropertyInteger(
                    self::PROP_CURRENT_L3_VAR_ID
                ),
                self::PROP_IMPORT_ENERGY_VAR_ID        => $this->ReadPropertyInteger(
                    self::PROP_IMPORT_ENERGY_VAR_ID
                ),
                self::PROP_EXPORT_ENERGY_VAR_ID        => $this->ReadPropertyInteger(
                    self::PROP_EXPORT_ENERGY_VAR_ID
                ),
                self::PROP_MAX_MEASUREMENT_AGE         => $this->ReadPropertyInteger(
                    self::PROP_MAX_MEASUREMENT_AGE
                ),
            ],
            [
                self::PROP_PHASE_MODE => $this->ReadPropertyString(self::PROP_PHASE_MODE),
            ],
            [
                self::PROP_ENERGY_SCALE_FACTOR => $this->ReadPropertyFloat(
                    self::PROP_ENERGY_SCALE_FACTOR
                ),
            ]
        );
    }

    private function shouldWarnAboutDirectPhaseMode(): bool
    {
        if ($this->ReadPropertyString(self::PROP_PHASE_MODE) !== 'direct') {
            return false;
        }

        $phasePowerIds = [
            $this->ReadPropertyInteger(self::PROP_POWER_L1_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_POWER_L2_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_POWER_L3_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_IMPORT_POWER_L1_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_EXPORT_POWER_L1_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_IMPORT_POWER_L2_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_EXPORT_POWER_L2_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_IMPORT_POWER_L3_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_EXPORT_POWER_L3_VAR_ID),
        ];

        foreach ($phasePowerIds as $variableId) {
            if ($variableId > 0) {
                return false;
            }
        }

        return true;
    }

    private function registerReferences(): void
    {
        foreach ($this->GetReferenceList() as $referenceId) {
            $this->UnregisterReference($referenceId);
        }

        foreach ($this->getConfiguredReferenceIds() as $referenceId) {
            if ($referenceId > 0) {
                $this->RegisterReference($referenceId);
            }
        }
    }

    /**
     * @return int[]
     */
    private function getConfiguredReferenceIds(): array
    {
        $referenceIds = [
            $this->ReadPropertyInteger(self::PROP_POWER_TOTAL_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_IMPORT_POWER_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_EXPORT_POWER_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_POWER_L1_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_IMPORT_POWER_L1_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_EXPORT_POWER_L1_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_POWER_L2_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_IMPORT_POWER_L2_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_EXPORT_POWER_L2_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_POWER_L3_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_IMPORT_POWER_L3_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_GRID_EXPORT_POWER_L3_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_VOLTAGE_L1_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_VOLTAGE_L2_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_VOLTAGE_L3_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_CURRENT_L1_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_CURRENT_L2_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_CURRENT_L3_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_IMPORT_ENERGY_VAR_ID),
            $this->ReadPropertyInteger(self::PROP_EXPORT_ENERGY_VAR_ID),
        ];

        return array_values(array_unique(array_filter($referenceIds, static fn(int $referenceId): bool => $referenceId > 0)));
    }

    private function handleRpcRequest(string $payload, string $remoteIp, int $remotePort): ?array
    {
        $this->SendDebug(__FUNCTION__, sprintf('%s:%d', $remoteIp, $remotePort), 0);

        $decoded = UdpRpcCodec::decode($payload);
        if ($decoded['ok'] !== true) {
            return $decoded['error'];
        }

        $message = $decoded['message'];

        try {
            $result = $this->handleMethod($message['method'], $message['params']);
        } catch (Throwable $throwable) {
            $this->SendDebug(__FUNCTION__, $throwable->getMessage(), 0);
            return UdpRpcCodec::buildServerError('Internal server error', $message['id']);
        }

        if (isset($result['error']) && is_array($result['error'])) {
            $result['id'] = $message['id'];
            return $result;
        }

        return UdpRpcCodec::buildSuccessResponse($message['id'], $result);
    }

    private function handleMethod(string $method, array $params): array
    {
        $snapshot         = $this->buildSnapshot();
        $stringProperties = $this->getStringProperties();

        return match ($method) {
            'Shelly.GetDeviceInfo' => ShellyResponseBuilder::buildDeviceInfo($stringProperties),
            'Shelly.GetStatus' => $snapshot->isValid ? ShellyResponseBuilder::buildShellyStatus($stringProperties, $snapshot)
                : UdpRpcCodec::buildServerError('No valid measurement snapshot available'),
            'Shelly.ListMethods' => ShellyResponseBuilder::buildMethodList(),
            'Sys.GetStatus' => ShellyResponseBuilder::buildSysStatus($stringProperties),
            'Sys.GetConfig' => ShellyResponseBuilder::buildSysConfig($stringProperties),
            'EM.GetStatus' => $snapshot->isValid
                ? ShellyResponseBuilder::buildEmStatus($snapshot)
                : UdpRpcCodec::buildServerError(
                    'No valid measurement snapshot available'
                ),
            default => UdpRpcCodec::buildMethodNotFoundError($method),
        };
    }

    private function sendUdpReply(array $message, string $remoteIp, int $remotePort): void
    {
        $payload  = UdpRpcCodec::encode($message);
        $envelope = $this->buildUdpResponseEnvelope($payload, $remoteIp, $remotePort);

        $this->SendDebug(__FUNCTION__ . ' (payload)', json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 0);

        $result = @$this->SendDataToParent(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->SendDebug(__FUNCTION__ . '.parentResult', var_export($result, true), 0);
    }

    /**
     * @return array{0:string,1:string,2:int}
     */
    private function extractTransportContext(string $jsonString): array
    {
        $envelope = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($envelope)) {
            return [$jsonString, '0.0.0.0', 0];
        }

        $payload = $jsonString;
        if (isset($envelope['Buffer']) && is_string($envelope['Buffer'])) {
            $payload = $this->decodeParentBuffer($envelope['Buffer']);
        }

        $remoteIp = '0.0.0.0';
        if (isset($envelope['ClientIP']) && is_string($envelope['ClientIP'])) {
            $remoteIp = $envelope['ClientIP'];
        }

        $remotePort = 0;
        if (isset($envelope['ClientPort']) && is_numeric($envelope['ClientPort'])) {
            $remotePort = (int)$envelope['ClientPort'];
        }

        return [$payload, $remoteIp, $remotePort];
    }

    /**
     * Builds the official Symcon UDP TX envelope for SendDataToParent().
     */
    private function buildUdpResponseEnvelope(string $payload, string $remoteIp, int $remotePort): array
    {
        return [
            'DataID'     => self::UDP_DATAFLOW_TX,
            'Buffer'     => strtoupper(bin2hex($payload)),
            'ClientIP'   => $remoteIp,
            'ClientPort' => $remotePort,
            'Broadcast'  => false,
        ];
    }

    private function decodeParentBuffer(string $buffer): string
    {
        if ($buffer !== '' && (strlen($buffer) % 2) === 0 && ctype_xdigit($buffer)) {
            $decoded = hex2bin($buffer);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $buffer;
    }

    /**
     * @return array<string, string>
     */
    private function getStringProperties(): array
    {
        return [
            self::PROP_DEVICE_NAME      => $this->ReadPropertyString(self::PROP_DEVICE_NAME),
            self::PROP_HOSTNAME         => $this->ReadPropertyString(self::PROP_HOSTNAME),
            self::PROP_MAC_ADDRESS      => $this->ReadPropertyString(self::PROP_MAC_ADDRESS),
            self::PROP_FIRMWARE_VERSION => $this->ReadPropertyString(self::PROP_FIRMWARE_VERSION),
            self::PROP_PHASE_MODE       => $this->ReadPropertyString(self::PROP_PHASE_MODE),
        ];
    }
}
