<?php

declare(strict_types=1);

// Deployment marker: private visuals package integration (2026-08-27).

require dirname(__DIR__) . '/vendor/autoload.php';

$unitsAutoload = dirname(__DIR__) . '/private/mathphp-units/vendor/autoload.php';
if (is_file($unitsAutoload)) {
    require_once $unitsAutoload;
}
$explainingAutoload = dirname(__DIR__) . '/private/mathphp-explaining/vendor/autoload.php';
if (is_file($explainingAutoload)) {
    require_once $explainingAutoload;
}
$visualsAutoload = dirname(__DIR__) . '/private/mathphp-visuals/vendor/autoload.php';
if (is_file($visualsAutoload)) {
    // Composer prepends each autoloader; register standalone Visuals last so
    // its checked-out source cannot be shadowed by Explaining's locked copy.
    require_once $visualsAutoload;
}

use MathPHP\Math;
use MathPHP\Exception\MathException;

const WEBSITE_API_VERSION = '0.1';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatResult(int|float $result): string
{
    if (is_int($result)) {
        return (string) $result;
    }

    $formatted = sprintf('%.14g', $result);

    return str_contains($formatted, '.')
        ? rtrim(rtrim($formatted, '0'), '.')
        : $formatted;
}

/**
 * Return the resolved version and source revision for one runtime package.
 * Lockfiles are preferred because optional packages have independent vendor
 * trees. Optional package roots also write a checkout marker during Docker
 * startup so this reports the source that is actually running, not merely a
 * dependency revision mentioned by another package's lockfile.
 *
 * @return array{version:string|null,reference:string|null}
 */
function runtimePackageMetadata(string $package, string $lockPath, bool $available, ?string $sourceDir = null): array
{
    if (!$available) {
        return ['version' => null, 'reference' => null];
    }

    $sourceReference = null;
    if ($sourceDir !== null) {
        $markerPath = $sourceDir . '/.mathphp-revision';
        if (is_file($markerPath)) {
            $marker = file_get_contents($markerPath);
            if ($marker !== false && preg_match('/^[0-9a-f]{7,64}$/i', trim($marker)) === 1) {
                $sourceReference = trim($marker);
            }
        }
    }

    if (is_file($lockPath)) {
        $contents = file_get_contents($lockPath);
        if ($contents !== false) {
            try {
                $lock = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                foreach (['packages', 'packages-dev'] as $section) {
                    foreach (($lock[$section] ?? []) as $installed) {
                        if (!is_array($installed) || ($installed['name'] ?? null) !== $package) {
                            continue;
                        }

                        $source = is_array($installed['source'] ?? null) ? $installed['source'] : [];
                        $dist = is_array($installed['dist'] ?? null) ? $installed['dist'] : [];
                        $reference = $source['reference'] ?? $dist['reference'] ?? null;

                        return [
                            'version' => is_string($installed['version'] ?? null) ? $installed['version'] : null,
                            'reference' => $sourceReference ?? (is_string($reference) ? $reference : null),
                        ];
                    }
                }
            } catch (JsonException) {
                // Fall through to Composer's installed package metadata.
            }
        }
    }

    if (class_exists('Composer\\InstalledVersions')) {
        try {
            if (\Composer\InstalledVersions::isInstalled($package)) {
                return [
                    'version' => \Composer\InstalledVersions::getPrettyVersion($package),
                    'reference' => $sourceReference ?? \Composer\InstalledVersions::getReference($package),
                ];
            }
        } catch (Throwable) {
            // A partial optional install should not make health checks fail.
        }
    }

    return ['version' => null, 'reference' => null];
}

/**
 * @return array{core:bool,optional:array{units:bool,explaining:bool,visuals:bool},versions:array{core:array{version:string|null,reference:string|null},units:array{version:string|null,reference:string|null},explaining:array{version:string|null,reference:string|null},visuals:array{version:string|null,reference:string|null}}}
 */
function runtimePackageState(): array
{
    $root = dirname(__DIR__);
    $core = class_exists('MathPHP\\Math');
    $optional = [
        'units' => class_exists('MathPHP\\Units\\UnitMath'),
        'explaining' => class_exists('MathPHP\\Explaining\\Explainer'),
        'visuals' => class_exists('MathPHP\\Visuals\\Plotter'),
    ];

    return [
        'core' => $core,
        'optional' => $optional,
        'versions' => [
            'core' => runtimePackageMetadata('mathphp/mathphp', $root . '/composer.lock', $core),
            'units' => runtimePackageMetadata('mathphp/mathphp-units', $root . '/private/mathphp-units/composer.lock', $optional['units'], $root . '/private/mathphp-units'),
            'explaining' => runtimePackageMetadata('mathphp/mathphp-explaining', $root . '/private/mathphp-explaining/composer.lock', $optional['explaining'], $root . '/private/mathphp-explaining'),
            'visuals' => runtimePackageMetadata('mathphp/mathphp-visuals', $root . '/private/mathphp-visuals/composer.lock', $optional['visuals'], $root . '/private/mathphp-visuals'),
        ],
    ];
}

function renderLayout(string $title, string $content, string $active): string
{
    $cssHash = is_file(__DIR__ . '/assets/site.css') ? substr((string) hash_file('sha256', __DIR__ . '/assets/site.css'), 0, 12) : 'dev';
    $jsHash = is_file(__DIR__ . '/assets/site.js') ? substr((string) hash_file('sha256', __DIR__ . '/assets/site.js'), 0, 12) : 'dev';
    $groups = [
        'Explore' => ['packages' => ['Packages', 'Core and private add-ons'], 'docs' => ['Documentation', 'Guides and API contracts'], 'units' => ['Units', 'Dimensions and conversions']],
        'Build' => ['playground' => ['Playground', 'Evaluate, explain, and visualize'], 'visuals' => ['Visuals', 'Graphs, plots, and SVG output']],
        'Support' => ['pricing' => ['Distribution planning', 'License and access options are not connected']],
    ];
    $links = '<a class="nav-home' . ($active === 'home' ? ' active' : '') . '" href="?page=home"' . ($active === 'home' ? ' aria-current="page"' : '') . '>Overview</a>';
    foreach ($groups as $label => $items) {
        $isGroupActive = in_array($active, array_keys($items), true);
        $links .= '<details class="nav-menu"><summary' . ($isGroupActive ? ' class="active"' : '') . '>' . e($label) . '<span class="nav-chevron" aria-hidden="true">⌄</span></summary><div class="nav-popover">';
        foreach ($items as $key => [$itemLabel, $itemDescription]) {
            $itemActive = $active === $key;
            $links .= '<a class="nav-item' . ($itemActive ? ' active' : '') . '" href="?page=' . $key . '"' . ($itemActive ? ' aria-current="page"' : '') . '><span>' . e($itemLabel) . '</span><small>' . e($itemDescription) . '</small></a>';
        }
        $links .= '</div></details>';
    }

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="description" content="MathPHP: a bounded scalar expression evaluator for PHP.">'
        . '<title>' . e($title) . ' · MathPHP</title>'
        . '<link rel="stylesheet" href="assets/site.css?v=' . $cssHash . '"></head><body>'
        . '<header class="site-header"><a class="brand" href="?page=home"><span class="brand-mark" aria-hidden="true">M</span><span>MathPHP</span><small class="brand-badge">PHP math library</small></a>'
        . '<button class="mobile-menu-toggle" type="button" aria-expanded="false" aria-controls="primary-menu"><span class="menu-icon" aria-hidden="true"><i></i><i></i><i></i></span><span>Menu</span></button>'
        . '<nav id="primary-menu" aria-label="Primary navigation">' . $links . '</nav><button class="menu-backdrop" type="button" aria-label="Close navigation"></button></header>'
        . '<main>' . $content . '</main>'
        . '<footer class="site-footer"><div class="footer-brand"><span class="brand-mark" aria-hidden="true">M</span><div><strong>MathPHP</strong><small>Deterministic mathematics for PHP.</small></div></div><div class="footer-links"><div><b>Build</b><a href="?page=getting-started">Installation</a><a href="?page=docs">Guides</a><a href="?page=api">API reference</a></div><div><b>Explore</b><a href="?page=playground">Playground</a><a href="?page=visuals">Visuals</a><a href="?page=packages">Packages</a></div><div><b>Community</b><a href="https://github.com/mathphp/mathphp">GitHub</a><a href="https://github.com/mathphp/mathphp/issues">Issues</a><a href="?page=pricing">Distribution notes</a></div></div><a class="footer-sponsor" href="?page=pricing"><span>✦</span><strong>Product planning</strong><small>Review add-on licensing and distribution options →</small></a><div class="footer-bottom"><span>© 2026 MathPHP</span><span>Open core · explicit contracts</span></div></footer>'
        . '<script src="assets/site.js?v=' . $jsHash . '" defer></script></body></html>';
}

