# AGENT_MANIFEST.md

## Purpose

This document instructs an AI agent how to inspect a website and create its Redist site manifest. The manifest describes the site's logical sections (including subdomains and path-based sections) so Redist can maintain a separate queue and wave gate for each section.

**This file is the authoring specification, not the runtime manifest.** The AI should create or update the actual manifest separately, normally as `redist.manifest.json`, and must not claim Redist enforces these rules until the runtime implements them.

## Required outcome

Produce a manifest that:

- Lists the site's real, identifiable sections.
- Groups URLs by hostname and, where needed, path prefix.
- Gives every section a stable, unique ID.
- Defines how incoming requests are matched to exactly one section.
- Defines section-level wave settings explicitly.
- Records uncertain or unmapped routes instead of silently inventing sections.
- Can be reviewed by a human before it is used in production.

## Evidence to inspect

Use available evidence in this order, recording the sources inspected:

1. Existing Redist configuration and any existing manifest.
2. The site's repository: application routes, router configuration, framework config, reverse-proxy rules, virtual-host configuration, and deployment files.
3. `robots.txt`, sitemap files, navigation menus, and internal links, if the site is accessible.
4. DNS/subdomain lists, hosting configuration, or an explicit list supplied by the site owner.
5. Documentation describing product areas, services, or site architecture.

Do not assume that a hostname exists merely because it seems conventional. Do not infer private or unlisted subdomains from names alone. Do not scan or probe systems without authorization. If the AI cannot access a source, mark it unavailable and continue with the remaining evidence.

## Convert an existing sitemap into a manifest

Sitemap conversion is a first-class input mode. If the user supplies a sitemap URL or file, the AI should parse it and use it as the starting inventory for `redist.manifest.json`, rather than requiring the user to manually list every section.

### Sitemap input and discovery

1. Accept a local sitemap XML file, a `.xml.gz` sitemap, a sitemap index, or a sitemap URL supplied by the user.
2. If authorized and network access is available, check the site's `robots.txt` for declared sitemap locations. Do not probe guessed private URLs or crawl beyond the supplied/declared sitemap scope.
3. Handle both a standard `urlset` and a `sitemapindex`. For an index, recursively process its listed child sitemaps, with duplicate detection and a reasonable configurable limit to prevent loops or unbounded downloads.
4. Parse XML safely: disable external entity resolution and do not execute or fetch XML entities. Apply limits to download size, decompressed size, URL count, and redirects.
5. Extract each `loc` URL. Treat `lastmod`, `changefreq`, and `priority` as optional metadata only; they do not define section boundaries or prove that a page is active.
6. Record invalid URLs, unsupported sitemap formats, fetch failures, and limit-exceeded files in the conversion report. Do not silently discard them.
7. Normalize URLs for comparison: lowercase scheme and hostname, remove default ports and hostname trailing dots, and normalize empty paths to `/`. Preserve meaningful path case and query strings in the source inventory; do not assume query strings are part of section identity.
8. Deduplicate equivalent URLs for inventory purposes, but retain a count and examples of duplicates in the report. Do not use URL deduplication as the runtime's request idempotency mechanism.

### Derive candidate sections from sitemap URLs

For every valid `loc`, extract the hostname and path. Build a route inventory grouped by hostname, then identify candidate path prefixes:

- Distinct hostnames in the sitemap are evidence of those hostnames, but not proof that each requires an independent section.
- For each hostname, summarize the paths and common directory prefixes (for example, `/shop/products/...`, `/shop/orders/...`, `/help/...`).
- Prefer a small number of meaningful high-level sections over one section per URL. Do not create a separate section for every product, article, pagination page, or individual file.
- Use router/deployment documentation, navigation, sitemap grouping, and user guidance to decide whether a prefix is a true logical section.
- If the sitemap provides no reliable semantic boundary, create a provisional section per evidenced hostname and leave path-level subdivisions unconfirmed rather than inventing business meanings.
- A URL path of `/` is a root match, not a literal directory that should accidentally swallow every more-specific route. Use the manifest's documented matching semantics and explicit precedence.
- Sitemaps commonly omit login-only pages, APIs, temporary pages, and some subdomains. Never interpret absence from the sitemap as proof that a route does not exist.

### Convert, validate, and report

