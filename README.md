# MathPHP website

This is the public-facing companion site for MathPHP: a landing page, a concise reference, and an interactive evaluator demo.

It intentionally uses the existing PHP package directly instead of duplicating evaluator logic in a separate service.

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

These endpoints are read-only. Payment, sponsorship, account provisioning, and
private-repository access remain deliberate product placeholders; the website
does not issue tokens or synchronize users.

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
The optional checks also exercise multi-word and separator aliases such as
`metres per second` and `mile per hour to km/h`.
They also guard affine-temperature errors such as scaling an absolute
`20C` reading.
Zero quantities raised to negative powers are checked as structured division
errors as well.
Unit responses also verify that normalized `value` and display-unit
`displayValue` remain distinct after conversion.