function renderHome(): string
{
    return '<section class="hero wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Open-source PHP math library</div>'
        . '<div class="hero-grid"><div><h1>Mathematics for PHP,<br><em>made predictable.</em></h1><p class="hero-copy">Evaluate readable expressions with explicit grammar, stable errors, and configurable limits. Add private explanation, visual, or unit layers only when your product needs them.</p>'
        . '<div class="hero-actions"><a class="button button-primary" href="?page=playground">Open evaluator <span>↗</span></a><a class="text-link" href="?page=docs">Read the docs <span>→</span></a></div>'
        . '<div class="hero-note"><span class="note-line"></span><span>PHP 8.2+ · no runtime dependencies · explicit errors</span></div></div>'
        . '<div class="console-card"><div class="console-top"><span class="console-label">mathphp / playground</span><span class="console-status"><i></i> ready</span></div><div class="console-body"><div><span class="prompt">&gt;</span><span> (subtotal + tax) * 1.05</span></div><div class="console-muted">subtotal = 42.50 &nbsp; tax = 0.20</div><div class="console-result"><span class="result-arrow">→</span><strong>53.55</strong><span class="result-type">float</span></div></div><div class="console-foot"><span>deterministic</span><span>bounded</span><span>typed</span></div></div></div></section>'
        . '<section class="signal-band"><div class="wrap signal-grid"><div><strong>Small surface area.</strong><span>Clear contracts at every edge.</span></div><div><strong>Safe by default.</strong><span>No eval(), no hidden state.</span></div><div><strong>Useful immediately.</strong><span>Operators, functions, variables.</span></div></div></section>'
        . '<section class="feature-section wrap"><div class="section-kicker">Why MathPHP</div><div class="feature-grid"><article><div class="feature-number">01</div><h2>Expressions that stay readable.</h2><p>Write the calculation your users already understand. Variables, familiar operators, and a focused set of math functions.</p><a href="?page=docs#grammar">See the grammar <span>→</span></a></article><article><div class="feature-number">02</div><h2>Errors that tell the truth.</h2><p>Every failure has a stable code and source span, so validation messages can be useful instead of mysterious.</p><a href="?page=docs#errors">Explore error codes <span>→</span></a></article><article><div class="feature-number">03</div><h2>Boundaries you can trust.</h2><p>Overflow, non-finite values, malformed input, and resource limits are handled explicitly and consistently.</p><a href="?page=docs#limits">View the limits <span>→</span></a></article></div></section>'
        . '<section class="extensions-section wrap" id="extensions"><div class="section-kicker">Optional extensions</div><div class="extensions-heading"><div><h2>Keep the engine free.<br><em>Add the understanding.</em></h2></div><p>MathPHP stays a small, open core. Private add-ons plug into the same contracts when your product needs to teach a calculation, present it beautifully, or keep measurements honest.</p></div><div class="extension-grid"><article class="extension-card extension-card-warm"><div class="extension-top"><span class="extension-index">01</span><span class="extension-badge">Private package</span></div><h3>mathphp-explaining</h3><p>Turn an expression into a clear, ordered lesson: substitutions, operation rules, partial results, source spans, and translated messages.</p><ul><li>Step-by-step evaluation</li><li>English and Danish catalogs</li><li>Observer-based, deterministic output</li></ul><a class="button button-secondary" href="?page=explaining">Explore package <span>→</span></a></article><article class="extension-card extension-card-cool"><div class="extension-top"><span class="extension-index">02</span><span class="extension-badge">Private package</span></div><h3>mathphp-visuals</h3><p>Turn formulas and analysis into renderer-neutral data with accessible SVG fallbacks for charts, graphs, matrices, and calculus.</p><ul><li>Plots, areas, roots, and histograms</li><li>SVG and image-ready data URIs</li><li>Bring your own frontend renderer</li></ul><a class="button button-secondary" href="?page=visuals">Explore package <span>→</span></a></article><article class="extension-card extension-card-cool"><div class="extension-top"><span class="extension-index">03</span><span class="extension-badge">Private package</span></div><h3>mathphp-units</h3><p>Evaluate quantities such as <code>2m * 6 + 200cm</code> with compatible conversions, dimensions, and stable errors.</p><ul><li>Length, mass, time, and temperature</li><li>Explicit <code>to</code> conversions</li><li>Normalized and display values</li></ul><a class="button button-secondary" href="?page=units">Explore package <span>→</span></a></article></div><div class="extension-foot"><span>Private packages · separate installation · distribution model to be chosen</span><a href="?page=pricing">Read distribution notes <span>→</span></a></div></section>'
        . '<section class="api-callout wrap"><div><div class="section-kicker">A tiny API</div><h2>One call between input and result.</h2><p>Keep the evaluator behind your own form, API, or rule editor. MathPHP does the careful part.</p></div><pre><code><span class="code-keyword">$result</span> = Math::evaluate(<span class="code-string">\'2 * (3 + 4)\'</span>);</code></pre></section>'
        . '<section class="detail-section wrap"><div class="section-kicker">A clearer mental model</div><div class="detail-grid"><article><span class="feature-number">01</span><h3>Parse</h3><p>Input becomes a small, immutable syntax tree. Operators and grouping are resolved before any value is calculated.</p></article><article><span class="feature-number">02</span><h3>Validate</h3><p>Names, function arity, domains, spans, and resource limits are checked before work can escape your boundary.</p></article><article><span class="feature-number">03</span><h3>Evaluate</h3><p>The result is a native integer or float. Optional packages can observe the same events and add explanations or visual models.</p></article></div><div class="detail-links"><a href="?page=getting-started">Install guide →</a><a href="?page=errors">Error contract →</a><a href="?page=explaining-steps">Teaching layer →</a><a href="?page=visuals-plots">Visual layer →</a></div></section>';
}

function renderPackages(): string
{
    return '<section class="page-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Package catalogue</div><h1>One core.<br><em>Three ways to extend it.</em></h1><p>The evaluator stays free and focused. Add the private package that gives your users a lesson, a picture, or quantities with real units.</p></section>'
        . '<section class="package-overview wrap"><div class="package-overview-grid"><article class="package-feature package-feature-warm"><span class="package-label">01 · Private add-on</span><h2>Explain every move.</h2><p><code>mathphp/mathphp-explaining</code> turns an AST evaluation into ordered, translated steps with substitutions, partial results, and source spans.</p><a class="button button-secondary" href="?page=explaining">Explore Explaining <span>→</span></a></article><article class="package-feature package-feature-cool"><span class="package-label">02 · Private add-on</span><h2>Make the result visible.</h2><p><code>mathphp/mathphp-visuals</code> produces portable chart data and accessible SVG fallbacks for plots, matrices, calculus, and statistics.</p><a class="button button-secondary" href="?page=visuals">Explore Visuals <span>→</span></a></article><article class="package-feature package-feature-cool"><span class="package-label">03 · Private add-on</span><h2>Keep units attached.</h2><p><code>mathphp/mathphp-units</code> parses quantities such as <code>2m * 6 + 200cm</code>, normalizes compatible dimensions, and returns a formatted result.</p><a class="button button-secondary" href="?page=units">Explore Units <span>→</span></a></article></div><div class="package-note"><strong>Built as additions.</strong><span>Install the free core alone, or load one or more private packages when your product needs them.</span><a href="?page=pricing">Read distribution notes <span>→</span></a></div></section>'
        . '<section class="detail-section wrap"><div class="section-kicker">Choose by job</div><div class="comparison-table"><div><strong>Need to teach?</strong><span>Explaining gives you ordered steps, stable translation keys, and analyzer models for equations, systems, matrices, calculus, areas, roots, and statistics.</span><a href="?page=explaining-equations">Browse analyzers →</a></div><div><strong>Need to draw?</strong><span>Visuals gives you renderer-neutral representations, sampling metadata, and SVG output you can embed or replace.</span><a href="?page=visuals-rendering">Browse rendering →</a></div><div><strong>Need measurements?</strong><span>Units keeps dimensions explicit, converts compatible values, and exposes normalized and display values to your API.</span><a href="?page=units-guide">Read the Units guide →</a></div></div></section>';
}

