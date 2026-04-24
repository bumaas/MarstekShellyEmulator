<?php

declare(strict_types=1);

/**
 * Encapsulates JSON-RPC decoding and encoding for the Shelly-compatible endpoint.
 */
final class UdpRpcCodec
{
    public static function decode(string $payload): array
    {
        try {
            $message = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [
                'ok' => false,
                'error' => self::buildParseError($exception->getMessage()),
            ];
        }

        if (!is_array($message)) {
            return [
                'ok' => false,
                'error' => self::buildInvalidRequestError('JSON-RPC payload must decode to an object'),
            ];
        }

        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = $message['params'] ?? [];

        if (!is_string($method) || $method === '') {
            return [
                'ok' => false,
                'error' => self::buildInvalidRequestError('Missing or invalid method', $id),
            ];
        }

        if (!is_array($params)) {
            return [
                'ok' => false,
                'error' => self::buildInvalidRequestError('Params must be an object or array', $id),
            ];
        }

        return [
            'ok' => true,
            'message' => [
                'id' => $id,
                'src' => isset($message['src']) && is_string($message['src']) ? $message['src'] : null,
                'method' => $method,
                'params' => $params,
            ],
        ];
    }

    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function buildSuccessResponse(mixed $id, array $result): array
    {
        return [
            'id' => $id,
            'result' => $result,
        ];
    }

    public static function buildParseError(string $message): array
    {
        return [
            'id' => null,
            'error' => [
                'code' => -32700,
                'message' => 'Parse error',
                'data' => $message,
            ],
        ];
    }

    public static function buildInvalidRequestError(string $message, mixed $id = null): array
    {
        return [
            'id' => $id,
            'error' => [
                'code' => -32600,
                'message' => 'Invalid request',
                'data' => $message,
            ],
        ];
    }

    public static function buildMethodNotFoundError(string $method, mixed $id = null): array
    {
        return [
            'id' => $id,
            'error' => [
                'code' => -32601,
                'message' => sprintf('Method not found: %s', $method),
            ],
        ];
    }

    public static function buildServerError(string $message, mixed $id = null): array
    {
        return [
            'id' => $id,
            'error' => [
                'code' => -32000,
                'message' => $message,
            ],
        ];
    }
}
