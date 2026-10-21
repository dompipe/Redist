# Redist

**Redist is a PHP queueing and wave-dispatch front controller for smoothing bursts of incoming traffic before forwarding approved requests to configured destination services.**

The goal is to give a site a controlled ingress point: requests are validated, admitted to a queue, grouped into waves, and dispatched according to queue thresholds and release timers. Redist is designed to help manage overload; it is not a replacement for a CDN, WAF, upstream rate limits, or infrastructure-level DDoS mitigation.

> **Status:** Active development. Configure and test Redist against a staging destination before routing production traffic through it. A `202` response means a request was queued, not that the destination has served it.

## How it works

1. A client sends a JSON dispatch envelope to the ingress endpoint.
2. Redist validates the envelope and checks the section against `redist.manifest.json`.
3. The request is recorded in SQLite with an idempotency key.
4. The dispatcher releases work when the queue reaches its configured threshold or the oldest queued request reaches the release timer.
5. The worker dispatches a wave and waits for the previous wave to complete before advancing.
6. Destination outcomes are recorded; a successful destination response is what marks a request as served.

The timer only progresses if the worker runs. Configure a cron job for `php bin/dispatch-queue.php` at an interval appropriate for your release policy.

## Repository layout

The repository contains the runtime, worker, tests, and documentation. The intended deployment model is to keep the web-serving application together as a self-contained **`serve/` leaf**, separate from development assets:

```text
Redist/
├── serve/                  # Deployable web-serving unit (default)
│   ├── index.php           # Front controller
│   ├── .htaccess           # Optional Apache routing rules
│   ├── redist.manifest.json# Site-specific section configuration
│   ├── Redist/             # Runtime classes and CAPTCHA endpoint
│   ├── bin/                # Queue worker
│   └── config/             # Path and directory configuration
├── tests/                  # Smoke tests and regression checks
├── docs/                   # Deployment and design documentation
└── README.md
```

The deployment leaf and the installation destination should be configurable independently. A deployment must not blindly overwrite files already present at the destination: detect collisions and report them, and only replace existing files through an explicit, deliberate operation.

**Repository-layout note:** the current front-controller and Apache configuration are still present at the repository root on this branch. Treat the tree above as the intended deployment layout, not a claim that the migration into `serve/` has already been completed.

## Requirements

- PHP with SQLite/PDO SQLite support
- Apache `mod_rewrite` if using the optional `.htaccess` front-controller mode
- A writable location for the SQLite database and any runtime state
- A cron or other reliable scheduler for the queue worker
- HTTPS and appropriate edge protections for public deployments

Confirm the required PHP extensions and directory permissions on the target host before deployment.

## Configuration

Create `redist.manifest.json` for your site. The manifest defines the sections Redist is allowed to dispatch to and each section's `target_origin`. Use real origins that you control; do not deploy example destinations as production configuration.

A section may configure its IP protection policy and CAPTCHA behavior. CAPTCHA can be enabled at section level and overridden for exact paths through `file_policies`. `no-protect` disables only Redist's adaptive IP reputation/deferral policy for that section; it does not bypass the other ingress validations.

Keep environment-specific configuration and secrets out of public source control. Do not commit live credentials, private keys, or production-only configuration.

## Sending a dispatch request

Send a `POST` with `Content-Type: application/json` and a JSON object shaped like this:

```json
{
  "version": 1,
  "operation": "dispatch",
  "request_id": "request-20261021-000001",
  "section": "example",
  "payload": {
    "path": "/health",
    "query": {
      "detail": "full"
    },
    "data": {}
  }
}
```

- `request_id` is an idempotency key and must be 16–128 characters matching the endpoint's accepted safe-character format.
- `section` must exist in the manifest.
- `payload.path` is the destination path. `payload.query` is optional; when supplied as an object it is encoded by PHP. If omitted, the current front-controller implementation can preserve the incoming raw query string.
- The request body limit is 64 KiB by default in the current ingress implementation.

Use the endpoint's GET response as the machine-readable service description. Do not interpret an HTTP `202` acknowledgement as proof that the destination has completed the request.

## Optional Apache front-controller mode

The root `.htaccess` currently defaults to **off**. Its toggle can be changed to `on` to internally route requests through `index.php`, excluding the entry point itself and the numeric CAPTCHA endpoint.

This is an Apache internal rewrite, not a general-purpose transparent reverse proxy. The ingress still expects a valid Redist JSON envelope for POST requests; arbitrary HTML form submissions and arbitrary GET requests do not automatically become dispatches. Read [the front-controller notes](docs/front-controller-htaccess.md) and test on staging before enabling it.

## Running the queue worker

From the project directory, run:

```sh
php bin/dispatch-queue.php
```

Set up cron or an equivalent scheduler to invoke the worker regularly. The interval should be no longer than the configured queue release interval if timer-based releases are required. Monitor worker output and destination health in your hosting environment.

## Tests

The repository includes smoke tests for core behavior, including IP reputation/deferral and atomic no-overwrite file creation. Run the relevant test scripts with PHP in an environment that has the required extensions. CI configuration is under `.github/workflows/`.

A passing local smoke test does not replace a staging test of Apache rewrite behavior, PHP session handling, SQLite permissions, cron scheduling, and actual destination responses.

## Security and operational notes

- Use HTTPS.
- Restrict manifest destinations to services you control and verify them before enabling dispatch.
- Keep the SQLite database and runtime state outside public download paths where possible.
- Apply sensible request, connection, and queue limits at the web server or edge.
- Trust the connection peer address by default; do not accept arbitrary client-supplied forwarding headers as the real client IP.
- CAPTCHA is friction against automated submissions, not a standalone DDoS defense.
- A PHP front controller cannot protect traffic that bypasses it and reaches the destination directly.
- Back up the database and test recovery procedures before production use.

## Documentation

- [Optional Apache front-controller mode](docs/front-controller-htaccess.md)
- [Agent and manifest guidance](AGENT_MANIFEST.md)

## Contributing

Issues and pull requests are welcome. Please include reproduction steps for bugs, note the PHP/runtime environment, and add regression coverage for behavior changes. Avoid committing real production manifests, credentials, or personal data.

## License

No license is declared in this README. Check the repository's license metadata before redistributing or incorporating Redist into another project.
