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