1. Create a source inventory containing each URL's normalized hostname/path and its source sitemap.
2. Generate candidate section rules that cover every valid sitemap URL exactly once.
3. Check overlapping host/path rules and test each sitemap URL against the proposed rules. Report URLs that match zero or multiple sections.
4. Keep source provenance in the report. If the manifest schema supports metadata, include a compact evidence note such as `sitemap: https://example.com/sitemap.xml`; do not copy the entire URL inventory into the manifest unless requested.
5. Validate the output JSON and the manifest structure. If there are many URLs, report counts and representative examples instead of flooding the final response with every URL.
6. Clearly separate **observed facts** (URLs found in sitemap) from **inferred candidates** (logical section names and wave settings).
7. Treat wave limits and completion policies as proposals unless established by the owner or tested runtime defaults.
8. If the user provides only a sitemap file, produce a draft manifest and conversion report from that file; do not require live-site access. Ask a clarifying question only when an ambiguity materially changes the section boundaries.

### Sitemap conversion report

Include:

- Sitemaps processed and whether each was supplied, discovered, or referenced by an index.
- URL count: discovered, valid, duplicate, and rejected.
- Hostnames observed, with counts per hostname.
- Proposed sections and the host/path rules covering each.
- Any sitemap URLs not matched exactly once.
- Candidate boundaries that need human confirmation.
- Fetch/XML/size-limit errors and unsupported metadata.
- Runtime compatibility: whether Redist can currently load and enforce the generated manifest.

## How to identify sections

A **section** is a group of requests that should share queue coordination and a wave-completion gate because they belong to the same logical site area.

### 1. Inspect subdomains

List hostnames supported by evidence, for example:

- `example.com` and `www.example.com` — public website, often one section.
- `account.example.com` — account and authentication workflows.
- `shop.example.com` — store and checkout.
- `api.example.com` — API endpoints, if Redist is intended to coordinate them.
- `media.example.com` — media delivery.

These are examples only. Include only hostnames actually evidenced for the target site. Do not automatically split every hostname into a separate section: aliases may serve the same application and should normally map to one section when the owner intends shared coordination.

### 2. Inspect path-based areas

A single hostname may contain distinct sections, such as:

- `/account/`
- `/admin/`
- `/shop/`
- `/api/`
- `/images/` or `/media/`

Use path sections when the routes are meaningfully distinct and should have independent queue/wave coordination. Do not split every page or individual route into its own section. Prefer a stable, high-level boundary such as a router prefix or application module.

### 3. Choose section boundaries

Create a separate section when there is evidence that the area has its own workload, application boundary, service, access pattern, or desired coordination behavior.

Keep routes together when they are aliases, share one application and should progress as a unit, or there is insufficient evidence to justify a split.

For each candidate section, record:

- Stable section ID (lowercase letters, digits, and hyphens; e.g. `account`).
- Human-readable purpose.
- Hostnames.
- Optional path prefixes.
- Evidence/source for the mapping.
- Whether the mapping is confirmed or provisional.
- Proposed wave limit and completion policy, with assumptions noted.

## URL matching rules

The generated manifest must use deterministic, non-overlapping routing:

1. Normalize hostnames to lowercase and remove a trailing dot.
2. Match hostnames exactly unless wildcard matching is explicitly supported by the runtime and authorized by the owner. Never use an unrestricted wildcard by default.
3. Match path prefixes on path-segment boundaries. For example, `/shop` matches `/shop` and `/shop/item`, but not `/shopping`.
4. If multiple rules match, choose the most specific hostname and then the longest matching path prefix, or flag the conflict if the runtime cannot guarantee that precedence.
5. A request must resolve to exactly one section. Detect and report ambiguous overlaps.
6. Define behavior for unmatched hosts and paths explicitly. Prefer `reject` or an explicitly configured fallback section; never silently route an unknown request to an arbitrary section.
7. Preserve query strings for the request itself, but do not use query parameters to define section boundaries unless the owner specifically requires it.
8. Do not treat the HTTP `Referer` as authoritative routing identity. Route using the request's validated host and path.

## IP-range reputation and targeted CAPTCHA challenges

Redist also tracks source IPs and groups IPv4 addresses into their A.B.C.0/24 range. This is separate from section availability and wave completion.