function renderPackage(string $package): string
{
    $explaining = $package === 'explaining';
    $units = $package === 'units';
    $name = $explaining ? 'mathphp-explaining' : ($units ? 'mathphp-units' : 'mathphp-visuals');
    $title = $explaining ? 'Teach the calculation.' : ($units ? 'Keep the unit attached.' : 'Show the calculation.');
    $description = $explaining
        ? 'A private extension that observes the same deterministic evaluator and turns each completed node into a useful, translatable lesson.'
        : ($units ? 'A private extension for dimensional quantities, conversions, and readable expressions such as 2m * 6 + 200cm or 25m to km.' : 'A private extension that keeps visual output structured first, with accessible SVG and image-ready fallbacks when a frontend renderer is not available.');
    $features = $explaining
        ? '<li>Post-order steps with dependencies</li><li>Substitutions, partial results, and exact spans</li><li>Closed-form analyzers for common equations and systems</li><li>Bounded numerical roots for any Core expression equality</li><li>English and Danish translations</li><li>Custom observers without changing core semantics</li>'
        : ($units ? '<li>Length, area, volume, mass, time, temperature, and angle units</li><li>Litres, square/cubic lengths, and explicit speed units</li><li>Automatic conversion before compatible addition</li><li>Dimensional algebra with stable errors</li>' : '<li>Line plots, areas, roots, and histograms</li><li>Equation, matrix, system, and calculus models</li><li>Renderer-neutral data for your own frontend</li><li>Accessible SVG and image-ready data URIs</li>');
    $example = $explaining
        ? '<div class="package-code"><span class="code-comment">// explain a scalar expression</span><br><span class="code-keyword">$result</span> = (<span class="code-keyword">new</span> Explainer(Translations::create(<span class="code-string">\'en\'</span>)))-&gt;explain(<span class="code-string">\'(5*2)*2\'</span>);<br><br><span class="code-comment">// solve a mixed equation numerically</span><br><span class="code-keyword">$roots</span> = (<span class="code-keyword">new</span> NumericalEquationAnalyzer())-&gt;analyze(<span class="code-string">\'sin(x) = x / 2\'</span>, <span class="code-string">\'x\'</span>, -8, 8);</div>'
        : ($units ? '<div class="package-code"><span class="code-comment">// normalize compatible quantities</span><br><span class="code-keyword">$total</span> = UnitMath::evaluate(<span class="code-string">\'2m * 6 + 200cm\'</span>);<br><span class="code-comment">// 14 m</span><br><span class="code-keyword">$distance</span> = UnitMath::evaluate(<span class="code-string">\'25m to km\'</span>);<br><span class="code-comment">// 0.025 km</span></div>' : '<div class="package-code"><span class="code-comment">// keep data and presentation separate</span><br><span class="code-keyword">$plot</span> = (<span class="code-keyword">new</span> Plotter())-&gt;plot(<span class="code-string">\'sin(x)\'</span>, <span class="code-string">\'x\'</span>, 0, 6.28);</div>');
    $install = $explaining
        ? '<div class="package-code package-install"><span class="code-comment"># add approved private VCS sources</span><br>composer config repositories.mathphp-visuals vcs https://github.com/mathphp/mathphp-visuals.git<br>composer config repositories.mathphp-explaining vcs https://github.com/mathphp/mathphp-explaining.git<br>composer require mathphp/mathphp-visuals:^0.1 mathphp/mathphp-explaining:^0.1</div>'
        : ($units ? '<div class="package-code package-install"><span class="code-comment"># add the approved private VCS source</span><br>composer config repositories.mathphp-units vcs https://github.com/mathphp/mathphp-units.git<br>composer require mathphp/mathphp-units:^0.1</div>' : '<div class="package-code package-install"><span class="code-comment"># add the approved private VCS source</span><br>composer config repositories.mathphp-visuals vcs https://github.com/mathphp/mathphp-visuals.git<br>composer require mathphp/mathphp-visuals:^0.1</div>');
    $showcase = $explaining
        ? '<div class="showcase-card showcase-card-dark"><div class="showcase-top"><span>Expression</span><code>(5 × 2) × 2</code></div><ol class="step-list light"><li><span class="step-index">1</span><span><strong>Multiply 5 by 2</strong><small>5 × 2 = 10</small></span><b>10</b></li><li><span class="step-index">2</span><span><strong>Multiply the partial result by 2</strong><small>10 × 2 = 20</small></span><b>20</b></li></ol><div class="showcase-result"><span>Final result</span><strong>20</strong></div></div>'
        : ($units ? '<div class="showcase-card showcase-card-dark"><div class="showcase-top"><span>Normalize or convert</span><code>25m to km</code></div><ol class="step-list light"><li><span class="step-index">1</span><span><strong>Read the source quantity</strong><small>25 m is stored as 25 base metres</small></span><b>25 m</b></li><li><span class="step-index">2</span><span><strong>Convert the display unit</strong><small>1 km = 1000 m · 25 ÷ 1000</small></span><b>0.025 km</b></li></ol><div class="showcase-result"><span>Converted quantity</span><strong>0.025 km</strong></div></div>' : '<div class="showcase-card showcase-card-mint"><div class="showcase-top"><span>Plot model</span><code>sin(x)</code></div><div class="mini-chart"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><div class="showcase-metrics"><span><b>128</b> samples</span><span><b>SVG</b> fallback</span><span><b>0 → 6.28</b> domain</span></div></div>');

    $visualGallery = '<section class="visual-gallery wrap"><div class="section-kicker">Visual catalogue</div><h2>One visual layer, many mathematical stories.</h2><p class="gallery-lead">Every card below is a renderer-neutral model with an SVG fallback. Feed the same representation to a web chart, a PDF export, an accessible text view, or your own renderer.</p><div class="visual-gallery-grid">'
        . '<article><div class="gallery-art gallery-line"><svg viewBox="0 0 240 90" role="img" aria-label="Sine line plot"><path d="M8 45 C30 5 52 5 74 45 S118 85 140 45 S184 5 206 45 S228 85 240 45"/></svg></div><strong>Line & function plots</strong><span>Sampled domains, gaps at undefined points, labels, and axes.</span><a href="?page=visuals-plots">Plot guide →</a></article>'
        . '<article><div class="gallery-art gallery-area"><svg viewBox="0 0 240 90" role="img" aria-label="Area under a curve"><path d="M8 72 C45 65 70 20 112 26 S175 70 232 18 L232 72 Z"/></svg></div><strong>Areas & integrals</strong><span>Signed areas, intervals, sample counts, and shaded fallback output.</span><a href="?page=explaining-area">Area guide →</a></article>'
        . '<article><div class="gallery-art gallery-bars"><svg viewBox="0 0 240 90" role="img" aria-label="Histogram"><path d="M8 78H232"/><path d="M20 78V52H48V78M58 78V28H86V78M96 78V12H124V78M134 78V38H162V78M172 78V58H200V78"/></svg></div><strong>Histograms & distributions</strong><span>Explicit bins, counts, and source samples for statistical graphics.</span><a href="?page=explaining-statistics">Statistics guide →</a></article>'
        . '<article><div class="gallery-art gallery-matrix"><svg viewBox="0 0 240 90" role="img" aria-label="Matrix heatmap"><g><rect x="36" y="15" width="42" height="24"/><rect x="84" y="15" width="42" height="24"/><rect x="132" y="15" width="42" height="24"/><rect x="36" y="45" width="42" height="24"/><rect x="84" y="45" width="42" height="24"/><rect x="132" y="45" width="42" height="24"/></g></svg></div><strong>Matrices & heatmaps</strong><span>Dimensions, entries, labels, and color-safe cell representations.</span><a href="?page=explaining-matrices">Matrix guide →</a></article>'
        . '<article><div class="gallery-art gallery-system"><svg viewBox="0 0 240 90" role="img" aria-label="Linear system"><path d="M28 25H212M28 45H212M28 65H212"/><circle cx="92" cy="25" r="6"/><circle cx="148" cy="65" r="6"/></svg></div><strong>Equations & systems</strong><span>Unknowns, constraints, solution states, and equation-flow diagrams.</span><a href="?page=explaining-systems">Systems guide →</a></article>'
        . '<article><div class="gallery-art gallery-calculus"><svg viewBox="0 0 240 90" role="img" aria-label="Calculus curves"><path d="M12 70 C54 12 76 12 112 55 S170 80 228 18"/><path d="M12 52 C56 48 86 42 112 35 S175 25 228 12"/></svg></div><strong>Derivatives & calculus</strong><span>Formula pairs, operation labels, sampled curves, and annotations.</span><a href="?page=explaining-calculus">Calculus guide →</a></article>'
        . '<article><div class="gallery-art gallery-root"><svg viewBox="0 0 240 90" role="img" aria-label="Root convergence"><path d="M16 70H224M120 12V78"/><circle cx="138" cy="70" r="5"/><path d="M38 24L138 70M190 24L138 70"/></svg></div><strong>Roots & convergence</strong><span>Brackets, approximate roots, and convergence stories that stay honest.</span><a href="?page=explaining-roots">Roots guide →</a></article>'
        . '<article><div class="gallery-art gallery-graph"><svg viewBox="0 0 240 90" role="img" aria-label="Dependency graph"><path d="M46 25C90 25 90 45 132 45M46 65C90 65 90 45 132 45M132 45C170 45 170 25 208 25"/><circle cx="38" cy="25" r="9"/><circle cx="38" cy="65" r="9"/><circle cx="140" cy="45" r="9"/><circle cx="216" cy="25" r="9"/></svg></div><strong>Dependency & step graphs</strong><span>Evaluation trees, operation order, and explainable graph layouts.</span><a href="?page=explaining-steps">Steps guide →</a></article>'
        . '<article><div class="gallery-art gallery-formula"><svg viewBox="0 0 240 90" role="img" aria-label="Formula card"><text x="18" y="54">a² + b² = c²</text></svg></div><strong>Formula cards & notation</strong><span>Accessible SVG formulas for docs, lessons, reports, and PDFs.</span><a href="?page=visuals-rendering">Rendering guide →</a></article>'
        . '<article><div class="gallery-art gallery-scatter"><svg viewBox="0 0 240 90" role="img" aria-label="Scatter plot"><path d="M14 76H228M14 12V76"/><g><circle cx="44" cy="62" r="3"/><circle cx="72" cy="48" r="3"/><circle cx="104" cy="56" r="3"/><circle cx="130" cy="28" r="3"/><circle cx="166" cy="36" r="3"/><circle cx="204" cy="18" r="3"/></g></svg></div><strong>Scatter & coordinate graphics</strong><span>Point clouds, annotations, and coordinate overlays for data-driven math.</span><a href="?page=visuals-rendering">Rendering guide →</a></article>'
        . '<article><div class="gallery-art gallery-vector"><svg viewBox="0 0 240 90" role="img" aria-label="Vector field"><path d="M30 24l18 0m-8-8l8 8-8 8M80 24l18 10m-3-11l3 11-11 1M30 62l18-10m-8-1l8 1-3 8M80 62l18-2m-10-6l10 6-9 6"/></svg></div><strong>Vectors & vector fields</strong><span>Direction, magnitude, and annotation-ready glyph data for physics and geometry.</span><a href="?page=visuals-rendering">Rendering guide →</a></article>'
        . '<article><div class="gallery-art gallery-polar"><svg viewBox="0 0 240 90" aria-label="Polar graph" role="img"><circle cx="120" cy="45" r="31"/><path d="M12 45H228M120 10V80M120 45L149 23M120 45L149 67"/></svg></div><strong>Polar & parametric graphs</strong><span>Angle/radius paths and parameterized curves for engineering and geometry.</span><a href="?page=visuals-rendering">Polar guide →</a></article>'
        . '</div><div class="gallery-foot"><strong>Composable output.</strong><span>Each visual can be serialized as JSON, embedded as SVG, or rendered by your own Canvas/WebGL layer.</span><a class="button button-secondary" href="?page=visuals-rendering">Read the rendering contract <span>→</span></a></div></section>';

    $whatItAdds = $explaining
        ? '<article><span class="feature-number">01</span><h3>A teaching layer</h3><p>Turn a single result into a sequence a learner can follow, with the operation, operands, translation key, and resulting value.</p></article><article><span class="feature-number">02</span><h3>Closed-form analyzers</h3><p>Explain linear, quadratic, power, system, calculus, matrix, area, root, and statistics operations with explicit result states.</p></article><article><span class="feature-number">03</span><h3>General numerical solving</h3><p>Sample any Core-compatible single-variable equality across a finite interval and expose every certified bracket, gap, and approximation.</p></article>'
        : ($units ? '<article><span class="feature-number">01</span><h3>Dimensions stay honest</h3><p>Length, mass, time, temperature, and angle are represented explicitly so adding metres to seconds fails clearly.</p></article><article><span class="feature-number">02</span><h3>Convert on demand</h3><p>Use the explicit <code>to</code> operator, such as <code>25m to km</code>, to choose a compatible display unit without losing the normalized value.</p></article><article><span class="feature-number">03</span><h3>Composable output</h3><p>Serialize value, unit, dimensions, and a formatted label for the playground, APIs, or the explaining add-on.</p></article>' : '<article><span class="feature-number">01</span><h3>Data before pixels</h3><p>Get samples, domains, labels, and metadata as plain models before choosing SVG, Canvas, or a chart library.</p></article><article><span class="feature-number">02</span><h3>More than plots</h3><p>Use the same contracts for matrices, equations, systems, calculus, roots, areas, and statistical summaries.</p></article><article><span class="feature-number">03</span><h3>Accessible fallback</h3><p>Render a useful SVG or image-ready data URI when a full frontend renderer is unavailable.</p></article>');

    return '<section class="page-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Private extension</div><h1>' . $title . '<br><em>' . e($name) . '</em></h1><p>' . $description . '</p></section>'
        . '<section class="package-detail wrap"><div class="package-detail-grid"><div><span class="package-label">' . e($name) . '</span><h2>Designed to load separately.</h2><p>Keep <code>mathphp/mathphp</code> small and stable. This package adds its own contracts, translations, and presentation models without changing the public evaluator.</p><ul class="package-list">' . $features . '</ul><a class="button button-primary" href="?page=pricing">Read integration notes <span>↗</span></a></div><div>' . $showcase . $example . '<h3 class="package-install-heading">Install from an approved source</h3><p class="package-install-copy">These repositories are private and may be consumed through your organization’s Composer mirror or VCS access. Pin a tag or commit for production.</p>' . $install . '<div class="package-callout"><strong>Distribution placeholder.</strong><span>Private repositories and commercial terms are intentionally not automated yet; the delivery model will be chosen separately.</span></div></div></div></section>'
        . '<section class="package-adds wrap"><div class="section-kicker">What it adds</div><div class="package-adds-grid">' . $whatItAdds . '</div></section>'
        . '<section class="package-flow wrap"><div><div class="section-kicker">How it fits</div><h2>One core call.<br><em>One richer response.</em></h2></div><div class="flow-steps"><div><span>01</span><strong>Evaluate</strong><small>Core parses and validates the expression.</small></div><div><span>02</span><strong>Enrich</strong><small>' . ($explaining ? 'Explaining records each completed operation.' : ($units ? 'Units normalizes dimensions and conversions.' : 'Visuals turns the result into portable models.')) . '</small></div><div><span>03</span><strong>Present</strong><small>Your application chooses the UI, language, and renderer.</small></div></div></section>'
        . ($explaining || $units ? '' : $visualGallery)
        . '<section class="package-guides wrap"><div class="section-kicker">Feature guides</div><div class="package-guide-links">' . ($explaining ? '<a href="?page=explaining-steps"><strong>Step-by-step evaluation</strong><span>Build a lesson from each operation →</span></a><a href="?page=explaining-translations"><strong>Translations & observers</strong><span>Localize and instrument safely →</span></a><a href="?page=explaining-equations"><strong>Equation analyzer</strong><span>Explain unknowns and known values →</span></a><a href="?page=explaining-systems"><strong>System analyzer</strong><span>Describe linear constraints →</span></a><a href="?page=explaining-matrices"><strong>Matrix analyzer</strong><span>Inspect dimensions and values →</span></a><a href="?page=explaining-calculus"><strong>Calculus analyzer</strong><span>Derivatives and integrals →</span></a><a href="?page=explaining-area"><strong>Area, roots & statistics</strong><span>Numerical analysis models →</span></a>' : ($units ? '<a href="?page=units-guide"><strong>Unit grammar</strong><span>Write quantities and conversions →</span></a><a href="?page=units-guide"><strong>Dimensional arithmetic</strong><span>Rates, powers, and compatibility →</span></a><a href="?page=units-guide"><strong>Custom catalogs</strong><span>Register domain-specific symbols →</span></a>' : '<a href="?page=visuals-plots"><strong>Plots & sampling</strong><span>Turn formulas into portable data →</span></a><a href="?page=visuals-rendering"><strong>Rendering pipeline</strong><span>SVG, data URIs, and accessibility →</span></a><a href="?page=visuals-analysis"><strong>Analysis models</strong><span>Connect analyzer output to a view →</span></a>')) . '</div></section>';
}

