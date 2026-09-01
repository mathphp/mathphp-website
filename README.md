# MathPHP website

This is the public-facing companion site for MathPHP: a landing page, a concise reference, and an interactive evaluator demo.

It intentionally uses the existing PHP package directly instead of duplicating evaluator logic in a separate service.
Website CI checks both the minimum supported PHP 8.2 runtime and the PHP 8.5
runtime used by production.

## Run locally

From this repository root:

```sh
php -S 127.0.0.1:8080 -t public
```

Then open <http://127.0.0.1:8080/>.

The site installs the public `mathphp/mathphp` package through Composer. The
private add-ons are optional; when they are not installed, their interactive
endpoints report an explicit unavailable response while the package pages and
core evaluator remain usable.

## Operational endpoints

- `GET /?api=health` reports core readiness and which optional packages are loaded.
- `GET /?api=version` reports the API/PHP versions and resolved package revisions.
- `GET /?api=capabilities` lists the evaluator and add-on endpoints. Each entry keeps
  its stable `id` and includes `available` plus `requiredPackages`, so a client can
  distinguish an installed optional add-on from an endpoint that will return an
  explicit `*.unavailable` response.

Health keeps its existing boolean package fields for simple probes and adds
`packageVersions` for release diagnostics. Version data is read from the
runtime lockfiles (or Composer metadata for the core), so operators can match
a response to a specific build without exposing credentials.

The website commits its core `composer.lock`; Docker and CI therefore install
the reviewed Core `v0.3.5` revision instead of resolving a moving branch at
build time. For
optional packages, `MATHPHP_PRIVATE_REPO_REF` selects a shared branch, tag, or
commit fallback, while `MATHPHP_UNITS_REF`, `MATHPHP_VISUALS_REF`, and
`MATHPHP_EXPLAINING_REF` can pin each add-on independently. Startup checks out
the requested ref (defaulting to `v0.3.6`) before installing dependencies, and the health/version
responses expose the resolved revision. Optional package roots also write a
`.mathphp-revision` marker after checkout; this prevents a dependency lockfile
from masking the actual add-on source revision running in the container.
The Explaining add-on defaults to its compatible `v0.42.0` release. Units and
Visuals default to their compatible `v0.3.6` releases.
The Docker dependency stage also carries an explicit cache key tied to the
reviewed Core revision, so a remote build cache cannot silently retain older
Core vendor files after a lockfile update.

These endpoints are read-only. Payment, sponsorship, account provisioning, and
private-repository access remain deliberate product placeholders; the website
does not issue tokens or synchronize users.

The Playground escapes all API-provided text before inserting it into the
result panel. SVG returned by the private Visuals renderer passes through a
small client-side sanitizer that removes scripts, event handlers, navigation,
and embedded-document elements before rendering.

CI also runs `tools/site-js-contract.php` as a dependency-free regression guard
for those browser rendering boundaries.

## HTTP smoke checks

Run the dependency-free contract probe against a local server:

```sh
php -S 127.0.0.1:8080 -t public
php tools/http-smoke.php
```

The default core-only mode expects explicit `*.unavailable` responses for
private add-ons. To verify a deployment where all optional packages are
installed, set `MATHPHP_SMOKE_REQUIRE_OPTIONAL=1`; the same script then checks
the explanation, equation, system, matrix, calculus, area, root, statistics,
Units, and Visuals success payloads, including precise unit error spans.
The optional checks also cover complex evaluation, complex Newton equations,
exact polynomial inequalities, rational equations/inequalities with pole
metadata, and elementary inverse equations. They exercise multi-word and separator aliases such as
`metres per second` and `mile per hour to km/h`.
They also estimate finite one-sided and two-sided limits while preserving
undefined samples and the explicit non-proof `complete: false` marker.
The higher-order ODE check covers direct third-order initial-value input and
retains the full derivative-state trajectory.
They also guard affine-temperature errors such as scaling an absolute
`20C` reading.
Zero quantities raised to negative powers are checked as structured division
errors as well.
Unit responses also verify that normalized `value` and display-unit
`displayValue` remain distinct after conversion.
