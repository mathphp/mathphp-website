<?php

declare(strict_types=1);

/**
 * Small, dependency-free HTTP contract probe for the website runtime.
 *
 * Run against a local server in CI, or set MATHPHP_SMOKE_BASE_URL to inspect
 * a deployed runtime. Set MATHPHP_SMOKE_REQUIRE_OPTIONAL=1 when the private
 * add-ons are expected to be installed.
 */

$baseUrl = rtrim((string) (getenv('MATHPHP_SMOKE_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
$requireOptional = getenv('MATHPHP_SMOKE_REQUIRE_OPTIONAL') === '1';

/** @return array{status:int,body:array<string,mixed>} */
function requestJson(string $baseUrl, string $path, ?array $payload = null): array
{
    $headers = ['Accept: application/json'];
    $options = [
        'http' => [
            'method' => $payload === null ? 'GET' : 'POST',
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
            'timeout' => 10,
        ],
    ];
    if ($payload !== null) {
        $options['http']['header'] .= "\r\nContent-Type: application/json";
        $options['http']['content'] = json_encode($payload, JSON_THROW_ON_ERROR);
    }

    $response = file_get_contents($baseUrl . $path, false, stream_context_create($options));
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }
    if ($response === false || $status === 0) {
        throw new RuntimeException('No JSON response from ' . $path . '.');
    }

    $body = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('Response from ' . $path . ' is not a JSON object.');
    }

    return ['status' => $status, 'body' => $body];
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "ok - {$message}\n";
}

try {
    $health = requestJson($baseUrl, '/?api=health');
    check($health['status'] === 200, 'health responds with HTTP 200');
    check(($health['body']['ok'] ?? false) === true, 'health reports a ready core');
    check(($health['body']['packages']['core'] ?? false) === true, 'health reports the core package');
    check(isset($health['body']['packageVersions']['core']), 'health includes core version metadata');

    $version = requestJson($baseUrl, '/?api=version');
    check($version['status'] === 200 && ($version['body']['ok'] ?? false) === true, 'version endpoint is ready');
    check(is_string($version['body']['version'] ?? null), 'version endpoint includes the API version');
    check(array_key_exists('reference', $version['body']['packages']['core'] ?? []), 'version endpoint includes core revision metadata');

    $capabilities = requestJson($baseUrl, '/?api=capabilities');
    $capabilityIds = array_column($capabilities['body']['capabilities'] ?? [], 'id');
    foreach (['evaluate', 'units', 'unit-explain', 'plot'] as $id) {
        check(in_array($id, $capabilityIds, true), 'capabilities lists ' . $id);
    }

    $evaluation = requestJson($baseUrl, '/?api=evaluate', ['expression' => '2 + 2', 'variables' => []]);
    check(($evaluation['body']['ok'] ?? false) === true && ($evaluation['body']['result'] ?? null) === 4, 'core evaluates 2 + 2');

    $error = requestJson($baseUrl, '/?api=evaluate', ['expression' => '10 / 0', 'variables' => []]);
    check(($error['body']['ok'] ?? true) === false && is_string($error['body']['code'] ?? null), 'core returns a structured arithmetic error');

    $optionalChecks = [
        'units' => ['path' => '/?api=units', 'payload' => ['expression' => '2m * 6 + 200cm', 'variables' => []], 'success' => static fn (array $body): bool => ($body['quantity']['formatted'] ?? null) === '14 m', 'unavailable' => 'units.unavailable'],
        'unit-explain' => ['path' => '/?api=unit-explain', 'payload' => ['expression' => '25m to km', 'variables' => [], 'locale' => 'en'], 'success' => static fn (array $body): bool => ($body['unitExplanation']['result']['formatted'] ?? null) === '0.025 km', 'unavailable' => 'explain.units_unavailable'],
        'visuals' => ['path' => '/?api=plot', 'payload' => ['expression' => 'sin(x)', 'variable' => 'x', 'minimum' => 0, 'maximum' => 6.28, 'samples' => 9, 'variables' => []], 'success' => static fn (array $body): bool => ($body['visual']['kind'] ?? null) === 'line-plot', 'unavailable' => 'visuals.unavailable'],
    ];
    foreach ($optionalChecks as $name => $definition) {
        $response = requestJson($baseUrl, $definition['path'], $definition['payload']);
        if ($requireOptional) {
            check(($response['body']['ok'] ?? false) === true && $definition['success']($response['body']), $name . ' add-on responds successfully');
        } else {
            check(($response['body']['ok'] ?? true) === false && ($response['body']['code'] ?? null) === $definition['unavailable'], $name . ' reports an explicit unavailable state');
        }
    }

    echo $requireOptional ? "HTTP smoke suite passed (core + optional packages).\n" : "HTTP smoke suite passed (core-only mode).\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'HTTP smoke suite failed: ' . $error->getMessage() . "\n");
    exit(1);
}