function renderPricing(): string
{
    return '<section class="page-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Distribution planning</div><h1>Keep the core open.<br><em>Choose the add-on model later.</em></h1><p>MathPHP Core remains free. The private add-ons are real packages, while the commercial delivery model is still being evaluated.</p></section>'
        . '<section class="pricing-section wrap" id="decision"><div class="planning-note"><strong>Non-transactional planning page</strong><span>Pricing, payment, sponsorship, and repository-access automation are intentionally not connected. These example tiers are comparison material only; no purchase, login, entitlement, or provisioning flow exists here.</span></div><div class="pricing-grid"><article class="pricing-card"><span class="pricing-kicker">Core</span><h2>Free</h2><div class="pricing-price">€0 <small>forever</small></div><p>The bounded evaluator for every PHP project.</p><ul><li>Public Composer package</li><li>Stable errors and limits</li><li>Community issues and pull requests</li></ul><a class="button button-secondary" href="?page=docs">Read the docs <span>→</span></a></article><article class="pricing-card pricing-card-featured"><span class="pricing-kicker">Individual · proposed</span><h2>Plus</h2><div class="pricing-price">€12 <small>/ month</small></div><p>Step-by-step explanations and visual output for one developer.</p><ul><li>Both private add-ons</li><li>Named-user access (proposal)</li><li>Updates while active (proposal)</li><li>Use of obtained versions (proposal)</li></ul><a class="button button-primary" href="#decision">Review the proposal <span>↓</span></a></article><article class="pricing-card"><span class="pricing-kicker">Team · proposed</span><h2>Team</h2><div class="pricing-price">€39 <small>/ month</small></div><p>Shared access for a small product team.</p><ul><li>Both private add-ons</li><li>Up to five developers</li><li>Commercial internal use</li><li>Priority issue handling</li></ul><a class="button button-secondary" href="#decision">Review the proposal <span>↓</span></a></article></div><div class="pricing-terms"><strong>Current status</strong><span>The packages and private licenses are real; checkout, sponsorship, user provisioning, and access revocation are not wired into this site.</span><a href="?page=docs#api">Read license and API notes <span>→</span></a></div><div class="detail-grid pricing-detail"><article><h3>License boundary</h3><p>Existing licensed copies remain usable under the private package license. Distribution and branding rules still apply.</p></article><article><h3>Access boundary</h3><p>No account sync, token issuing, or repository automation is performed by the website.</p></article><article><h3>Decision needed</h3><p>Choose a payment and access provider before turning this proposal into a purchase flow.</p></article></div></section>';
}

function renderDocs(): string
{
    return '<section class="page-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Documentation</div><h1>Learn the engine.<br><em>Then extend it.</em></h1><p>A task-focused guide to the public evaluator, its boundaries, and the private packages that add teaching and presentation layers.</p></section>'
        . '<section class="docs-home wrap"><div class="docs-home-lead"><div class="section-kicker">Start here</div><h2>Choose the question you need to answer.</h2><p>Every page includes a concrete expression, the relevant contract, and a path into the next feature.</p></div><div class="guide-index-grid"><a href="?page=getting-started"><span>Core · 00</span><strong>Getting started</strong><small>Install and make the first call →</small></a><a href="?page=grammar"><span>Core · 01</span><strong>Grammar & precedence</strong><small>Operators, grouping, and AST rules →</small></a><a href="?page=functions"><span>Core · 02</span><strong>Functions & variables</strong><small>Allowlisted calls and custom functions →</small></a><a href="?page=errors"><span>Core · 03</span><strong>Errors & spans</strong><small>Stable codes and useful validation →</small></a><a href="?page=limits"><span>Core · 04</span><strong>Resource limits</strong><small>Bound work before it grows →</small></a><a href="?page=api"><span>Core · 05</span><strong>PHP API</strong><small>Install, evaluate, integrate →</small></a></div></section>'
        . '<section class="docs-addons wrap"><div class="section-kicker">Private add-ons</div><h2>When a number needs a lesson, a picture, or a measurement.</h2><div class="addon-doc-grid"><a href="?page=explaining-steps"><span class="addon-doc-mark">01</span><strong>Explaining</strong><small>Steps, translations, observers, analyzers</small><em>Explore the teaching layer →</em></a><a href="?page=visuals-plots"><span class="addon-doc-mark">02</span><strong>Visuals</strong><small>Plots, models, SVG fallbacks</small><em>Explore the presentation layer →</em></a><a href="?page=units-guide"><span class="addon-doc-mark">03</span><strong>Units</strong><small>Dimensions, conversions, display values</small><em>Explore the measurement layer →</em></a></div><div class="docs-principles"><div><strong>Core first</strong><span>Every add-on accepts the same validated inputs as the public evaluator.</span></div><div><strong>Models over markup</strong><span>Return arrays and representations your web, mobile, or CLI client can consume.</span></div><div><strong>License-aware</strong><span>Private packages have separate licenses; their distribution and access model remains a product decision.</span></div></div></section>';
}