Current implementation notes for `Redist/storage/IpRangeGuard.php` and `Redist/url/purl.php`:

- Observations are recorded in SQLite for requests that pass through Redist's `pURL::spoof_check()`; this is not a host-wide firewall and does not observe requests that bypass Redist.
- The default trigger is 120 observed requests from one IPv4 /24 in a rolling one-minute window. Configure with `REDIST_RANGE_CHALLENGE_THRESHOLD`; challenge expiry defaults to 600 seconds and is configurable with `REDIST_RANGE_CHALLENGE_TTL_SECONDS`.
- A triggered range receives a Cloudflare Turnstile challenge. Configure `REDIST_TURNSTILE_SITE_KEY` and `REDIST_TURNSTILE_SECRET_KEY` in the server environment. Tokens are verified server-side against Turnstile's Siteverify endpoint.
- A successful challenge grants that IP/browser session a 30-minute pass; it does not clear the challenge for every address in the /24.
- Existing individual-IP and /24 blacklist entries are checked before CAPTCHA challenge state. Blocking a range can affect legitimate users behind shared NAT and should remain a deliberate escalation.
- If a range is flagged but CAPTCHA keys are missing, Redist returns a 503 rather than silently allowing the request through. Configure keys before enabling this behavior in production.
- Challenge status and range counts are displayed in the operations dashboard.

These are runtime behaviors only for the Redist entry point that invokes `spoof_check()`. Deployments should confirm all relevant inbound paths use that entry point, test the CAPTCHA provider configuration, and consider a trusted edge/WAF rate limiter for traffic that can overwhelm PHP before this code runs.

## Spoke-based coordination for high traffic

The manifest should describe a **coordinator with independent section spokes**. A spoke is a logical dispatch lane owned by one section (or explicitly configured subsection), not a page and not necessarily a separate server. The coordinator resolves a request to one section/spoke, admits it once, and tracks wave state; spokes process bounded work independently so a busy shop or API does not stall the public site.

This is a scale-ready design target, not a claim that the current Redist runtime already implements it.

### Responsibilities

- **Coordinator:** validates the manifest version, resolves host/path to exactly one section, assigns a stable request ID, enforces admission policy, and records the durable queue decision.
- **Section spoke:** owns that section's queue partition, active-wave lease, concurrency budget, and completion state. It may run on one process or be consumed by several workers using atomic claims.
- **Workers:** claim work from a spoke using a lease/visibility timeout and heartbeat; a worker must not dispatch an item whose claim is held by another live worker.
- **Shared durable store:** is the source of truth for request identity, queue state, wave membership, leases, attempts, and outcomes. In-memory counters or process-local locks alone are not sufficient when multiple processes or hosts are running.
- **Observer/operations view:** exposes queue depth, oldest-item age, active wave, in-flight count, throughput, latency, retries, failures, lease expiry, and rejected/admission-limited requests per spoke.

### Manifest policy for spokes

For each section, describe policy rather than hard-coding a machine topology:

- A stable spoke ID (default to the section ID when one section has one spoke).
- A bounded max-in-flight count and max requests per wave.
- Optional requests-per-second or token-bucket rate limit, scoped to the destination/section and configured conservatively.
- A bounded queue or explicit overflow policy (reject, defer, or owner-approved durable spillover); never silently drop admitted work.
- A fair scheduling policy across spokes, such as round-robin or weighted fair scheduling, so one hot section cannot monopolize shared workers.
- A lease duration, heartbeat interval, and retry budget with backoff; expired leases must be recoverable without allowing two workers to dispatch the same logical request.
- A circuit-breaker policy for sustained destination errors/timeouts, plus a clear half-open/recovery procedure.
- A completion policy that says whether every member must succeed, whether explicitly retryable failures can be retried in the same wave, and when a wave becomes blocked.
- Optional dependencies only when explicitly declared. Do not make all spokes wait for a site-wide barrier by default.

Keep limits finite. If traffic estimates and destination capacity are unknown, use conservative proposed values, mark them as unmeasured, and require review before activation. Do not set concurrency based only on CPU count: the constrained resource may be the destination, network, database, PHP worker pool, or external service.

### Coordination invariants under load

