# Deployment

The MathPHP website runs on EasyPanel as the `mathphp/web` service. Auto-deploy
is enabled: pushing a change to this repository's `main` branch triggers a
service deployment. This was confirmed on 2026-10-31 when the `Add accent rule
to site header` change deployed automatically.

The deployed site was verified at <https://mathphp-web.2nmpax.easypanel.host/>.
At the time of verification, <https://mathphp.com/> still served Porkbun's
parked-domain page; configure the domain's DNS to point to the website service
before using the custom domain as the production URL.

This documents observed behavior; it does not guarantee future deployments or
custom-domain DNS state. Check the EasyPanel deployment log and the live site
after pushing a change.