function renderGuide(string $guide): string
{
    if ($guide === 'units-guide') {
        return '<section class="page-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Units add-on</div><h1>Expressions with<br><em>something to measure.</em></h1><p>Use explicit quantities in metres, areas, litres, mass, time, temperature, angles, and speeds. The add-on converts compatible values before arithmetic and rejects dimension mistakes.</p></section><section class="guide-page wrap"><div class="guide-content guide-content-wide"><h2>Start with a quantity</h2><p>Install the private package, then evaluate the expression through <code>UnitMath</code>:</p><pre class="code-block"><code><span class="code-keyword">$quantity</span> = UnitMath::evaluate(<span class="code-string">\'2m * 6 + 200cm\'</span>);<br><span class="code-comment">// 14 m</span></code></pre><h2>Convert when the output needs a specific unit</h2><p>Use the explicit <code>to</code> operator to change the display unit while keeping the normalized value:</p><pre class="code-block"><code><span class="code-keyword">$distance</span> = UnitMath::evaluate(<span class="code-string">\'25m to km\'</span>);<br><span class="code-comment">// 0.025 km</span></code></pre><h2>Derived quantities work the same way</h2><p>Square and cubic lengths, litres, and registered speeds share dimensions, so compatible values can be combined or converted:</p><pre class="code-block"><code>UnitMath::evaluate(<span class="code-string">\'1L + 500mL\'</span>); <span class="code-comment">// 1.5 L</span><br>UnitMath::evaluate(<span class="code-string">\'60mph to kmh\'</span>); <span class="code-comment">// 96.56064 kmh</span></code></pre><h2>Conversions happen before addition</h2><p><code>200cm</code> is normalized to two metres, so it can be added to <code>12m</code>. Adding <code>2m + 3s</code> fails with the stable <code>units.incompatible_addition</code> error instead of returning a meaningless number.</p><h2>Temperature offsets stay explicit</h2><p>Absolute readings such as <code>20C</code> and <code>10C</code> cannot be added. Subtracting them gives a Kelvin difference: <code>20C - 10C</code> returns <code>10 K</code>; convert with <code>0C to F</code> for <code>32 F</code>.</p><h2>Keep the model in your API</h2><p>A quantity serializes as its normalized numeric value, display unit, dimensions, and formatted label. Pass that object to the explaining add-on to produce translated unit steps, or to the visuals add-on for unit-labelled axes.</p><div class="guide-note"><strong>Scope:</strong><span>The core <code>mathphp/mathphp</code> package remains scalar-only. Units are an opt-in grammar and dependency.</span></div><a class="button button-primary" href="?page=units">See the package details <span>→</span></a></div></section>';
    }
    $guides = [
        'getting-started' => ['Core guide', 'Getting started', 'Install the core package, make a safe evaluation, and understand where the rest of the guide fits.', '<h2>One dependency. One public call.</h2><p>MathPHP is designed to sit behind a form, rule editor, API, or pricing calculation. Start with the bounded core, then add the private layers only when your product needs them.</p><pre class="code-block"><code><span class="code-comment">// composer require mathphp/mathphp</span><br><span class="code-keyword">$total</span> = Math::evaluate(<span class="code-string">\'subtotal * (1 + tax)\'</span>, [<span class="code-string">\'subtotal\'</span> =&gt; 42.5, <span class="code-string">\'tax\'</span> =&gt; 0.2]);</code></pre><div class="guide-note"><strong>Next:</strong><span>Learn the <a href="?page=grammar">grammar</a>, then wire the <a href="?page=api">PHP API</a> into your application.</span></div>'],
        'grammar' => ['Core feature', 'Grammar & precedence', 'Build expressions people can read: numbers, variables, grouping, operators, and deliberate precedence.', '<h2>From text to a typed result.</h2><p>MathPHP accepts a small expression language and turns it into an immutable AST before evaluation. Multiplication binds tighter than addition, exponentiation is right-associative, and unary signs have explicit rules.</p><pre class="code-block"><code><span class="code-keyword">$result</span> = Math::evaluate(<span class="code-string">\'gross * (1 - discount)\'</span>, [<span class="code-string">\'gross\'</span> =&gt; 125, <span class="code-string">\'discount\'</span> =&gt; 0.2]);</code></pre><div class="guide-note"><strong>Try it:</strong><span><code>2^3^2</code> is <code>512</code>; <code>-2^2</code> is <code>-4</code>.</span></div>'],
        'functions' => ['Core feature', 'Functions & variables', 'Use a focused allowlist of math functions and pass values explicitly from your application.', '<h2>Small allowlist, predictable domains.</h2><p>Built-ins such as <code>sqrt</code>, <code>sin</code>, <code>cos</code>, <code>ln</code>, and two-argument <code>log</code> are validated for arity and domain before execution. Variables are supplied per call and never read from global PHP state. Register <code>tan</code>, <code>min</code>, or domain-specific functions explicitly when your application needs them.</p><div class="guide-grid"><div><span class="feature-number">01</span><h3>Pass values</h3><p>Keep user input separate from trusted application data.</p></div><div><span class="feature-number">02</span><h3>Register safely</h3><p>Add a custom function with an explicit name, arity, and callback.</p></div></div><a class="button button-secondary" href="?page=playground">Try a function <span>↗</span></a>'],
        'errors' => ['Core feature', 'Errors & source spans', 'Make invalid input useful with stable error codes, exact spans, and phase-aware exceptions.', '<h2>Failures you can design around.</h2><p>Lexing, parsing, and evaluation errors are represented by <code>MathException</code> subclasses. Every expression error carries a stable code and a source span so your form can underline the exact problem.</p><div class="error-grid"><div><code>lex.malformed_number</code><span>Invalid numeric token</span></div><div><code>parse.unexpected_token</code><span>Expression structure is invalid</span></div><div><code>eval.division_by_zero</code><span>Division or modulo by zero</span></div><div><code>eval.integer_overflow</code><span>Exact integer result cannot fit</span></div></div>'],
        'limits' => ['Core feature', 'Resource limits', 'Bound work before it grows: expression length, nesting, arguments, exponents, and factorial inputs.', '<h2>Fail closed by configuration.</h2><p>EvaluationOptions lets you choose bounded defaults for your product. Limits are checked before expensive work and return ordinary source-aware errors instead of timeouts or runaway allocations.</p><div class="guide-grid"><div><span class="feature-number">Expression</span><h3>Length & depth</h3><p>Protect parsers and user interfaces from oversized or deeply nested input.</p></div><div><span class="feature-number">Evaluation</span><h3>Magnitude & calls</h3><p>Bound exponents, factorials, and function argument counts.</p></div></div>'],
        'api' => ['Core feature', 'PHP API & integration', 'Install the core package, evaluate safely, and add instrumentation without coupling your application to internals.', '<h2>One public facade.</h2><p>Keep your integration small: install through Composer, call <code>Math::evaluate()</code>, and catch <code>MathException</code>. The observer seam is available when a separate explaining or telemetry layer needs evaluation events.</p><pre class="code-block"><code><span class="code-comment">// composer require mathphp/mathphp</span><br><span class="code-keyword">$result</span> = Math::evaluate(<span class="code-string">\'2 * (3 + 4)\'</span>);</code></pre><a class="button button-primary" href="?page=playground">Open playground <span>↗</span></a>'],
        'explaining-steps' => ['Explaining add-on', 'Step-by-step evaluation', 'Turn one result into an ordered lesson with operations, substitutions, partial values, and exact source spans.', '<h2>Show the path, not just the destination.</h2><p>The explainer observes the same AST evaluation as Core and emits deterministic post-order steps. Each step can be rendered as a card, narrated aloud, or stored for an audit trail.</p><div class="guide-step-demo"><span>01</span><div><strong>Multiply 5 by 2</strong><small>5 × 2 = 10</small></div><b>10</b></div><div class="guide-step-demo"><span>02</span><div><strong>Multiply the partial result by 2</strong><small>10 × 2 = 20</small></div><b>20</b></div>'],
        'explaining-translations' => ['Explaining add-on', 'Translations & observers', 'Deliver the same explanation in English, Danish, or your own catalogue without changing evaluation semantics.', '<h2>Translate presentation, not mathematics.</h2><p>Step data carries stable keys and values. Choose a locale at the edge of your application, extend the catalogue when needed, and keep your domain logic language-neutral.</p><pre class="code-block"><code><span class="code-keyword">$translator</span> = Translations::create(<span class="code-string">\'da\'</span>);<br><span class="code-keyword">$explanation</span> = (<span class="code-keyword">new</span> Explainer(<span class="code-keyword">$translator</span>))-&gt;explain(<span class="code-string">\'(5*2)*2\'</span>);</code></pre>'],
        'visuals-plots' => ['Visuals add-on', 'Plots & sampling', 'Turn a formula into renderer-neutral samples, domains, labels, and an accessible fallback.', '<h2>Data first. Pixels second.</h2><p>Plot models are plain data your frontend can draw with SVG, Canvas, or a chart library. Sampling and domain metadata stay consistent even when the renderer changes.</p><pre class="code-block"><code><span class="code-keyword">$plot</span> = (<span class="code-keyword">new</span> Plotter())-&gt;plot(<span class="code-string">\'sin(x)\'</span>, <span class="code-string">\'x\'</span>, 0, 6.28);</code></pre><div class="guide-note"><strong>Fallback included:</strong><span>Generate accessible SVG or image-ready data when no chart library is available.</span></div>'],
        'visuals-analysis' => ['Visuals add-on', 'Equations, matrices & calculus', 'Use one presentation model for equation analysis, linear systems, matrices, derivatives, areas, roots, and statistics.', '<h2>A shared vocabulary for rich math.</h2><p>Visuals includes structured analysis models so your UI does not need to parse strings or duplicate numerical rules. Each model is ready to serialize through an API or hand to a renderer.</p><div class="guide-grid"><div><span class="feature-number">Analysis</span><h3>Equations & systems</h3><p>Expose knowns, unknowns, constraints, and partial solutions.</p></div><div><span class="feature-number">Calculus</span><h3>Derivatives & areas</h3><p>Present formulas, intervals, samples, and result metadata together.</p></div></div>'],
        'explaining-equations' => ['Explaining add-on', 'Equation analysis', 'Describe what is known, what is unknown, and which transformations are safe to show to a learner.', '<h2>Make unknowns explicit.</h2><p><code>EquationAnalyzer</code> returns a serializable analysis rather than pretending every equation has a closed-form solution. Use its closed-form rules for linear, quadratic, and power forms, or use <code>NumericalEquationAnalyzer</code> for any single-variable equality that Core can evaluate.</p><pre class="code-block"><code><span class="code-keyword">$analysis</span> = (<span class="code-keyword">new</span> EquationAnalyzer())-&gt;analyze(<span class="code-string">\'1*x^2 + 0*x + 1 = 5\'</span>);<br><span class="code-comment">// render $analysis-&gt;toArray() in your UI</span></code></pre><pre class="code-block"><code><span class="code-keyword">$numeric</span> = (<span class="code-keyword">new</span> NumericalEquationAnalyzer())-&gt;analyze(<span class="code-string">\'sin(x) = x / 2\'</span>, <span class="code-string">\'x\'</span>, -8, 8);<br><span class="code-comment">// roots: approximately -1.89549, 0, 1.89549</span></code></pre><div class="guide-note"><strong>Numerical scope:</strong><span>Finite intervals and sampled bisection provide approximate roots; <code>partial</code> remains possible for domain gaps, tangent roots, or roots outside the interval.</span></div>'],
        'explaining-systems' => ['Explaining add-on', 'Linear systems', 'Turn a set of equations into a structured explanation of variables, constraints, and solution status.', '<h2>Explain the system as a system.</h2><p><code>SystemAnalyzer</code> accepts two equations in one semicolon- or newline-delimited string. It keeps both constraints and the solution status together so a UI does not need to parse the text twice.</p><pre class="code-block"><code><span class="code-keyword">$analysis</span> = (<span class="code-keyword">new</span> SystemAnalyzer())-&gt;analyze(<span class="code-string">\'2*x + 3*y = 8; 1*x - 1*y = 1\'</span>);</code></pre><p>Use the returned model for a table, a guided exercise, or a compact API response. Keep numerical solving and teaching copy as separate concerns.</p>'],
        'explaining-matrices' => ['Explaining add-on', 'Matrix analysis', 'Inspect a 2×2 matrix, its determinant, transpose, optional inverse, and heatmap-ready metadata.', '<h2>Dimensions are part of the explanation.</h2><p><code>MatrixAnalyzer</code> accepts a finite 2×2 numeric array and returns status, determinant, transpose, an inverse when one exists, and a visual model. Invalid or ragged input fails early with an input error.</p><pre class="code-block"><code><span class="code-keyword">$analysis</span> = (<span class="code-keyword">new</span> MatrixAnalyzer())-&gt;analyze([[1, 2], [3, 4]]);<br><span class="code-comment">// $analysis-&gt;result[\'determinant\'] === -2</span></code></pre><div class="guide-note"><strong>Pair it with:</strong><span><a href="?page=visuals-rendering">Visuals rendering</a> for an accessible matrix view.</span></div>'],
        'explaining-calculus' => ['Explaining add-on', 'Calculus analysis', 'Represent derivatives and antiderivatives with the expression, variable, operation, and result metadata intact.', '<h2>Show the operation and its assumptions.</h2><p><code>CalculusAnalyzer</code> exposes focused <code>derivative()</code>, <code>derivativeOrder()</code>, and <code>integral()</code> methods. That keeps the transformation, variable, operation, and result explicit for separate UI elements.</p><pre class="code-block"><code><span class="code-keyword">$analysis</span> = (<span class="code-keyword">new</span> CalculusAnalyzer())-&gt;derivative(<span class="code-string">\'x^2 + 3*x\'</span>, <span class="code-string">\'x\'</span>);</code></pre><p>For sampled curves, hand the model to <a href="?page=visuals-plots">Plotter</a> and label numerical approximations clearly.</p>'],
        'explaining-area' => ['Explaining add-on', 'Area analysis', 'Explain numerical area under a curve with interval, sampling, and explicit partial-result metadata.', '<h2>Make approximation visible—and honest.</h2><p><code>AreaAnalyzer</code> uses Simpson’s rule and returns the interval, sample count, signed estimate, and sampled visual data. Preserve those fields so learners can see how the number was produced.</p><pre class="code-block"><code><span class="code-keyword">$analysis</span> = (<span class="code-keyword">new</span> AreaAnalyzer())-&gt;analyze(<span class="code-string">\'x^2\'</span>, <span class="code-string">\'x\'</span>, 0, 1, samples: 21);<br><span class="code-comment">// status: solved; area: approximately 0.333333333333</span></code></pre><p>If any sample is undefined or non-finite, the result is <code>partial</code> with <code>area: null</code>; visual gaps keep <code>y: null</code> instead of inventing zero. Combine the model with a shaded representation and accessible summary.</p>'],
        'explaining-roots' => ['Explaining add-on', 'Root finding', 'Show a bracket, convergence evidence, and explicit partial status rather than presenting a root as magic.', '<h2>Explain convergence—and its limits.</h2><p><code>RootAnalyzer</code> uses bisection and exposes the original interval, endpoint sign check, midpoint history, and approximate result. A finite increasing bracket with opposite signs is required; an endpoint at zero is valid.</p><pre class="code-block"><code><span class="code-keyword">$analysis</span> = (<span class="code-keyword">new</span> RootAnalyzer())-&gt;analyze(<span class="code-string">\'x^2 - 2\'</span>, <span class="code-string">\'x\'</span>, 0, 2, iterations: 20);<br><span class="code-comment">// status: solved; root: approximately 1.4142135623731</span></code></pre><div class="guide-note"><strong>Partial is a result:</strong><span>If endpoints do not bracket a root or an interior sample is undefined, the analyzer returns <code>status: partial</code> and <code>root: null</code>. Keep the steps and successful iterations in the explanation.</span></div>'],
        'explaining-statistics' => ['Explaining add-on', 'Statistics analysis', 'Turn a numeric sample into summary values and histogram-ready bins with explicit input assumptions.', '<h2>Summaries with provenance.</h2><p><code>StatisticsAnalyzer</code> keeps the input sample and bin choice close to the derived summary. This is ideal for teaching mean, spread, and distribution without hiding the data.</p><pre class="code-block"><code><span class="code-keyword">$analysis</span> = (<span class="code-keyword">new</span> StatisticsAnalyzer())-&gt;analyze([2, 3, 3, 4, 8], 4);</code></pre><p>Use <a href="?page=visuals-rendering">Visuals</a> to render the bins, and expose the raw sample for accessible fallback text.</p>'],
        'visuals-rendering' => ['Visuals add-on', 'Rendering pipeline', 'Keep representations portable: generate structured data first, then choose SVG, Canvas, or your own renderer.', '<h2>Renderer-neutral by design.</h2><p><code>VisualRepresentation</code> is the hand-off point between analysis and presentation. <code>Plotter</code> creates samples and metadata; <code>SvgRenderer</code> turns supported models into embeddable, accessible SVG.</p><pre class="code-block"><code><span class="code-keyword">$svg</span> = SvgRenderer::scatter([<span class="code-string">[\'x\' =&gt; 1, \'y\' =&gt; 3]</span>, <span class="code-string">[\'x\' =&gt; 2, \'y\' =&gt; 4]</span>]);<br><span class="code-keyword">$formula</span> = SvgRenderer::formula(<span class="code-string">\'Pythagorean theorem\'</span>, <span class="code-string">\'a² + b² = c²\'</span>);</code></pre><div class="guide-grid"><div><h3>Accessibility</h3><p>Every helper emits an image role, an ARIA label, and readable fallback text. Add a visible summary beside dense graphics.</p></div><div><h3>Portability</h3><p>Use formula, dependency, line, area, histogram, matrix, system, calculus, root, scatter, polar, vector-field, and geometry helpers across web, PDF, and mobile clients.</p></div><div><h3>Unit labels</h3><p>Decorate any visual with <code>UnitLabels::withAxes()</code> so axis units travel with the renderer-neutral data and SVG accessibility label.</p></div></div>'],
    ];
    if (!isset($guides[$guide])) {
        return renderDocs();
    }
    [$eyebrow, $title, $description, $body] = $guides[$guide];
    $coreNav = ['getting-started' => 'Getting started', 'grammar' => 'Grammar & precedence', 'functions' => 'Functions & variables', 'errors' => 'Errors & spans', 'limits' => 'Resource limits', 'api' => 'PHP API'];
    $addonNav = ['explaining-steps' => 'Explaining · Steps', 'explaining-translations' => 'Explaining · Translations', 'explaining-equations' => 'Explaining · Equations', 'explaining-systems' => 'Explaining · Systems', 'explaining-matrices' => 'Explaining · Matrices', 'explaining-calculus' => 'Explaining · Calculus', 'explaining-area' => 'Explaining · Areas', 'explaining-roots' => 'Explaining · Roots', 'explaining-statistics' => 'Explaining · Statistics', 'visuals-plots' => 'Visuals · Plots', 'visuals-rendering' => 'Visuals · Rendering', 'visuals-analysis' => 'Visuals · Analysis'];
    $nav = '';
    foreach (['Core' => $coreNav, 'Add-ons' => $addonNav] as $label => $items) {
        $nav .= '<div class="guide-nav-group"><span>' . e($label) . '</span>';
        foreach ($items as $key => $item) {
            $nav .= '<a' . ($key === $guide ? ' class="active"' : '') . ' href="?page=' . $key . '">' . e($item) . '</a>';
        }
        $nav .= '</div>';
    }
    $keys = array_keys($guides);
    $position = array_search($guide, $keys, true);
    $previous = $position > 0 ? $keys[$position - 1] : null;
    $next = $position < count($keys) - 1 ? $keys[$position + 1] : null;
    $pager = '<div class="guide-pager">' . ($previous ? '<a href="?page=' . $previous . '"><small>Previous</small><strong>← ' . e($guides[$previous][1]) . '</strong></a>' : '<span></span>') . ($next ? '<a class="next" href="?page=' . $next . '"><small>Next</small><strong>' . e($guides[$next][1]) . ' →</strong></a>' : '<span></span>') . '</div>';
    return '<section class="page-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>' . e($eyebrow) . '</div><h1>' . e($title) . '</h1><p>' . e($description) . '</p></section><section class="guide-page wrap"><a class="guide-back" href="?page=docs">← All documentation</a><div class="guide-layout"><aside class="guide-sidebar">' . $nav . '</aside><div class="guide-content">' . $body . $pager . '</div></div></section>';
}