1. **Atomic admission:** persist the idempotency key and queue entry in one transaction. Concurrent duplicate submissions must resolve to the same logical request, not create parallel dispatches.
2. **Atomic claim:** use a transactional claim or compare-and-swap lease so only one worker owns an item at a time. A timeout alone does not prove the previous worker stopped.
3. **Fenced leases:** increment a lease/fencing token on reassignment; a stale worker must not be allowed to overwrite a newer worker's outcome.
4. **Durable wave membership:** persist membership before dispatch and record each result idempotently. A process crash must not erase the wave's state.
5. **Bounded fan-out:** each wave and spoke has explicit concurrency and rate ceilings. Backpressure must reach admission before memory or the database is overwhelmed.
6. **Fairness and isolation:** schedule ready spokes fairly; pause or throttle one failing/hot spoke without freezing unrelated spokes.
7. **Retry safety:** retry only according to the destination contract. Exactly-once network delivery cannot be guaranteed by a queue alone; use destination idempotency support where available and report ambiguous outcomes as indeterminate rather than blindly replaying them.
8. **Database discipline:** keep transactions short; do not hold a database write transaction open during network I/O. Use indexes for status/section/position/next-attempt queries and test lock contention with multiple workers.
9. **Graceful recovery:** on restart, recover expired leases, preserve blocked waves, and reconcile any dispatch whose remote result is unknown. Never mark queued, claimed, or timed-out work as served without the configured success evidence.
10. **Operational limits:** bound payload size, queue retention, attempts, log growth, and per-spoke backlog. Provide explicit retention and dead-letter/review behavior.

### Suggested manifest extension

These fields are a proposed schema until the runtime validates and enforces them. They express logical coordination policy, not a demand to deploy one machine per spoke:

```json
{
  "version": 1,
  "coordination": {
    "mode": "section-spokes",
    "admission": "atomic-idempotent",
    "scheduler": "weighted-fair",
    "max_global_in_flight": 40,
    "unmatched": "reject"
  },
  "sections": {
    "shop": {
      "spoke": {
        "id": "shop",
        "max_in_flight": 12,
        "max_requests_per_second": 8,
        "queue": {
          "max_pending": 20000,
          "overflow": "defer"
        },
        "lease": {
          "seconds": 60,
          "heartbeat_seconds": 15
        },
        "retry": {
          "max_attempts": 4,
          "backoff": "exponential-jitter"
        },
        "circuit_breaker": {
          "failure_threshold": 8,
          "cooldown_seconds": 30
        }
      },
      "wave": {
        "max_requests": 100,
        "completion": "all_success"
      }
    }
  }
}
```

The numbers above are examples only, not recommended production limits. Validate that global limits are at least as restrictive as the sum of section budgets where shared workers/resources require it. The runtime must reject unknown or unsupported settings rather than silently ignoring them.

### Load-readiness checks

Before describing a deployment as ready for heavy traffic, test and report:

- Concurrent duplicate admission and simultaneous workers claiming the same queue.
- Process termination after claim, during network I/O, and after remote success but before local acknowledgement.
- Queue growth, bounded-memory behavior, database lock contention, and backpressure at configured limits.
- Fair progress when one spoke is continuously busy and another has a small queue.
- Destination throttling, timeouts, retry storms, circuit-breaker recovery, and dead-letter visibility.
- Restart/recovery of leases and blocked waves.
- Metrics and alerts for queue age, backlog growth, failure rate, saturation, and stalled spokes.

A manifest alone cannot make a system high-traffic-ready. The implementation, storage engine, deployment topology, destination contract, and load tests must all support these invariants.


## Section availability and downtime recording

The runtime health recorder must distinguish observed failure from missing observations. **No traffic is unknown, not down.** Health may be derived from actual destination outcomes and/or explicitly configured authorized health probes; it must not be inferred from queue depth alone.

### State model

- `unknown`: there are no observations, observations are stale, or monitoring itself is unavailable.
- `healthy`: a destination response in the HTTP 2xx range was observed recently.
- `suspected_down`: failures are accumulating but the configured threshold has not been reached.
- `down`: the configured consecutive-failure threshold has been reached and an incident is open.
- `recovering`: successes have resumed but the configured recovery policy has not yet confirmed stability (when a multi-success recovery policy is enabled).

