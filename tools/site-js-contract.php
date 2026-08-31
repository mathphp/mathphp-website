<?php

declare(strict_types=1);

/**
 * Small regression guard for the browser result-rendering boundary.
 *
 * The website deliberately has no JavaScript dependency tree. This check
 * keeps the security-sensitive escaping and SVG sanitization calls present
 * until a browser test harness is introduced.
 */
$source = file_get_contents(__DIR__ . '/../public/assets/site.js');
if ($source === false) {
    fwrite(STDERR, "Could not read public/assets/site.js.\n");
    exit(1);
}

$checks = [
    'evaluate display is escaped' => str_contains($source, 'escapeHtml(data.display)'),
    'evaluate type is escaped' => str_contains($source, 'escapeHtml(data.type)'),
    'evaluate error code is escaped' => str_contains($source, 'escapeHtml(data.code ||'),
    'evaluate error message is escaped' => str_contains($source, 'escapeHtml(data.message ||'),
    'evaluate source span is escaped' => str_contains($source, 'escapeHtml(span)'),
    'visual details sanitize SVG' => str_contains($source, 'sanitizeSvg(visual.svg)'),
    'plot output sanitizes SVG' => substr_count($source, 'sanitizeSvg(visual.svg)') >= 2,
    'SVG sanitizer removes scripts' => str_contains($source, "'script', 'foreignobject'"),
    'unsafe display interpolation is absent' => !str_contains($source, '${data.display}'),
    'unsafe error interpolation is absent' => !str_contains($source, '${data.message}'),
    'auto engine detection is exposed' => str_contains($source, 'const looksLikeUnits') && str_contains($source, 'engineHint.textContent'),
    'optional controls fail closed during discovery' => str_contains($source, 'let capabilitiesReady = false') && str_contains($source, "'Checking installed add-ons…'"),
    'capabilities response status is checked' => str_contains($source, 'if (!response.ok) throw new Error'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Failed browser rendering contract: {$label}.\n");
        exit(1);
    }
    echo "ok - {$label}\n";
}
