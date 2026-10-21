# Optional .htaccess front-controller mode

The root `.htaccess` defaults to `REDIST_FRONT_CONTROLLER off`. Change it to `on` to route incoming requests through `index.php`.

- The original query string is retained with `QSA`.
- The HTTP method and request body are preserved by the internal rewrite.
- The original URL path is passed to PHP using an Apache rewrite environment variable.
- `index.php` is excluded to prevent a rewrite loop.
- `Redist/captcha/numeric.php` is excluded so the CAPTCHA page and image frames remain reachable.

Redist's `index.php` still expects a JSON dispatch envelope for POST requests. If `payload.path` is omitted, it uses the original rewritten path. If `payload.query` is omitted, it forwards the incoming raw query string as-is. An explicit `payload.query` object takes precedence and is encoded by PHP.

This is an internal rewrite, not a transparent reverse proxy or a general-purpose router: arbitrary form submissions and arbitrary GET requests do not automatically become valid Redist dispatch envelopes. The GET/HEAD response from `index.php` remains the Redist service description.

Requires Apache `mod_rewrite` and hosting configuration that permits `SetEnv`/rewrite directives in `.htaccess`. Test on staging before enabling on a live site.