A response is successful only when the destination returned an HTTP 2xx response and no transport error occurred. Record non-2xx status codes, timeouts, DNS/TLS/connect errors, and unknown outcomes distinctly. A single failure must not declare a section down. Thresholds and observation windows must be configurable per section; display the threshold used. If the site is idle, use an explicitly authorized health probe or show unknown rather than falsely declaring an outage.

### Durable downtime recorder

Persist response observations and incident transitions in the durable store, not process memory. Each incident records section/spoke ID, incident ID, start estimate, detection timestamp, last known 2xx, recovery timestamp, failure counts, response/error samples, and final duration. Keep a history of recovered incidents and leave an open incident visible until recovery is confirmed. Preserve monitoring gaps and recorder failures separately from destination failures. Use UTC timestamps and stable ordering. The operations dashboard should display each section's state, last 2xx, last observation, current incident duration, recent incident history, and queue/circuit-breaker context.

Do not treat a response to a different section as proof of recovery. Recovery must be attributed to the same section and should follow the configured recovery threshold. Never silently delete downtime history; retention must be explicit and configurable.

The current branch records outcomes from the multi-cURL wave dispatcher and exposes section health plus downtime history in the protected operations dashboard. Until manifest routing is implemented, these observations are grouped by exact destination hostname (`host:<hostname>`), not by inferred path-based logical sections. The dashboard is live only for requests that actually pass through this dispatcher; it is not an independent active probe, and an idle destination remains unknown. Unit/smoke tests cover no traffic, 2xx, threshold crossing, recovery, persistent history, and separate section IDs. Validate the tests in CI before treating this as production-ready.

## Wave policy

The design target is **one active wave per section**, not one global wave for the entire site and not one wave for every individual page.

- Each section has its own queue and active-wave lock.
- A wave may contain a bounded batch of requests from that same section.
- Different sections may progress independently.
- The next wave in a section may begin only when that section's current wave meets its completion policy.
- A duplicate/retried entry for the same logical request must not be inserted or dispatched twice. Use a stable request/idempotency key; do not deduplicate solely by IP address, page URL, or serialized payload.
- Record per-request outcomes and keep failed or indeterminate work visible for safe recovery.
- Do not mark a request served merely because it was queued, a cURL handle was created, or a redirect was issued. Define success according to the actual destination-response contract.
- Wave limits are configuration choices, not measured facts. If no owner-provided limit or tested default exists, mark the value as a proposal and ask for review.
- A failure in one section should not block unrelated sections unless the owner explicitly configures a site-wide dependency.

These rules describe the intended design. The AI must inspect the current Redist implementation and clearly call out any rule that the runtime does not yet enforce.

## Runtime dispatch contract (implemented by root index.php)

The root POST endpoint requires `redist.manifest.json` beside `index.php`, with `version: 1` and a `sections` object. Every dispatchable section must declare an explicit `target_origin`, such as `https://origin.example.com`. This origin must be owner-controlled and reachable by the Redist host. The endpoint accepts a relative same-origin `payload.path` and optional `payload.query`; it constructs the destination URL from the manifest origin, never from a caller-supplied absolute URL. This prevents the POST body from turning the ingress into an arbitrary-URL/SSRF proxy.

The endpoint records an idempotency row before network I/O. A successful destination HTTP 2xx response is the only condition that returns `200 ACK status=served`. A destination transport error or non-2xx returns `502 NACK status=failed`; manifest or storage failures return a NACK without claiming delivery. A retry using the same request ID never intentionally dispatches a second time: served requests replay the served ACK, in-progress requests return a conflict, and failed requests require explicit reconciliation before an intentional retry with a new ID. A network timeout can leave the remote outcome ambiguous, so the local failure response must not be interpreted as proof that the destination did no work.

This synchronous ingress is not the multi-member section-wave coordinator. It proves a single destination response, not that all requests in a section wave have completed. Do not represent it as wave-level completion until requests are enrolled in `WaveQueue` and the wave's persisted member states are checked.

## Manifest format

Use JSON for the generated `redist.manifest.json`. Keep the schema conservative and versioned. A proposed shape is:

