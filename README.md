# QRIVO — endpoint branch

This orphan branch exists so the QRIVO mobile app can learn where the API
currently lives. `start-qrivo.ps1` starts a Cloudflare quick tunnel, captures
the fresh public URL and force-pushes it here.

The app fetches:

    https://raw.githubusercontent.com/HekimSefkan/QRIVO/endpoint/endpoint.json

It carries an ADDRESS AND NOTHING ELSE — never a credential, token or policy.
The server remains the sole authority for every security decision. The app pins
the host shape (https + *.trycloudflare.com only), so this file cannot redirect
it to an arbitrary host.

This branch is force-pushed on every startup and has no meaningful history. It
is deliberately separate from `main` so restart churn never touches real
history.