function renderPlayground(): string
{
    return '<section class="page-intro playground-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Interactive evaluator</div><h1>Make a calculation.<br><em>See its edges.</em></h1><p>Try the same engines your PHP application calls. Choose Core or Units, or let the Playground detect quantity expressions automatically.</p></section>'
        . '<section class="playground wrap" data-playground><div class="editor-panel"><div class="panel-heading"><span>Expression</span><span class="panel-hint">⌘ ↵ to evaluate</span></div><textarea id="expression" spellcheck="false">(5*2)*2</textarea><div class="panel-heading variables-heading"><span>Variables <small>JSON object</small></span><span class="playground-controls"><label class="locale-label" for="engine">Engine <select id="engine"><option value="auto">Auto</option><option value="core">Core</option><option value="units">Units</option></select><small id="engine-hint" class="engine-hint" aria-live="polite">Auto chooses from the expression</small></label><label class="locale-label" for="locale">Language <select id="locale"><option value="en">English</option><option value="da">Dansk</option></select></label></span></div><textarea id="variables" spellcheck="false">{}</textarea><div class="action-row"><button class="button button-primary evaluate-button" id="evaluate">Evaluate <span>↗</span></button><button class="button button-secondary" id="explain">Explain steps <span>✦</span></button><button class="button button-secondary" id="plot">Plot function <span>⌁</span></button></div><div class="action-row calculus-actions"><button class="button button-secondary" id="derivative">Derivative <span>′</span></button><button class="button button-secondary" id="integral">Antiderivative <span>∫</span></button><button class="button button-secondary" id="area">Area 0→1 <span>∫</span></button><button class="button button-secondary" id="root-find">Find root 0→2 <span>≈</span></button></div><div class="equation-tool"><div class="panel-heading"><span>Equation analysis</span><span class="panel-hint">partial solving is honest</span></div><input id="equation" value="x^y = z" aria-label="Equation"><button class="button button-secondary" id="analyze">Analyze equation <span>∑</span></button></div><div class="equation-tool"><div class="panel-heading"><span>Linear system</span><span class="panel-hint">two equations, two unknowns</span></div><textarea id="system" aria-label="Linear system">2*x + 3*y = 8; 1*x - 1*y = 1</textarea><button class="button button-secondary" id="analyze-system">Analyze system <span>▦</span></button></div><div class="equation-tool"><div class="panel-heading"><span>Matrix</span><span class="panel-hint">JSON 2×2</span></div><textarea id="matrix" aria-label="Matrix">[[1,2],[3,4]]</textarea><button class="button button-secondary" id="analyze-matrix">Analyze matrix <span>▦</span></button></div><div class="examples"><span>Try an example</span><button type="button" data-example="(5*2)*2">(5*2)*2</button><button type="button" data-example="sqrt(144) + abs(-3)">sqrt(144) + abs(-3)</button><button type="button" data-example="2^3^2">2^3^2</button><button type="button" data-example="10 / 0">10 / 0</button><button type="button" data-example="2m * 6 + 200cm">2m × 6 + 200cm</button><button type="button" data-example="25m to km">25m → km</button></div></div><div class="result-panel"><div class="panel-heading"><span id="result-heading">Result</span><span class="result-live"><i></i> live</span></div><div class="result-empty" id="result"><span class="result-symbol">∑</span><strong>Your result will appear here.</strong><span>Evaluate normally, or reveal each operation one step at a time.</span></div><div class="result-meta"><span id="result-engine">MathPHP evaluator</span><span>PHP 8.2+</span></div></div></section>';
}