```json
{
  "version": 1,
  "site": "example.com",
  "defaults": {
    "unmatched": "reject",
    "duplicate_request": "reuse",
    "retry_failed_wave": "manual"
  },
  "sections": {
    "main": {
      "description": "Public website",
      "match": {
        "hosts": ["example.com", "www.example.com"],
        "paths": ["/"]
      },
      "wave": {
        "max_requests": 20,
        "completion": "all_success"
      },
      "evidence": ["application router", "sitemap"],
      "confidence": "confirmed"
    },
    "account": {
      "description": "Account area",
      "match": {
        "hosts": ["account.example.com"]
      },
      "wave": {
        "max_requests": 10,
        "completion": "all_success"
      },
      "evidence": ["deployment configuration"],
      "confidence": "confirmed"
    }
  }
}
```

The example is illustrative. Replace all example domains, paths, evidence, and settings with verified site-specific data. Do not include a section simply because it appears in this example.

If the runtime has no formal schema yet, label fields that are proposals and do not represent them as supported configuration. Do not add credentials, cookies, access tokens, private user data, or secrets to the manifest.

## AI workflow

1. Identify the repository/site root and inspect existing Redist configuration.
2. Build an inventory of evidenced hostnames and high-level route prefixes.
3. Group aliases and routes into candidate sections using the criteria above.
4. Record each section's evidence and confidence.
5. Generate `redist.manifest.json` with stable IDs and explicit matching rules.
6. Validate JSON syntax and check for duplicate IDs, overlapping rules, missing hosts, invalid path prefixes, and absent unmatched-request behavior.
7. Compare the proposed manifest fields with the actual Redist runtime. List unsupported fields and missing runtime behavior separately.
8. Produce a concise report listing: sections found, host/path mappings, evidence, unresolved ambiguities, proposed wave limits, and validation results.
9. Do not deploy or activate the manifest automatically unless the user explicitly authorizes that action.

## Expected report

The AI's final report should include:

- **Sections discovered:** ID, purpose, hostnames, path prefixes.
- **Evidence:** where each mapping was found.
- **Routing conflicts:** any URL that could match more than one section.
- **Unmapped routes:** known hosts/paths not assigned to a section.
- **Assumptions:** especially wave size, completion semantics, and aliases.
- **Runtime compatibility:** which manifest fields Redist currently supports versus which require implementation.
- **Validation:** syntax and structural checks performed.

When evidence is incomplete, provide a reviewable draft and ask only the specific questions needed to resolve material ambiguities. Never fabricate a complete site map from a domain name alone.


## Section-aware behavioral intelligence and adaptive challenges

Redist should evaluate behavior in context. A high-traffic article, search page, leaderboard, product listing, or infinite-scroll feed can legitimately generate far more requests than a quiet section. Do not apply a site-wide request threshold as if every section had the same normal workload.

### Evidence model

The BehavioralIntelligence scorer is an advisory component. It stores section ID, a pseudonymous first-party session key, normalized route key, event type, HTTP outcome, optional dwell time, and timestamp. Do not store raw query strings, page contents, form values, or credentials as route keys. Do not create covert device fingerprints. A random first-party session token can connect that browser's sessions when permitted, but IP addresses alone must not be used to infer that different visitors are the same person.

Use these signals together:

- **Section demand:** compare session request rate with an operator-reviewed baseline for that particular section.
- **Navigation entropy:** measure route variety/repetition, but never treat low entropy alone as malicious; reading one article or scrolling one feed naturally has low route entropy.
- **Cadence and persistence:** examine sustained rate and timing over multiple windows, not a single burst.
- **Engagement:** when the site deliberately supplies click, navigation, scroll, or dwell-time events, use them as supporting evidence only. Missing browser telemetry is not proof of automation; support keyboard use, assistive technology, and privacy settings.
- **Outcomes and impact:** consider non-2xx responses, queue growth, latency, and section-health incidents.
- **Coordinated load:** aggregate section-wide surges and IP-range diversity to identify distributed load. This establishes a traffic pattern, not a shared human identity.

### Decision policy

The scorer returns a risk score, reasons, and one of allow, mitigate, or challenge. It does not block requests itself. A policy layer should:

1. Allow normal or explainable activity.
2. Prefer section-local rate shaping, bounded concurrency, caching, and backpressure for load spikes.
3. Issue a privacy-respecting challenge only when multiple independent signals justify it.
4. Escalate to a stronger challenge or temporary session limit only if suspicious behavior persists.
5. Decay risk after the pattern stops and restore normal access after a recovery window.
6. Never blacklist an entire IP range solely because it is busy; carrier NATs, shared networks, and changing addresses make that unsafe.
7. Keep an auditable explanation of each action and a way to clear false positives.

