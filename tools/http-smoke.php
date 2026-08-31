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

/** @return array{status:int,body:string} */
function requestText(string $baseUrl, string $path): array
{
    $options = [
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'header' => 'Accept: text/html',
            'timeout' => 10,
        ],
    ];
    $response = file_get_contents($baseUrl . $path, false, stream_context_create($options));
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }
    if ($response === false || $status === 0) {
        throw new RuntimeException('No HTML response from ' . $path . '.');
    }

    return ['status' => $status, 'body' => $response];
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
    foreach (['evaluate', 'explain', 'equation', 'system', 'matrix', 'calculus', 'plot', 'area', 'root', 'statistics', 'units', 'unit-explain'] as $id) {
        check(in_array($id, $capabilityIds, true), 'capabilities lists ' . $id);
    }

    $pageChecks = [
        '/' => 'Mathematics for PHP',
        '/?page=packages' => 'One core.',
        '/?page=explaining' => 'Teach the calculation.',
        '/?page=visuals' => 'Show the calculation.',
        '/?page=units' => 'Keep the unit attached.',
        '/?page=pricing' => 'Choose the add-on model later.',
        '/?page=docs' => 'Learn the engine.',
        '/?page=playground' => 'Make a calculation.',
        '/?page=units-guide' => '25m to km',
        '/?page=explaining-equations' => '1*x^2 + 0*x + 1 = 5',
        '/?page=explaining-systems' => '2*x + 3*y = 8; 1*x - 1*y = 1',
        '/?page=explaining-calculus' => 'derivative',
        '/?page=explaining-matrices' => 'determinant',
    ];
    foreach ($pageChecks as $path => $marker) {
        $page = requestText($baseUrl, $path);
        check($page['status'] === 200, 'page ' . $path . ' responds with HTTP 200');
        check(str_contains($page['body'], $marker), 'page ' . $path . ' contains its contract marker');
        check(!str_contains($page['body'], 'Fatal error') && !str_contains($page['body'], 'Warning:'), 'page ' . $path . ' has no PHP runtime warning');
    }

    $evaluation = requestJson($baseUrl, '/?api=evaluate', ['expression' => '2 + 2', 'variables' => []]);
    check(($evaluation['body']['ok'] ?? false) === true && ($evaluation['body']['result'] ?? null) === 4, 'core evaluates 2 + 2');

    $error = requestJson($baseUrl, '/?api=evaluate', ['expression' => '10 / 0', 'variables' => []]);
    check(($error['body']['ok'] ?? true) === false && is_string($error['body']['code'] ?? null), 'core returns a structured arithmetic error');

    $optionalChecks = [
        'explain' => ['path' => '/?api=explain', 'payload' => ['expression' => '(5*2)*2', 'variables' => [], 'locale' => 'en'], 'success' => static fn (array $body): bool => ($body['explanation']['result'] ?? null) === 20 && count($body['explanation']['steps'] ?? []) > 0, 'unavailable' => 'explain.unavailable'],
        'equation' => ['path' => '/?api=analyze', 'payload' => ['equation' => '1*x^2 + 0*x + 1 = 5', 'known' => []], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && count($body['analysis']['steps'] ?? []) > 0, 'unavailable' => 'explain.unavailable'],
        'system' => ['path' => '/?api=system', 'payload' => ['system' => '2*x + 3*y = 8; 1*x - 1*y = 1'], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && count($body['analysis']['steps'] ?? []) > 0, 'unavailable' => 'explain.unavailable'],
        'matrix' => ['path' => '/?api=matrix', 'payload' => ['matrix' => [[1, 2], [3, 4]]], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && isset($body['analysis']['result']['determinant']), 'unavailable' => 'explain.unavailable'],
        'calculus' => ['path' => '/?api=calculus', 'payload' => ['operation' => 'derivative', 'expression' => 'x^2 + 3*x', 'variable' => 'x'], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && ($body['analysis']['result'] ?? null) === '2x + 3', 'unavailable' => 'explain.unavailable'],
        'area' => ['path' => '/?api=area', 'payload' => ['expression' => 'x^2', 'variable' => 'x', 'minimum' => 0, 'maximum' => 1, 'samples' => 21], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && is_numeric($body['analysis']['area'] ?? null), 'unavailable' => 'explain.unavailable'],
        'root' => ['path' => '/?api=root', 'payload' => ['expression' => 'x^2 - 2', 'variable' => 'x', 'minimum' => 0, 'maximum' => 2, 'iterations' => 20], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && is_numeric($body['analysis']['root'] ?? null), 'unavailable' => 'explain.unavailable'],
        'statistics' => ['path' => '/?api=statistics', 'payload' => ['values' => [1, 2, 2, 3, 10], 'bins' => 4], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && ($body['analysis']['summary']['count'] ?? null) === 5, 'unavailable' => 'explain.unavailable'],
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

    $unitErrorChecks = [
        'units incompatibility diagnostics' => ['payload' => ['expression' => '2m + 3s', 'variables' => []], 'code' => 'units.incompatible_addition', 'span' => [3, 4]],
        'units conversion diagnostics' => ['payload' => ['expression' => '25m to s', 'variables' => []], 'code' => 'units.incompatible_conversion', 'span' => [7, 8]],
        'units affine scaling diagnostics' => ['payload' => ['expression' => '20C * 2', 'variables' => []], 'code' => 'units.affine_operation', 'span' => [4, 5]],
        'units zero negative power diagnostics' => ['payload' => ['expression' => '0m ^ -1', 'variables' => []], 'code' => 'units.division_by_zero', 'span' => [3, 4]],
    ];
    foreach ($unitErrorChecks as $name => $definition) {
        $response = requestJson($baseUrl, '/?api=units', $definition['payload']);
        if ($requireOptional) {
            check(($response['body']['ok'] ?? true) === false && ($response['body']['code'] ?? null) === $definition['code'] && ($response['body']['span'] ?? null) === $definition['span'], $name . ' report the operator/target span');
        } else {
            check(($response['body']['ok'] ?? true) === false && ($response['body']['code'] ?? null) === 'units.unavailable', $name . ' reports an explicit unavailable state');
        }
    }

    $unitAliasChecks = [
        'units multi-word alias' => ['expression' => '60 metres per second', 'formatted' => '60 mps'],
        'units separator alias' => ['expression' => '1 mile per hour to km/h', 'formatted' => '1.609344 kmh'],
    ];
    foreach ($unitAliasChecks as $name => $definition) {
        $response = requestJson($baseUrl, '/?api=units', ['expression' => $definition['expression'], 'variables' => []]);
        if ($requireOptional) {
            check(($response['body']['ok'] ?? false) === true && ($response['body']['quantity']['formatted'] ?? null) === $definition['formatted'], $name . ' responds with the canonical quantity');
        } else {
            check(($response['body']['ok'] ?? true) === false && ($response['body']['code'] ?? null) === 'units.unavailable', $name . ' reports an explicit unavailable state');
        }
    }

    echo $requireOptional ? "HTTP smoke suite passed (core + optional packages).\n" : "HTTP smoke suite passed (core-only mode).\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'HTTP smoke suite failed: ' . $error->getMessage() . "\n");
    exit(1);
}
