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
    foreach ($version['body']['packages'] ?? [] as $name => $metadata) {
        if (!is_array($metadata) || ($metadata['version'] ?? null) === null) {
            continue;
        }
        check(is_string($metadata['reference'] ?? null) && preg_match('/^[0-9a-f]{7,64}$/i', $metadata['reference']) === 1, 'version endpoint reports a valid revision for ' . $name);
    }

    $capabilities = requestJson($baseUrl, '/?api=capabilities');
    $capabilityIds = array_column($capabilities['body']['capabilities'] ?? [], 'id');
    foreach (['evaluate', 'complex-evaluate', 'complex-equation', 'explain', 'piecewise', 'piecewise-equation', 'equation', 'inequality', 'system', 'matrix', 'calculus', 'plot', 'area', 'root', 'statistics', 'units', 'unit-explain', 'numerical-second-order-ode', 'recurrence', 'limit'] as $id) {
        check(in_array($id, $capabilityIds, true), 'capabilities lists ' . $id);
    }
    $capabilityAvailability = [];
    foreach ($capabilities['body']['capabilities'] ?? [] as $capability) {
        if (is_array($capability) && is_string($capability['id'] ?? null)) {
            $capabilityAvailability[$capability['id']] = $capability['available'] ?? null;
            check(is_bool($capability['available'] ?? null), 'capabilities reports availability for ' . $capability['id']);
            check(is_array($capability['requiredPackages'] ?? null) && count($capability['requiredPackages']) > 0, 'capabilities reports package requirements for ' . $capability['id']);
        }
    }
    check(($capabilityAvailability['evaluate'] ?? false) === true, 'core evaluate capability is available');
    foreach (['complex-evaluate', 'complex-equation', 'explain', 'piecewise', 'piecewise-equation', 'equation', 'inequality', 'system', 'matrix', 'calculus', 'area', 'root', 'statistics', 'units', 'unit-explain', 'plot', 'numerical-second-order-ode', 'recurrence', 'limit'] as $id) {
        check(($capabilityAvailability[$id] ?? true) === $requireOptional, 'optional capability availability matches runtime for ' . $id);
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
        '/?page=explaining-systems' => '2x + 3y = 8; x - y = 1',
        '/?page=explaining-calculus' => 'derivative',
        '/?page=explaining-matrices' => 'determinant',
    ];
    foreach ($pageChecks as $path => $marker) {
        $page = requestText($baseUrl, $path);
        check($page['status'] === 200, 'page ' . $path . ' responds with HTTP 200');
        check(str_contains($page['body'], $marker), 'page ' . $path . ' contains its contract marker');
        check(!str_contains($page['body'], 'Fatal error') && !str_contains($page['body'], 'Warning:'), 'page ' . $path . ' has no PHP runtime warning');
    }

    foreach (['explaining', 'visuals', 'units'] as $packagePage) {
        $page = requestText($baseUrl, '/?page=' . $packagePage);
        check(str_contains($page['body'], 'Install from an approved source') && str_contains($page['body'], 'composer config repositories.'), 'package page ' . $packagePage . ' includes private installation guidance');
    }

    $evaluation = requestJson($baseUrl, '/?api=evaluate', ['expression' => '2 + 2', 'variables' => []]);
    check(($evaluation['body']['ok'] ?? false) === true && ($evaluation['body']['result'] ?? null) === 4, 'core evaluates 2 + 2');

    $notation = requestJson($baseUrl, '/?api=evaluate', ['expression' => '2² + √9', 'variables' => []]);
    check(($notation['body']['ok'] ?? false) === true && abs((float) ($notation['body']['result'] ?? NAN) - 7.0) < 1e-10, 'core evaluates superscript and radical notation');

    $extendedFunctions = requestJson($baseUrl, '/?api=evaluate', ['expression' => 'cbrt(-8) + sec(0) + csc(π/2) + cot(π/4)', 'variables' => []]);
    check(($extendedFunctions['body']['ok'] ?? false) === true && abs((float) ($extendedFunctions['body']['result'] ?? NAN) - 1.0) < 1e-10, 'core evaluates cube root and reciprocal trigonometric functions');

    $error = requestJson($baseUrl, '/?api=evaluate', ['expression' => '10 / 0', 'variables' => []]);
    check(($error['body']['ok'] ?? true) === false && is_string($error['body']['code'] ?? null), 'core returns a structured arithmetic error');

    $optionalChecks = [
        'explain' => ['path' => '/?api=explain', 'payload' => ['expression' => '(5*2)*2', 'variables' => [], 'locale' => 'en'], 'success' => static fn (array $body): bool => ($body['explanation']['result'] ?? null) === 20 && count($body['explanation']['steps'] ?? []) > 0, 'unavailable' => 'explain.unavailable'],
        'piecewise' => ['path' => '/?api=piecewise', 'payload' => ['expression' => 'piecewise(x < 0: -x; otherwise: x)', 'variables' => ['x' => -3]], 'success' => static fn (array $body): bool => ($body['result']['value'] ?? null) === 3 && ($body['result']['branch'] ?? null) === 1 && count($body['result']['steps'] ?? []) > 0, 'unavailable' => 'explain.unavailable'],
        'piecewise-equation' => ['path' => '/?api=piecewise-equation', 'payload' => ['equation' => 'piecewise(x < 0: -x; otherwise: x) = 3', 'variable' => 'x', 'minimum' => -5, 'maximum' => 5, 'samples' => 128], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'partial' && count($body['analysis']['solutions']['roots'] ?? []) === 2 && ($body['analysis']['solutions']['complete'] ?? true) === false, 'unavailable' => 'explain.unavailable'],
        'numerical-second-order-ode' => ['path' => '/?api=ode-second-numeric', 'payload' => ['equation' => "y'' = -y", 'dependent' => 'y', 'independent' => 'x', 'initialIndependent' => 0, 'initialValue' => 1, 'initialDerivative' => 0, 'targetIndependent' => 1.5707963267948966, 'steps' => 100], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && abs((float) ($body['analysis']['solution']['final']['y'] ?? NAN)) < 1e-7, 'unavailable' => 'explain.unavailable'],
        'recurrence' => ['path' => '/?api=recurrence', 'payload' => ['recurrence' => 'a(n+1) = 2*a(n) + 1', 'initial' => ['0' => 1], 'terms' => 5], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && ($body['analysis']['sequence']['4'] ?? null) === 31, 'unavailable' => 'explain.unavailable'],
        'limit' => ['path' => '/?api=limit', 'payload' => ['expression' => 'sin(x) / x', 'variable' => 'x', 'point' => 0, 'direction' => 'both', 'samples' => 14], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && abs((float) ($body['analysis']['limit'] ?? NAN) - 1.0) < 1e-7 && ($body['analysis']['solution']['complete'] ?? true) === false, 'unavailable' => 'explain.unavailable'],
        'complex-evaluate' => ['path' => '/?api=complex-evaluate', 'payload' => ['expression' => 'sqrt(-1) + 2*i', 'variables' => []], 'success' => static fn (array $body): bool => abs((float) ($body['result']['real'] ?? NAN)) < 1e-10 && abs((float) ($body['result']['imaginary'] ?? NAN) - 3.0) < 1e-10, 'unavailable' => 'explain.unavailable'],
        'complex-elementary-expanded' => ['path' => '/?api=complex-evaluate', 'payload' => ['expression' => 'tan(i) + log10(100*i) + sec(0) + asin(0.5) + asinh(i)', 'variables' => []], 'success' => static fn (array $body): bool => abs((float) ($body['result']['real'] ?? NAN) - (3.0 + asin(0.5))) < 1e-10 && abs((float) ($body['result']['imaginary'] ?? NAN) - (tanh(1.0) + M_PI / (2 * log(10.0)) + M_PI / 2.0)) < 1e-10, 'unavailable' => 'explain.unavailable'],
        'complex-equation' => ['path' => '/?api=complex-equation', 'payload' => ['equation' => 'z^2 + 1 = 0', 'variable' => 'z', 'initial' => ['real' => 0.2, 'imaginary' => 0.9]], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && abs((float) ($body['analysis']['solution']['root']['real'] ?? NAN)) < 1e-6, 'unavailable' => 'explain.unavailable'],
        'equation' => ['path' => '/?api=analyze', 'payload' => ['equation' => '1*x^2 + 0*x + 1 = 5', 'known' => []], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && count($body['analysis']['steps'] ?? []) > 0, 'unavailable' => 'explain.unavailable'],
        'implicit-equation' => ['path' => '/?api=analyze', 'payload' => ['equation' => '2x + 1 = 9', 'known' => []], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && is_array($body['analysis']['solutions']['roots'] ?? null) && count($body['analysis']['solutions']['roots']) === 1 && (float) $body['analysis']['solutions']['roots'][0] === 4.0, 'unavailable' => 'explain.unavailable'],
        'elementary-equation' => ['path' => '/?api=analyze', 'payload' => ['equation' => '2^(x + 1) = 8', 'known' => []], 'success' => static fn (array $body): bool => ($body['analysis']['solutions']['method'] ?? null) === 'exact-elementary-inverse' && ($body['analysis']['solutions']['roots'] ?? null) === [2], 'unavailable' => 'explain.unavailable'],
        'periodic-equation' => ['path' => '/?api=analyze', 'payload' => ['equation' => 'sin(x) = 0', 'known' => []], 'success' => static fn (array $body): bool => ($body['analysis']['solutions']['method'] ?? null) === 'exact-elementary-inverse' && ($body['analysis']['solutions']['complete'] ?? false) === true && count($body['analysis']['solutions']['families'] ?? []) > 0, 'unavailable' => 'explain.unavailable'],
        'rational-equation' => ['path' => '/?api=analyze', 'payload' => ['equation' => '1 / x = 2', 'known' => []], 'success' => static fn (array $body): bool => ($body['analysis']['solutions']['method'] ?? null) === 'exact-rational-polynomial' && ($body['analysis']['solutions']['roots'] ?? null) === [0.5] && ($body['analysis']['solutions']['excludedValues'] ?? null) === [0], 'unavailable' => 'explain.unavailable'],
        'inequality' => ['path' => '/?api=inequality', 'payload' => ['inequality' => 'x^2 > 0', 'variable' => 'x', 'minimum' => -2, 'maximum' => 2], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && ($body['analysis']['solutions']['complete'] ?? false) === true && ($body['analysis']['solutions']['method'] ?? null) === 'exact-polynomial-sign-chart', 'unavailable' => 'explain.unavailable'],
        'chained-inequality' => ['path' => '/?api=inequality', 'payload' => ['inequality' => '1 < x ≤ 3', 'variable' => 'x', 'minimum' => -5, 'maximum' => 5], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && ($body['analysis']['solutions']['method'] ?? null) === 'chained-intersection' && ($body['analysis']['solutions']['complete'] ?? false) === true, 'unavailable' => 'explain.unavailable'],
        'rational-inequality' => ['path' => '/?api=inequality', 'payload' => ['inequality' => '1 / x > 0', 'variable' => 'x', 'minimum' => -1, 'maximum' => 1], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && ($body['analysis']['solutions']['complete'] ?? false) === true && ($body['analysis']['solutions']['method'] ?? null) === 'exact-rational-sign-chart' && ($body['analysis']['solutions']['excludedValues'] ?? null) === [0], 'unavailable' => 'explain.unavailable'],
        'unicode-inequality' => ['path' => '/?api=inequality', 'payload' => ['inequality' => 'x^2 ≤ 4', 'variable' => 'x', 'minimum' => -3, 'maximum' => 3], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && ($body['analysis']['solutions']['method'] ?? null) === 'exact-polynomial-sign-chart' && count($body['analysis']['solutions']['intervals'] ?? []) === 1, 'unavailable' => 'explain.unavailable'],
        'system' => ['path' => '/?api=system', 'payload' => ['system' => '2x + 3y = 8; x - y = 1'], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && count($body['analysis']['steps'] ?? []) > 0, 'unavailable' => 'explain.unavailable'],
        'matrix' => ['path' => '/?api=matrix', 'payload' => ['matrix' => [[1, 2], [3, 4]]], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && isset($body['analysis']['result']['determinant']), 'unavailable' => 'explain.unavailable'],
        'calculus' => ['path' => '/?api=calculus', 'payload' => ['operation' => 'derivative', 'expression' => 'x^2 + 3*x', 'variable' => 'x'], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && ($body['analysis']['result'] ?? null) === '2x + 3', 'unavailable' => 'explain.unavailable'],
        'calculus-expanded' => ['path' => '/?api=calculus', 'payload' => ['operation' => 'derivative', 'expression' => 'tan(x) + asinh(x) + log10(x)', 'variable' => 'x'], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && str_contains((string) ($body['analysis']['result'] ?? ''), 'cos(x)^2') && str_contains((string) ($body['analysis']['result'] ?? ''), 'ln(10)'), 'unavailable' => 'explain.unavailable'],
        'calculus-integral-expanded' => ['path' => '/?api=calculus', 'payload' => ['operation' => 'integral', 'expression' => 'tanh(x) + log10(x)', 'variable' => 'x'], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && str_contains((string) ($body['analysis']['result'] ?? ''), 'ln(cosh(x))') && str_contains((string) ($body['analysis']['result'] ?? ''), 'log10(x)'), 'unavailable' => 'explain.unavailable'],
        'area' => ['path' => '/?api=area', 'payload' => ['expression' => 'x^2', 'variable' => 'x', 'minimum' => 0, 'maximum' => 1, 'samples' => 21], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && is_numeric($body['analysis']['area'] ?? null), 'unavailable' => 'explain.unavailable'],
        'root' => ['path' => '/?api=root', 'payload' => ['expression' => 'x^2 - 2', 'variable' => 'x', 'minimum' => 0, 'maximum' => 2, 'iterations' => 20], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && is_numeric($body['analysis']['root'] ?? null), 'unavailable' => 'explain.unavailable'],
        'repeated-root' => ['path' => '/?api=solve-equation', 'payload' => ['equation' => '(x - 1)^2 = 0', 'variable' => 'x', 'minimum' => 0, 'maximum' => 2, 'samples' => 32, 'iterations' => 80], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && ($body['analysis']['solutions']['method'] ?? null) === 'sampled-bisection-newton' && count($body['analysis']['solutions']['roots'] ?? []) === 1, 'unavailable' => 'explain.unavailable'],
        'statistics' => ['path' => '/?api=statistics', 'payload' => ['values' => [1, 2, 2, 3, 10], 'bins' => 4], 'success' => static fn (array $body): bool => ($body['analysis']['status'] ?? null) === 'solved' && ($body['analysis']['summary']['count'] ?? null) === 5, 'unavailable' => 'explain.unavailable'],
        'units' => ['path' => '/?api=units', 'payload' => ['expression' => '2m * 6 + 200cm', 'variables' => []], 'success' => static fn (array $body): bool => ($body['quantity']['formatted'] ?? null) === '14 m' && (float) ($body['quantity']['displayValue'] ?? NAN) === 14.0, 'unavailable' => 'units.unavailable'],
        'units-unicode' => ['path' => '/?api=units', 'payload' => ['expression' => '2m × 6 + 200cm', 'variables' => []], 'success' => static fn (array $body): bool => ($body['quantity']['formatted'] ?? null) === '14 m', 'unavailable' => 'units.unavailable'],
        'units signed temperature' => ['path' => '/?api=units', 'payload' => ['expression' => '-20C to F', 'variables' => []], 'success' => static fn (array $body): bool => ($body['quantity']['formatted'] ?? null) === '-4 F', 'unavailable' => 'units.unavailable'],
        'unit-explain' => ['path' => '/?api=unit-explain', 'payload' => ['expression' => '25m to km', 'variables' => [], 'locale' => 'en'], 'success' => static fn (array $body): bool => ($body['unitExplanation']['result']['formatted'] ?? null) === '0.025 km' && abs((float) ($body['unitExplanation']['result']['displayValue'] ?? NAN) - 0.025) < 0.0000001, 'unavailable' => 'explain.units_unavailable'],
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

    $rootHole = requestJson($baseUrl, '/?api=root', ['expression' => '1/x', 'variable' => 'x', 'minimum' => -1, 'maximum' => 1, 'iterations' => 20]);
    if ($requireOptional) {
        check(($rootHole['body']['ok'] ?? true) === true && ($rootHole['body']['analysis']['status'] ?? null) === 'partial' && ($rootHole['body']['analysis']['root'] ?? null) === null && ($rootHole['body']['analysis']['iterations'] ?? null) === [], 'root analyzer reports undefined interior samples as partial');
    } else {
        check(($rootHole['body']['ok'] ?? true) === false && ($rootHole['body']['code'] ?? null) === 'explain.unavailable', 'root hole check reports an explicit unavailable state');
    }

    $areaHole = requestJson($baseUrl, '/?api=area', ['expression' => '1/x', 'variable' => 'x', 'minimum' => -1, 'maximum' => 1, 'samples' => 11]);
    if ($requireOptional) {
        $areaAnalysis = is_array($areaHole['body']['analysis'] ?? null) ? $areaHole['body']['analysis'] : [];
        $areaPoints = is_array($areaAnalysis['visual']['data']['points'] ?? null) ? $areaAnalysis['visual']['data']['points'] : [];
        $hasUndefinedPoint = is_array($areaPoints) && array_filter($areaPoints, static fn (mixed $point): bool => is_array($point) && array_key_exists('y', $point) && $point['y'] === null) !== [];
        check(($areaHole['body']['ok'] ?? true) === true && ($areaAnalysis['status'] ?? null) === 'partial' && array_key_exists('area', $areaAnalysis) && $areaAnalysis['area'] === null && $hasUndefinedPoint, 'area analyzer omits biased values for undefined samples');
    } else {
        check(($areaHole['body']['ok'] ?? true) === false && ($areaHole['body']['code'] ?? null) === 'explain.unavailable', 'area hole check reports an explicit unavailable state');
    }

    $unitErrorChecks = [
        'units incompatibility diagnostics' => ['payload' => ['expression' => '2m + 3s', 'variables' => []], 'code' => 'units.incompatible_addition', 'span' => [3, 4]],
        'units conversion diagnostics' => ['payload' => ['expression' => '25m to s', 'variables' => []], 'code' => 'units.incompatible_conversion', 'span' => [7, 8]],
        'units affine scaling diagnostics' => ['payload' => ['expression' => '20C * 2', 'variables' => []], 'code' => 'units.affine_operation', 'span' => [4, 5]],
        'units zero negative power diagnostics' => ['payload' => ['expression' => '0m ^ -1', 'variables' => []], 'code' => 'units.division_by_zero', 'span' => [3, 4]],
        'units non-finite literal diagnostics' => ['payload' => ['expression' => '1e309', 'variables' => []], 'code' => 'units.non_finite_number', 'span' => [0, 1]],
        'units non-finite result diagnostics' => ['payload' => ['expression' => '1e308 * 10', 'variables' => []], 'code' => 'units.non_finite_result', 'span' => [6, 7]],
        'units conversion keyword diagnostics' => ['payload' => ['expression' => '1 to m', 'variables' => []], 'code' => 'units.incompatible_conversion', 'span' => [5, 6]],
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