Entropy is not a fate score: a high or low value is merely one feature. The same repetitive behavior can be ordinary for one section and suspicious for another, depending on baseline, timing, outcomes, and service impact.

### Operational limits and rollout

- Current scorer storage is opt-in; the existing request path does not yet supply a complete, manifest-resolved section ID and trustworthy browser engagement events automatically.
- Do not enable automatic challenges from the score until the application integrates event collection, manifest section resolution, section-specific baselines, retention/pruning, and an operator review panel.
- Use a short rolling raw-event retention window, keep aggregate baselines separately, and disclose any first-party behavior telemetry in the site's privacy notice as appropriate.
- Test legitimate heavy users, infinite scroll, article reading, keyboard/assistive navigation, search crawlers where permitted, distributed scraping, and recovery after load ceases.
- Treat missing telemetry as unknown, not suspicious. Keep an explicit observe-only mode during rollout.


## Runtime queue release policy

The root `index.php` endpoint is a durable queue ingress. It validates the envelope and section destination, stores the request as `queued`, and returns HTTP 202 with an admission ACK. A 202 response does **not** mean the destination has served the request. Only a destination 2xx result recorded by the dispatcher changes a queue entry to `served`.

The dispatcher releases a batch when either condition is true:

- **Threshold:** queued count is at least `REDIST_WAVE_THRESHOLD` (default 10). Ingress checks this after each admission, so crossing the threshold triggers a release without waiting for the timer.
- **Timer:** the oldest queued item has waited at least `REDIST_QUEUE_RELEASE_SECONDS` (default 5). This condition is checked by both ingress and the CLI worker.

A real timer requires the worker to be scheduled independently of incoming traffic. Configure hosting cron to run:

```sh
php /absolute/path/to/Redist/bin/dispatch-queue.php
```

Run it at the cadence appropriate for the hosting scheduler. Standard cPanel cron generally has one-minute granularity, so with that scheduler the practical maximum timer delay is approximately one minute plus processing time, regardless of the 5-second age threshold. A continuously running worker can provide finer timer precision if the host supports supervised long-running processes.

Only one wave proceeds at a time. A wave completes only when every member receives a 2xx response; transport errors and non-2xx responses are marked failed and the wave becomes blocked for review. A blocked wave is not silently treated as successful and requires operational reconciliation before subsequent waves proceed.

Manifest example (replace the example hostname with a destination you own and intend to route through Redist):

```json
{
  "version": 1,
  "sections": {
    "public": {
      "target_origin": "https://www.example.com",
      "description": "Public website"
    }
  }
}
```

Do not use the example hostname as a production destination without replacing it.


## Unknown-section health probes

The scheduled `bin/dispatch-queue.php` worker also checks section health. When a section has no recent observed traffic/response, its health is `unknown`; the worker sends a lightweight GET to the configured `target_origin` at most once every 300 seconds. The probe uses only the manifest origin, follows no redirects, validates TLS certificates, and has a 5-second connect / 10-second total timeout.

A 2xx probe records the section as healthy. A non-2xx response, timeout, invalid destination, or unavailable cURL is recorded as a failed observation; the section is not marked healthy. Unknown, suspected-down, and down sections are probed again after the five-minute interval so a later successful response can record recovery. Healthy sections are not probed continuously; normal health becomes unknown again if its observations become stale under `stale_after_minutes`.

The five-minute schedule depends on the CLI worker being run independently of traffic. On cPanel, schedule `php /absolute/path/to/Redist/bin/dispatch-queue.php` with cron; one-minute cron granularity means probes happen on the first worker run after the five-minute interval has elapsed.

## Automatic soft IP throttling

The dispatch ingress applies a soft priority penalty when one source address approaches the configured rolling request-rate threshold. It does not ban the address and does not drop admitted requests.

