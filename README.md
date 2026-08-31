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
- `GET /?api=capabilities` lists the evaluator and add-on endpoints available to a client.

These endpoints are read-only. Payment, sponsorship, account provisioning, and
private-repository access remain deliberate product placeholders; the website
does not issue tokens or synchronize users.