/**
 * @param array<string|int, mixed> $rawVariables
 * @return array<string, int|float>
 */
function normalizeVariables(array $rawVariables): array
{
    $variables = [];
    foreach ($rawVariables as $name => $value) {
        if (!is_string($name) || (!is_int($value) && !is_float($value))) {
            throw new InvalidArgumentException('Variables must be a JSON object with numeric values.');
        }
        $variables[$name] = $value;
    }

    return $variables;
}

function handleEvaluationRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $rawVariables = is_array($payload) && is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    try {
        $variables = normalizeVariables($rawVariables);
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_variables', 'message' => $error->getMessage(), 'span' => [0, 0]], JSON_THROW_ON_ERROR);
        exit;
    }

    try {
        $result = Math::evaluate($expression, $variables);
        echo json_encode(['ok' => true, 'result' => $result, 'type' => get_debug_type($result), 'display' => formatResult($result)], JSON_THROW_ON_ERROR);
    } catch (MathException $error) {
        $span = $error->span();
        echo json_encode(['ok' => false, 'code' => $error->errorCode(), 'message' => $error->getMessage(), 'span' => [$span->start, $span->end]], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleExplanationRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\Explainer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private mathphp-explaining package is not installed on this deployment.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $rawVariables = is_array($payload) && is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    $locale = is_array($payload) && is_string($payload['locale'] ?? null) ? $payload['locale'] : 'en';

    try {
        $variables = normalizeVariables($rawVariables);
        $translator = \MathPHP\Explaining\Translation\Translations::create($locale);
        $explanation = (new \MathPHP\Explaining\Explainer($translator))->explain($expression, $variables);
        echo json_encode(['ok' => true, 'explanation' => $explanation->toArray()], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_variables', 'message' => $error->getMessage(), 'span' => [0, 0]], JSON_THROW_ON_ERROR);
    } catch (MathException $error) {
        $span = $error->span();
        echo json_encode(['ok' => false, 'code' => $error->errorCode(), 'message' => $error->getMessage(), 'span' => [$span->start, $span->end]], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleUnitsRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Units\\UnitMath')) {
        echo json_encode(['ok' => false, 'code' => 'units.unavailable', 'message' => 'The private mathphp-units package is not installed on this deployment.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $rawVariables = is_array($payload) && is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    try {
        $quantity = \MathPHP\Units\UnitMath::evaluate($expression, normalizeVariables($rawVariables));
        echo json_encode(['ok' => true, 'quantity' => $quantity->toArray()], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_variables', 'message' => $error->getMessage(), 'span' => [0, 0]], JSON_THROW_ON_ERROR);
    } catch (\MathPHP\Units\UnitException $error) {
        echo json_encode(['ok' => false, 'code' => $error->errorCode, 'message' => $error->getMessage(), 'span' => [$error->position, $error->position + 1]], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleUnitExplanationRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\UnitExplainer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.units_unavailable', 'message' => 'The private mathphp-explaining package is not installed on this deployment.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $rawVariables = is_array($payload) && is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    $locale = is_array($payload) && is_string($payload['locale'] ?? null) ? $payload['locale'] : 'en';
    try {
        $explanation = (new \MathPHP\Explaining\UnitExplainer(\MathPHP\Explaining\Translation\Translations::create($locale)))
            ->explain($expression, normalizeVariables($rawVariables));
        echo json_encode(['ok' => true, 'unitExplanation' => $explanation->toArray()], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_variables', 'message' => $error->getMessage(), 'span' => [0, 0]], JSON_THROW_ON_ERROR);
    } catch (\MathPHP\Units\UnitException $error) {
        echo json_encode(['ok' => false, 'code' => $error->errorCode, 'message' => $error->getMessage(), 'span' => [$error->position, $error->position + 1]], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleEquationRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\EquationAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private mathphp-explaining package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $equation = is_array($payload) && is_string($payload['equation'] ?? null) ? $payload['equation'] : '';
    $rawKnown = is_array($payload) && is_array($payload['known'] ?? null) ? $payload['known'] : [];
    try {
        $known = normalizeVariables($rawKnown);
        $analysis = (new \MathPHP\Explaining\EquationAnalyzer())->analyze($equation, $known);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_variables', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleNumericalEquationRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\NumericalEquationAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The numerical equation analyzer is not installed on this deployment.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $equation = is_array($payload) && is_string($payload['equation'] ?? null) ? $payload['equation'] : '';
    $variable = is_array($payload) && is_string($payload['variable'] ?? null) ? $payload['variable'] : 'x';
    $minimum = is_array($payload) && is_numeric($payload['minimum'] ?? null) ? (float) $payload['minimum'] : -10.0;
    $maximum = is_array($payload) && is_numeric($payload['maximum'] ?? null) ? (float) $payload['maximum'] : 10.0;
    $samples = is_array($payload) && is_numeric($payload['samples'] ?? null) ? (int) $payload['samples'] : 256;
    $iterations = is_array($payload) && is_numeric($payload['iterations'] ?? null) ? (int) $payload['iterations'] : 60;
    try {
        $analysis = (new \MathPHP\Explaining\NumericalEquationAnalyzer())->analyze($equation, $variable, $minimum, $maximum, $samples, $iterations);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_equation', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleInequalityRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\InequalityAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The inequality analyzer is not installed on this deployment.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $inequality = is_array($payload) && is_string($payload['inequality'] ?? null) ? $payload['inequality'] : '';
    $variable = is_array($payload) && is_string($payload['variable'] ?? null) ? $payload['variable'] : 'x';
    $minimum = is_array($payload) && is_numeric($payload['minimum'] ?? null) ? (float) $payload['minimum'] : -10.0;
    $maximum = is_array($payload) && is_numeric($payload['maximum'] ?? null) ? (float) $payload['maximum'] : 10.0;
    $samples = is_array($payload) && is_numeric($payload['samples'] ?? null) ? (int) $payload['samples'] : 512;
    try {
        $analysis = (new \MathPHP\Explaining\InequalityAnalyzer())->analyze($inequality, $variable, $minimum, $maximum, $samples);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_inequality', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleLinearSystemGeneralRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\LinearSystemAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The general linear-system analyzer is not installed on this deployment.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $system = is_array($payload) && is_string($payload['system'] ?? null) ? $payload['system'] : '';
    $rawKnown = is_array($payload) && is_array($payload['known'] ?? null) ? $payload['known'] : [];
    try {
        $analysis = (new \MathPHP\Explaining\LinearSystemAnalyzer())->analyze($system, normalizeVariables($rawKnown));
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_variables', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleNonlinearSystemRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\NonlinearSystemAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The nonlinear-system analyzer is not installed on this deployment.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $system = is_array($payload) && is_string($payload['system'] ?? null) ? $payload['system'] : '';
    $rawVariables = is_array($payload) && is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    $rawInitial = is_array($payload) && is_array($payload['initial'] ?? null) ? $payload['initial'] : [];
    $rawStarts = is_array($payload) && is_array($payload['starts'] ?? null) ? $payload['starts'] : [];
    $iterations = is_array($payload) && is_numeric($payload['iterations'] ?? null) ? (int) $payload['iterations'] : 32;
    $tolerance = is_array($payload) && is_numeric($payload['tolerance'] ?? null) ? (float) $payload['tolerance'] : 1e-10;
    try {
        $variables = [];
        foreach ($rawVariables as $variable) {
            if (!is_string($variable)) {
                throw new InvalidArgumentException('Variables must be an array of names.');
            }
            $variables[] = $variable;
        }
        $initial = normalizeVariables($rawInitial);
        $analyzer = new \MathPHP\Explaining\NonlinearSystemAnalyzer();
        if ($rawStarts !== []) {
            $starts = [];
            foreach ($rawStarts as $rawStart) {
                if (!is_array($rawStart)) {
                    throw new InvalidArgumentException('Each nonlinear-system start must be a JSON object.');
                }
                $starts[] = normalizeVariables($rawStart);
            }
            $analyses = $analyzer->analyzeMany($system, $variables, $starts, $iterations, $tolerance);
            echo json_encode(['ok' => true, 'analyses' => array_map(static fn ($analysis): array => $analysis->toArray(), $analyses)], JSON_THROW_ON_ERROR);
        } else {
            $analysis = $analyzer->analyze($system, $variables, $initial, $iterations, $tolerance);
            echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
        }
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_nonlinear_system', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handlePlotRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Visuals\\Plotter')) {
        echo json_encode(['ok' => false, 'code' => 'visuals.unavailable', 'message' => 'The private mathphp-visuals package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $variable = is_array($payload) && is_string($payload['variable'] ?? null) ? $payload['variable'] : 'x';
    $minimum = is_array($payload) && is_numeric($payload['minimum'] ?? null) ? (float) $payload['minimum'] : -10.0;
    $maximum = is_array($payload) && is_numeric($payload['maximum'] ?? null) ? (float) $payload['maximum'] : 10.0;
    $samples = is_array($payload) && is_numeric($payload['samples'] ?? null) ? (int) $payload['samples'] : 101;
    $rawVariables = is_array($payload) && is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    try {
        $visual = (new \MathPHP\Visuals\Plotter())->plot($expression, $variable, $minimum, $maximum, $samples, normalizeVariables($rawVariables));
        $xUnit = is_array($payload) && is_string($payload['xUnit'] ?? null) ? trim($payload['xUnit']) : '';
        $yUnit = is_array($payload) && is_string($payload['yUnit'] ?? null) ? trim($payload['yUnit']) : '';
        if ($xUnit !== '' || $yUnit !== '') {
            $visual = \MathPHP\Visuals\UnitLabels::withAxes(
                $visual,
                \MathPHP\Visuals\UnitLabels::axis($variable, $xUnit),
                \MathPHP\Visuals\UnitLabels::axis('value', $yUnit),
            );
        }
        echo json_encode(['ok' => true, 'visual' => $visual->toArray()], JSON_THROW_ON_ERROR);
    } catch (\InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_plot', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    } catch (MathException $error) {
        $span = $error->span();
        echo json_encode(['ok' => false, 'code' => $error->errorCode(), 'message' => $error->getMessage(), 'span' => [$span->start, $span->end]], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleSystemRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\SystemAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $system = is_array($payload) && is_string($payload['system'] ?? null) ? $payload['system'] : '';
    $analysis = (new \MathPHP\Explaining\SystemAnalyzer())->analyze($system);
    echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    exit;
}

function handleCalculusRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\CalculusAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $variable = is_array($payload) && is_string($payload['variable'] ?? null) ? $payload['variable'] : 'x';
    $operation = is_array($payload) && in_array($payload['operation'] ?? 'derivative', ['integral', 'derivative-order'], true) ? $payload['operation'] : 'derivative';
    $order = is_array($payload) && is_numeric($payload['order'] ?? null) ? (int) $payload['order'] : 1;
    $analyzer = new \MathPHP\Explaining\CalculusAnalyzer();
    $analysis = $operation === 'integral' ? $analyzer->integral($expression, $variable) : ($operation === 'derivative-order' ? $analyzer->derivativeOrder($expression, $variable, $order) : $analyzer->derivative($expression, $variable));
    echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    exit;
}

function handleAreaRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\AreaAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $variable = is_array($payload) && is_string($payload['variable'] ?? null) ? $payload['variable'] : 'x';
    $minimum = is_array($payload) && is_numeric($payload['minimum'] ?? null) ? (float) $payload['minimum'] : 0.0;
    $maximum = is_array($payload) && is_numeric($payload['maximum'] ?? null) ? (float) $payload['maximum'] : 1.0;
    $samples = is_array($payload) && is_numeric($payload['samples'] ?? null) ? (int) $payload['samples'] : 101;
    try {
        $analysis = (new \MathPHP\Explaining\AreaAnalyzer())->analyze($expression, $variable, $minimum, $maximum, $samples);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (\InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_area', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleRootRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\RootAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $variable = is_array($payload) && is_string($payload['variable'] ?? null) ? $payload['variable'] : 'x';
    $minimum = is_array($payload) && is_numeric($payload['minimum'] ?? null) ? (float) $payload['minimum'] : 0.0;
    $maximum = is_array($payload) && is_numeric($payload['maximum'] ?? null) ? (float) $payload['maximum'] : 1.0;
    $iterations = is_array($payload) && is_numeric($payload['iterations'] ?? null) ? (int) $payload['iterations'] : 40;
    try {
        $analysis = (new \MathPHP\Explaining\RootAnalyzer())->analyze($expression, $variable, $minimum, $maximum, $iterations);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (\InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_root', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleStatisticsRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\StatisticsAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $values = is_array($payload) && is_array($payload['values'] ?? null) ? $payload['values'] : [];
    $bins = is_array($payload) && is_numeric($payload['bins'] ?? null) ? (int) $payload['bins'] : 5;
    try {
        $analysis = (new \MathPHP\Explaining\StatisticsAnalyzer())->analyze($values, $bins);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (\InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_statistics', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleMatrixRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\MatrixAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $matrix = is_array($payload) && is_array($payload['matrix'] ?? null) ? $payload['matrix'] : [];
    try {
        $analysis = (new \MathPHP\Explaining\MatrixAnalyzer())->analyze($matrix);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (\InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_matrix', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleCapabilitiesRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    $state = runtimePackageState();
    $capabilities = [
        ['id' => 'evaluate', 'endpoint' => '?api=evaluate', 'input' => 'expression, variables', 'visualKinds' => [], 'requiredPackages' => ['core'], 'available' => $state['core']],
        ['id' => 'explain', 'endpoint' => '?api=explain', 'input' => 'expression, variables, locale', 'visualKinds' => ['dependency-graph'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'equation', 'endpoint' => '?api=analyze', 'input' => 'equation, known', 'visualKinds' => ['equation-flow'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'numerical-equation', 'endpoint' => '?api=solve-equation', 'input' => 'single-variable equality, variable, finite interval, samples', 'visualKinds' => ['equation-roots'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'inequality', 'endpoint' => '?api=inequality', 'input' => 'inequality, variable, finite interval, samples', 'visualKinds' => ['inequality-intervals'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'linear-system-general', 'endpoint' => '?api=linear-system', 'input' => 'affine equations, known parameters', 'visualKinds' => ['linear-system-general'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'nonlinear-system', 'endpoint' => '?api=nonlinear-system', 'input' => 'nonlinear equations (square/overdetermined), variables, initial value(s), iterations', 'visualKinds' => ['nonlinear-system'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'system', 'endpoint' => '?api=system', 'input' => '2×2 system', 'visualKinds' => ['linear-system'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'matrix', 'endpoint' => '?api=matrix', 'input' => '2×2 matrix', 'visualKinds' => ['matrix-heatmap'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'calculus', 'endpoint' => '?api=calculus', 'input' => 'expression, operation, variable', 'visualKinds' => ['calculus-derivative', 'calculus-integral'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'plot', 'endpoint' => '?api=plot', 'input' => 'expression, variable, domain, samples, optional xUnit/yUnit labels', 'visualKinds' => ['line-plot'], 'requiredPackages' => ['core', 'visuals'], 'available' => $state['core'] && $state['optional']['visuals']],
        ['id' => 'area', 'endpoint' => '?api=area', 'input' => 'expression, variable, interval, samples', 'visualKinds' => ['area-under-curve'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'root', 'endpoint' => '?api=root', 'input' => 'expression, variable, bracket', 'visualKinds' => ['root-convergence'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'statistics', 'endpoint' => '?api=statistics', 'input' => 'values, bins', 'visualKinds' => ['histogram'], 'requiredPackages' => ['core', 'explaining'], 'available' => $state['core'] && $state['optional']['explaining']],
        ['id' => 'units', 'endpoint' => '?api=units', 'input' => 'quantity expression, numeric variables', 'visualKinds' => [], 'requiredPackages' => ['core', 'units'], 'available' => $state['core'] && $state['optional']['units']],
        ['id' => 'unit-explain', 'endpoint' => '?api=unit-explain', 'input' => 'quantity expression, numeric variables, locale', 'visualKinds' => [], 'requiredPackages' => ['core', 'explaining', 'units'], 'available' => $state['core'] && $state['optional']['explaining'] && $state['optional']['units']],
    ];
    echo json_encode(['ok' => true, 'version' => WEBSITE_API_VERSION, 'capabilities' => $capabilities], JSON_THROW_ON_ERROR);
    exit;
}

function handleHealthRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    $state = runtimePackageState();
    $core = $state['core'];
    if (!$core) {
        http_response_code(503);
    }
    echo json_encode([
        'ok' => $core,
        'status' => $core ? 'ok' : 'degraded',
        'version' => WEBSITE_API_VERSION,
        'php' => PHP_VERSION,
        'packages' => ['core' => $core, 'optional' => $state['optional']],
        'packageVersions' => $state['versions'],
    ], JSON_THROW_ON_ERROR);
    exit;
}

function handleVersionRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    $state = runtimePackageState();
    echo json_encode([
        'ok' => true,
        'version' => WEBSITE_API_VERSION,
        'php' => PHP_VERSION,
        'packages' => $state['versions'],
    ], JSON_THROW_ON_ERROR);
    exit;
}

if (($_GET['api'] ?? '') === 'evaluate') {
    handleEvaluationRequest();
}
if (($_GET['api'] ?? '') === 'explain') {
    handleExplanationRequest();
}
if (($_GET['api'] ?? '') === 'units') {
    handleUnitsRequest();
}
if (($_GET['api'] ?? '') === 'unit-explain') {
    handleUnitExplanationRequest();
}
if (($_GET['api'] ?? '') === 'analyze') {
    handleEquationRequest();
}
if (($_GET['api'] ?? '') === 'solve-equation') {
    handleNumericalEquationRequest();
}
if (($_GET['api'] ?? '') === 'inequality') {
    handleInequalityRequest();
}
if (($_GET['api'] ?? '') === 'linear-system') {
    handleLinearSystemGeneralRequest();
}
if (($_GET['api'] ?? '') === 'nonlinear-system') {
    handleNonlinearSystemRequest();
}
if (($_GET['api'] ?? '') === 'plot') {
    handlePlotRequest();
}
if (($_GET['api'] ?? '') === 'system') {
    handleSystemRequest();
}
if (($_GET['api'] ?? '') === 'calculus') {
    handleCalculusRequest();
}
if (($_GET['api'] ?? '') === 'area') {
    handleAreaRequest();
}
if (($_GET['api'] ?? '') === 'root') {
    handleRootRequest();
}
if (($_GET['api'] ?? '') === 'statistics') {
    handleStatisticsRequest();
}
if (($_GET['api'] ?? '') === 'matrix') {
    handleMatrixRequest();
}
if (($_GET['api'] ?? '') === 'capabilities') {
    handleCapabilitiesRequest();
}
if (($_GET['api'] ?? '') === 'health') {
    handleHealthRequest();
}
if (($_GET['api'] ?? '') === 'version') {
    handleVersionRequest();
}

$page = $_GET['page'] ?? 'home';
$guidePages = ['getting-started', 'grammar', 'functions', 'errors', 'limits', 'api', 'units-guide', 'explaining-steps', 'explaining-translations', 'explaining-equations', 'explaining-systems', 'explaining-matrices', 'explaining-calculus', 'explaining-area', 'explaining-roots', 'explaining-statistics', 'visuals-plots', 'visuals-rendering', 'visuals-analysis'];
$page = in_array($page, array_merge(['home', 'packages', 'explaining', 'visuals', 'units', 'docs', 'playground', 'pricing'], $guidePages), true) ? $page : 'home';
$content = match ($page) {
    'packages' => renderPackages(),
    'explaining', 'visuals', 'units' => renderPackage($page),
    'docs' => renderDocs(),
    'playground' => renderPlayground(),
    'pricing' => renderPricing(),
    default => renderHome(),
};

if (in_array($page, $guidePages, true)) {
    $content = renderGuide($page);
}

$activePage = $page === 'explaining' || $page === 'units' ? 'packages' : (in_array($page, $guidePages, true) ? 'docs' : $page);
echo renderLayout(ucfirst($page), $content, $activePage);