- `REDIST_IP_RATE_LIMIT_PER_MINUTE` defaults to 60 new dispatch admissions in a rolling 60-second window.
- `REDIST_IP_DEFER_RATIO` defaults to 0.75. At 75% of the configured limit, the request is marked for deferred priority.
- A deferred request records `defer_until_wave` as the next wave ID. Ordinary eligible traffic is allowed to form that wave first; the deferred request becomes eligible after that wave completes.
- If no ordinary traffic arrives, the timer may release a deferred-only queue after `REDIST_QUEUE_RELEASE_SECONDS` so low-volume traffic does not deadlock.
- The rate count is shared across manifest sections, but uses a keyed HMAC pseudonym. Raw IP addresses are not stored in the reputation-event table.
- The ingress uses the web server's `REMOTE_ADDR`; it deliberately does not trust arbitrary `X-Forwarded-For` headers. If Redist sits behind a reverse proxy, configure the web server to set a trustworthy client address before enabling this policy.
- If the reputation recorder is unavailable, admission fails open to normal queue priority and logs the issue. It does not reject the request.
- This is a heuristic rate guard, not machine learning and not proof that a source is a bot. Shared NATs and legitimate high-volume clients can trigger it. Tune the limit for the site and monitor the resulting deferrals.

The response from a newly admitted request includes `priority` and a small `ip_rate` summary. These fields are operational feedback, not an identity or abuse verdict.


## Overwrite-protected atomic file creation

Use `Redist\files\AtomicFile::writeOnce($destination, $contents, $logger, $mode)` when Redist needs to create a text/configuration artifact that must never replace an existing file. The helper writes to a temporary file in the destination directory, flushes it, applies the requested permissions, and uses an atomic hard-link install. If the destination already exists (including a symlink), it returns `already_exists` and leaves that destination unchanged. It never falls back to `rename()`, because rename can overwrite a destination on Unix.

The result includes `ok`, `code`, `path`, `bytes`, and a safe diagnostic `message`. An optional logger callback receives `(level, event, context)`; file contents are never included. Logger failures are caught and do not change the write result. The default mode is `0600`; callers may request a narrower permission mode such as `0640` when another trusted process needs read access.

The destination directory must already exist, be writable by the caller, be trusted against untrusted directory writers, and support hard links. The helper does not create directories, does not overwrite, and reports errors instead of silently falling back to a less-safe operation. This is an overwrite guard, not a substitute for directory ownership/permissions, signature verification, or safe path selection. The CI smoke test is `tests/atomic_file_smoke.php`.


## Isolated staging vault ("threshing floor")

The staging vault is a separate runtime directory, not a directory committed to or served from the application checkout. Prepare it with `bin/prepare-vault.sh` from a trusted deployment shell:

```sh
REDIST_VAULT_ROOT=/home/ACCOUNT/private/redist-vault bash bin/prepare-vault.sh
```

Replace the example path with an absolute path that is outside both the application checkout and the web document root. The script refuses a path inside the checkout, creates the layout, and applies conservative directory modes. It deliberately does not change owner/group or pretend that Unix mode bits alone create separate identities.

Layout:

- `incoming/`: private intake area; mode `0700`, never web-served.
- `verified/`: validated artifacts; publisher-owned directory, readable by an explicitly authorized reader group.
- `published/`: immutable/approved artifacts; request handlers receive read/traverse access only.
- `quarantine/`: rejected or expired artifacts; mode `0700`, not served.
- `index/`: minimal generation/pointer metadata; only the publisher writes it.
- `logs/`: operational diagnostics, with no private keys, plaintext token values, or artifact contents.

The expected flow is source/GPG output -> `incoming/` -> signature and policy verification -> `verified/` -> controlled publication to `published/`. Rejected files go to `quarantine/`. Always copy or generate into staging; never change canonical source files in place. Keep private signing keys outside the vault and outside the web root. GPG verification keys/keyrings should be controlled separately from writable intake.

**Important privilege boundary:** run the setup as a dedicated vault/publisher account. Add the PHP request account only to a read-only group for the specific files/directories it needs. It must not own the vault or have write permission on the vault root, parent directories, `incoming/`, `verified/`, `published/`, or `index/`. The publisher must be the only writer; the request handler should not be able to replace a read-only file by renaming a new file over it. If PHP and the publisher run under the same Unix account, the directory layout is organizational isolation, not a strong security boundary.

The script only prepares directories and modes. Ownership, group membership/ACLs, GPG configuration, publication policy, and token validation remain explicit deployment/application steps. It does not claim to implement link-token lifecycle or to validate/publish files automatically.
