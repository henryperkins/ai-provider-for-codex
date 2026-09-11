# Codex Subscription Image Transport Design

**Date:** 2026-09-11

**Goal:** Add an opt-in direct subscription image transport behind the existing `POST /v1/responses/image` interface, removing the application-side reasoning turn from the preferred image path while keeping Codex responsible for ChatGPT login, token refresh, and credential persistence.

**Status:** Design spec. No implementation has been performed. The live-endpoint evidence in "Verified Evidence" was gathered in-session on 2026-09-11; the acceptance gates remain unverified.

## Background And Problem

`sidecar/app/main.py` generates images by starting an ephemeral `codex app-server` thread, prompting a reasoning model to call the `image_generation` tool, then scraping `imageGeneration` notifications with a thread-history fallback (`build_image_generation_prompt`, `generate_image`, `_read_final_image_items`).

That path works but has three costs: it burns reasoning tokens on every image, it depends on a model choosing to call a tool when asked in prose, and it surfaces turn telemetry rather than image-endpoint usage.

A proposal circulated to replace it with a direct call to the ChatGPT Codex Images endpoint, sending `model: gpt-image-2.5-sunburst` to select the image model released 2026-09-08. **The model-selection premise was tested and refuted.** The transport change remains worthwhile for the reasons above; model selection is not among them.

## Decision

Keep one synthetic WordPress image model, `codex-image`. Add an opt-in direct transport behind the unchanged `/v1/responses/image` interface. Retain the app-server transport as the default and compatibility path.

Do not add named image variants (`codex-image-sunburst`, `codex-image-flare`), expose ineffective selectors, switch to API-key billing, or describe this adapter as an officially supported public Image API integration.

Public-facing description: *"Codex subscription image generation. The backend is selected by the service; an exact image model is not selectable or verified."*

## Verified Evidence

### The endpoint works over subscription credentials

`POST https://chatgpt.com/backend-api/codex/images/generations` with `Authorization: Bearer <tokens.access_token>`, `chatgpt-account-id: <tokens.account_id>`, and `originator: codex_cli_rs` returned HTTP 200 with a valid PNG in `data[0].b64_json`. No OpenAI API key. Response envelope: `background`, `created`, `data`, `output_format`, `quality`, `size`, `usage`; item keys `b64_json`, `generation_id`.

Usage is reported in image-endpoint shape:

```json
{"input_tokens": 17,
 "input_tokens_details": {"image_tokens": 0, "text_tokens": 17},
 "output_tokens": 343,
 "output_tokens_details": {"image_tokens": 343, "text_tokens": 0},
 "total_tokens": 360}
```

### The model, quality, and size fields are inert

| Request | Result |
| --- | --- |
| `model: gpt-image-2.5-sunburst` | 200, PNG |
| `model: gpt-image-2.5-nonexistent-xyz` | 200, PNG |
| `model: dall-e-2` | 200, PNG |
| *model omitted entirely* | 200, PNG |
| `quality: "totally-bogus"` | 200, echoed `quality: low` |
| `size: "99x99"` | 200, echoed `1254x1254` |
| `size: "1024x1024"` | 200, echoed `1774x887` |

The decisive test: an identical text-rendering prompt was sent under `gpt-image-2.5-sunburst` and `dall-e-2`. **Both rendered clean, kerned, anti-aliased bold type**, and both returned the same unrequested `1774x887`. This result is inconsistent with literal DALL-E 2 routing and reinforces the conclusion that the supplied model identifier was not honored as a selection control. It does not establish that both requests reached the same model.

This establishes that model, quality, and size are unreliable selection controls **in the tested account and request context**. It does not establish which model actually ran, prove every request used identical weights, or prove these fields can never matter for another account or a future backend. Image appearance and dimensions are not model provenance. This design deliberately depends on none of those stronger claims.

### There is no app-server image method

The deployed binary's own protocol schema (`codex app-server generate-json-schema`) lists 99 client-to-server methods. None are image-related. The only two available transports are the turn-based `image_generation` tool and direct HTTP.

### Explicit gpt-image-2.5 selection is not exposed through the deployed app-server

String inspection of the installed `codex.exe` (codex-cli 0.154.0, built 2026-09-09) finds `gpt-image-2` (49), `gpt-image-1.5` (24), `gpt-image-1-mini` (4), `gpt-image-1` (4), and **zero** `gpt-image-2.5` occurrences. That proves the deployed client exposes no literal `gpt-image-2.5` selection; it cannot rule out server-side routing or aliasing to a 2.5-series backend. The route the proposal described does exist in Codex — `/images/generations` and `/images/edits` are present, alongside `chatgpt-account-id`, `originator`, `b64_json`, `output_format`, `input_fidelity` — contradicting the claim that Codex lacked it.

### Managed-mode token access has exactly one shape

`chatgptAuthTokens/refresh` is a **ServerRequest** — Codex asking an external-auth client for a token, not a way for a client to obtain one. `account/login/start` accepts `chatgptAuthTokens` only for external mode. In managed mode there is no token-export method, so reading the on-disk credential document is the only option, and the credential accessor described below is necessary rather than incidental.

## Prerequisite Defect: account/read Refresh Parameter

`GetAccountParams` in the deployed schema declares exactly one property, `refreshToken` (boolean): *"When `true`, requests a proactive token refresh before returning. In managed auth mode this triggers the normal refresh-token flow. In external auth mode this flag is ignored."*

There is no `refresh` property. `sidecar/app/main.py:465` sends `{"refresh": True}` inside `account_snapshot()` — the method behind `GET /v1/account/snapshot`, which the hourly `codex_provider_refresh_connection_snapshots` cron calls. **The proactive refresh has never fired.** Lines 592 and 709 send `{"refresh": False}`, which is harmless since the intent there is not to refresh.

This is a one-token fix (`refresh` to `refreshToken`) and is independent of this design, but it is a prerequisite: the authentication strategy below depends on being able to force a managed refresh.

## Scope

**In scope**

- One new sidecar transport for `POST /v1/responses/image`, generation only.
- A restricted managed-credential accessor for the file-backed ChatGPT layout.
- Transport selection by deployment configuration, defaulting to existing behavior.
- Failure classification, usage/provenance mapping, and image validation.

**Out of scope**

- The `images/edits` route, masks, multiple requested candidates.
- Public API credentials or any API-key path.
- Image-model selection in any form.
- New credential storage backends, or independently implemented OAuth refresh.
- Text generation, which stays on app-server.
- General sidecar refactoring.

## Alternative Approaches Considered

**Retain app-server only.** Lowest risk, no new token-reading boundary. Retains turn orchestration and turn-shaped telemetry. This remains the default until the gates pass.

**Direct transport with Codex-managed refresh — recommended.** Removes orchestration from the preferred path while leaving the credential lifecycle with Codex. Requires the credential accessor, compatibility checks, and explicit failure classification.

**Direct transport with independently implemented OAuth refresh — excluded.** Creates a second refresh owner and new rotation, storage, and concurrency duties. Nothing demonstrated requires it.

**Named-model public API transport.** The only verified way to select Sunburst is `api.openai.com/v1/images/generations` with an API key. That breaks the plugin's defining constraint that billing is ChatGPT-managed. It is a different product decision, not a fallback for this adapter.

## Application Contract

Preserve the existing WordPress-to-sidecar route, authenticated user resolution, connection checks, request ID, text prompt, optional `systemInstruction`, and image-result DTO contract.

The direct transport accepts a prompt, not a selectable image model. Reject explicit model/quality/size requirements at the provider boundary with a typed unsupported-option error rather than accepting them and silently substituting service defaults. This is additive, not breaking: `CodexImageGenerationModel` currently reads only `getConfig()->getSystemInstruction()`, so those options are already ignored — the change makes the existing silence explicit.

To make that requirement testable, name the inspected surface exactly. Follow the two-tier probe already established by `CodexTextGenerationModel::extract_reasoning_effort()`: first `getConfig()->getCustomOptions()` for the keys `model`, `imageModel`, `image_model`, `quality`, `size`, `aspectRatio`, and `background`; then `is_callable( [ $config, $method ] )` reflection for `getCandidateCount`, `getQuality`, `getSize`, and `getAspectRatio`, which may or may not exist depending on the installed SDK version. A present, non-null value on any of these makes the request direct-ineligible. Absent and null values are not requirements and do not block dispatch.

Omit model, quality, and size from the direct request after validating the minimal request shape against the deployed environment. If a future backend requires a compatibility field, record it as transport metadata, never as proof of the generated model.

**Any non-empty `systemInstruction` makes a request direct-ineligible in v1.** The app-server path sends that value as `developerInstructions` on `thread/start`; the direct endpoint has no verified equivalent channel. Those are not interchangeable, and flattening a privileged developer instruction into prompt text is a silent demotion of its semantics, not a delivery-mode difference. `CodexImageGenerationModel` already unsets an empty or null `systemInstruction` before dispatch, so non-emptiness is a clean, existing discriminator.

Explicit `direct` mode returns a typed incompatibility error for such a request. Do not attempt prompt concatenation, and do not claim it preserves role hierarchy. If non-privileged image-style guidance is wanted later, expose it as its own option rather than reusing the system-instruction channel.

## Components

**Image coordinator.** Validates the request, evaluates policy, owns the overall deadline and attempt state, selects one transport.

**Managed credential accessor.** Obtains a narrowly scoped, short-lived access-token/account-ID snapshot from the correct isolated Codex home; invokes Codex for refresh when needed.

**Direct image transport.** Makes the bounded HTTPS request; normalizes image bytes, server-reported metadata, usage, and errors.

**App-server image transport.** Retains the existing prompt builder, notification loop, and final-thread-image reader unchanged. These helpers leave the direct path but are not deleted: `app-server` remains the default mode and the rollback target, and it is the only transport that can carry a `systemInstruction`.

## Transport Modes

Deployment-controlled, not caller-supplied. **v1 ships exactly two modes:**

- `app-server` — existing behavior; default and rollback mode.
- `direct` — opt-in; never falls back to app-server generation. A request that is ineligible or fails returns a typed error.

There is no `auto` mode in v1. Automatic transport fallback requires an exhaustive classifier that can distinguish a guaranteed pre-generation failure from an ambiguous backend error, and against a private endpoint with no published error contract that classifier cannot be built from available evidence. Guessing wrong means a duplicate generation billed to the user's subscription. A locally ineligible direct request therefore fails before dispatch rather than silently switching transport.

Recovery from a route that disappears is an administrative action, not an automatic one: the typed error names the transport, and an administrator returns the deployment to `app-server`. Because mode is deployment-level configuration, that is a settings change rather than a code change.

Add `auto` in a later release only if deterministic pre-dispatch failure classes become observable. These are proposed adapter settings, not existing Codex configuration keys. Direct transport stays disabled by default until the acceptance gates pass.

## Authentication Ownership And Concurrency

Codex remains the only OAuth refresh and persistence owner. The sidecar must not POST refresh tokens to an OAuth endpoint, write rotated credentials, derive token validity from `last_refresh` alone, or fall back to another user's home.

Managed refresh is `account/read` with `{"refreshToken": true}` — verified against the deployed schema, and subject to the prerequisite fix above. A successful `account/read` reports account state; it is not a token-export endpoint.

For direct mode, support only the file-backed managed-ChatGPT layout validated by this release: `${CODEX_WP_STORAGE_ROOT}/users/<wp_user_id>/auth.json`, reading only `tokens.access_token` and `tokens.account_id` into a dedicated secret-bearing object. Verify active auth mode and provider first; file existence is not proof of valid authentication. Other storage or auth modes are direct-ineligible. Do not force keyring users into plaintext storage, and do not claim this change adds keyring support.

On a recognized authentication rejection, request one Codex-managed refresh, reload persisted credentials, and retry the direct request at most once within the original deadline. Verify the account binding did not change; if it did, stop rather than charging another account.

### Concurrency State Machine

"Serialize auth-sensitive operations" is too coarse to implement. A lock spanning whole generations would let one five-minute image block every text request for that user; a lock covering only credential reads would let a logout rebind the account mid-flight. Define three pieces instead.

**1. A short auth-mutation mutex, per Codex home.** It guards credential mutations (login completion, managed refresh, logout) and credential snapshot reads. It is held for milliseconds and **never across a generation HTTP call, an app-server turn, or any network wait**. Different users never contend. Waiting on it is bounded by the operation budget.

**2. A per-home auth generation counter.** A monotonic integer incremented on every completed credential mutation. It is the only mechanism for detecting rebinding, because Codex's own app-server sessions can refresh credentials outside this mutex — so freshness must be checked after the fact rather than assumed from having held a lock.

**3. Operation flow.** Acquire the mutex; read `tokens.access_token`, `tokens.account_id`, and the current generation into a short-lived snapshot; release the mutex; dispatch. On a 401, re-acquire the mutex, and refresh only if the generation still matches the snapshot — otherwise another operation already refreshed, so reload and retry with the new credentials without issuing a second refresh. This is what prevents a thundering herd of refreshes when several requests hit 401 together.

**Logout or relogin while a generation is already dispatched.** Deleting `auth.json` cannot recall a request the backend has already accepted, and the user's subscription quota is consumed whether or not the sidecar keeps the result. On completion, re-read the generation under the mutex; if it changed, **return the image to the caller that requested it, marked with `authGenerationChanged: true`**, and do not write it into any per-user cache or snapshot keyed to the new generation.

Returning rather than discarding is deliberate: the request was legitimately authorized at dispatch, it has already been billed, and discarding it silently would waste the user's allotment while telling them nothing. The marker is what lets callers and the request log distinguish this case. Never resurrect deleted credentials to complete or retry such an operation.

The shipped `systemd` unit runs a single sidecar process; that is the supported deployment. A threading lock does not coordinate across processes, so multi-process deployments sharing a storage root are unsupported and should be documented as such rather than partially defended.

Use safe file handling, restricted permissions, bounded reads, and non-secret parse errors. Never copy the auth document, tokens, or authorization headers into PHP, responses, request previews, traces, exception payloads, or fixtures.

## Eligibility And Policy Parity

Direct HTTP must not become a way around account, provider, feature, or workspace restrictions. Preserve WordPress Connector Approval and logged-in-user checks on the unchanged loopback boundary. Verify the active Codex provider and managed ChatGPT account.

**Preserve the live capability probe.** `generate_image()` currently calls `modelProvider/capabilities/read` and rejects with `image_generation_unavailable` when `imageGeneration` is not true, before starting any turn. The direct path must keep that probe before credential-bearing dispatch. Dropping it because direct HTTP does not technically require it would make the transport switch quietly weaken an existing gate.

**Capability is not entitlement, and the spec should stop implying otherwise.** The probe reports what the provider supports, not whether this account and plan may generate. Codex exposes no reliable per-account image-entitlement field, so no local check can be authoritative. The rule is therefore explicit: a true capability result authorizes *attempting* the operation; the direct endpoint's typed denial remains the authoritative answer on entitlement, and is surfaced as such rather than retried or reinterpreted. Include plan and account-class variation in the acceptance matrix, since that denial is the only place the distinction becomes observable.

Direct mode is eligible only when the adapter preserves the configured endpoint, workspace binding, credential-storage policy, TLS/proxy requirements, and applicable image restrictions. Unsupported policy configurations make direct mode unavailable; they do not justify bypassing policy.

`originator: codex_cli_rs` is a recorded probe condition, not proof the header is required or that arbitrary callers are supported. Pin and document tested header behavior. Never change identity headers to work around a permission denial.

Use a fixed approved HTTPS destination. Do not accept a caller-controlled URL, follow redirects carrying credentials, or ignore an administrator's routing or residency requirement.

## Failure States And Fallback

Track each attempt as `not_dispatched`, `rejected`, `succeeded`, or `outcome_unknown`. A request ID is correlation metadata, not proof of backend idempotency.

| Condition | Required behavior |
| --- | --- |
| Direct disabled, or request locally ineligible before dispatch | Typed unavailable/incompatibility error. No transport switch. |
| Recognized authentication rejection | Refresh through Codex once, retry direct once within the original budget. Repeated rejection stops; no app-server generation fallback. |
| Permission, workspace, content-policy, or quota rejection | Return the typed denial. Do not try another path to get around it. |
| Route unavailable (404/410/HTML error page) | Typed error naming the transport. No app-server retry; an administrator returns the deployment to `app-server`. |
| Read timeout, reset after transmission, ambiguous 5xx, cancellation after dispatch, malformed success body | Mark outcome unknown. Do not regenerate or fall back. |
| Valid image followed by sidecar ancillary failure (rate-limit or account read) | Return the validated image with warning metadata. Do not generate again. |
| Valid image followed by PHP request-logging failure | Return the validated image unchanged. Logging stays best-effort and silent (see below). |

Default any unclassified failure to no automatic replay. Never fall back because output appearance, size, or reported quality differs from a preference. Do not assume an undocumented idempotency header makes retries safe.

Reject overlapping duplicate request IDs within a user's active operations. This is not an exactly-once guarantee across crashes. Expose ambiguous outcomes so callers do not blindly retry them.

**The sidecar deadline is derived from the caller's effective timeout, not configured independently.** A fixed inner default is unsafe here: `Client::request_timeout()` starts at `TEXT_GENERATION_TIMEOUT` (360s) for `/v1/responses/` paths, then passes it through the `codex_provider_runtime_request_timeout` filter and returns `max( 1, $timeout )`. A deployment can therefore lower the PHP timeout to 120 seconds while a fixed 300-second sidecar budget keeps generating. The PHP caller gives up, the image is still produced and billed, and that is precisely the `outcome_unknown` state this design exists to contain.

PHP must send its effective post-filter timeout as an explicit budget field on the `/v1/responses/image` request. The sidecar derives one operation deadline from that budget, minus a fixed processing reserve for base64 decoding, image validation, and response serialization. Every phase — credential coordination, auth refresh, the HTTPS request, and response processing — consumes that same budget, and the direct HTTP timeout is set strictly shorter than the remaining budget so a caller-side timeout cannot precede a server-side one.

If the budget field is absent (an older PHP caller against a newer sidecar), fall back to a conservative floor rather than the historical 360-second assumption, and record which source supplied the deadline. An auth retry must fit inside the remaining budget: it must not reset the clock, and no phase may start when the remaining budget is smaller than its own reserve.

## Response, Provenance, And Usage

Keep `model: codex-image` as the provider abstraction. Leave `runtimeModel` unset unless a trustworthy response contract identifies the generating model. Never populate it from a request field, a synthetic alias, image quality, dimensions, or a client constant.

Add explicitly mapped metadata to the sidecar response and the PHP result's additional data:

- transport used, logical request ID, attempt count, fallback reason, outcome classification
- backend request/generation IDs when supplied, kept distinct from local IDs
- actual decoded image dimensions and MIME type
- separately labeled server-reported quality, size, and any reported model string
- usage source, completeness, and validated token breakdown
- adapter contract version, Codex version, observation time, ancillary warnings

A server-echoed model string is reported metadata, not verified provenance. Actual dimensions come from the decoded image, not from a request or response label.

Map `input_tokens` and `output_tokens` to the existing flat counters when present and valid. Preserve `input_tokens_details`, `output_tokens_details`, and reported totals separately through an allowlisted mapping. Image-token subtotals are components, not additional tokens. Unknown stays unknown; an absent usage object is not evidence of zero usage.

`ResponseMapper::to_image_generative_ai_result()` currently computes `(int) ( $payload['usage']['inputTokens'] ?? 0 )` and constructs `TokenUsage( $in, $out, $in + $out )`, so absent counters already read as zero. Where compatibility requires that placeholder, include explicit `usageKnown: false` metadata and omit unobserved log counters. `additional_data` is built by explicit assignment (`revisedPrompt`, `artifacts`, `runtimeModel`), so new sidecar metadata will not reach callers unless mapped deliberately.

`RequestLogWriter::build_entry()` constructs a limited context by explicit assignment. Add only allowlisted image fields and verify persistence through the actual WordPress AI log sink. Never dump a raw response into log context. Keep one logical generation entry carrying attempt metadata, with the same request ID on success and failure, so upstream attempt diagnostics do not appear as multiple successful generations.

**Request logging stays best-effort and silent; this design does not change that contract.** `RequestLogWriter::record()` wraps the sink call in `try { ... } catch ( \Throwable $e )` and returns a boolean rather than propagating, and the plugin's stated invariant is that log writes never throw into generation. Warning metadata on a successful image therefore covers *sidecar-side* ancillary failures only — a failed `account/rateLimits/read` or `account/read` after a valid image. A PHP-side logging failure has no path to the response by construction and must not be given one: the earlier "logging failure produces warning metadata" phrasing described a layering that does not exist, since logging happens in PHP after the sidecar has already returned.

## Image Validation And Sensitive Output

Treat responses as untrusted input. Enforce explicit limits on response bytes, decompressed JSON, decoded image bytes, pixel count, and processing time. Validate JSON shape, strict base64, PNG signature and decodability, and dimensions before returning success. Preserve original valid image bytes rather than re-encoding and potentially stripping metadata.

Keep image binaries and base64 out of logs. Preserve the one-candidate external contract: if the backend returns more than one image, record the observed count and deterministically return the first valid candidate without initiating another generation. If no candidate is valid, report an invalid/unknown outcome rather than retrying.

Do not fetch arbitrary image URLs returned by the service. The verified path is inline `data[0].b64_json`.

## Performance And Backend Drift

The direct success path must make no app-server image-generation turn. It may still use app-server control operations for authentication and account metadata. This removes application-side orchestration; it does not establish that the backend performs no reasoning internally.

Measure end-to-end latency including auth and coordination, with paired representative prompts. Report median and tail latency, failure rate, refresh frequency, and fallback frequency. Do not advertise a speed or consumption gain from architectural inference alone.

Record response-contract versions and observable metadata. Use controlled regression prompts to monitor practical output changes, but do not identify models from visual fingerprints. Because the service exposes no trustworthy model identity, a silent backend swap may be undetectable — state that limitation in diagnostics and in user-facing capability text.

## Testing And Acceptance Gates

All gates are unverified for the proposed implementation.

**Contract and identity.** Capture sanitized live fixtures with the exact deployed binary and request headers. Validate the minimal request. Preserve unknown model identity. Fixtures contain no secrets and no base64 dumps.

**Eligibility.** Assert every named selector (`getCustomOptions()` keys and the reflective getters) makes a request direct-ineligible when present and non-null, and does not when absent or null. Assert any non-empty `systemInstruction` is direct-ineligible and returns a typed incompatibility error under `direct`. Assert the `modelProvider/capabilities/read` probe still runs before credential-bearing dispatch, and that a false result rejects before any token is read. Cover plan and account-class variation, since the endpoint's typed denial is the only authoritative entitlement signal.

**Deadline propagation.** Assert the sidecar derives its budget from the caller-supplied value and not from a fixed constant: filter `codex_provider_runtime_request_timeout` down to well below the default, and prove the sidecar's own deadline and HTTP timeout move with it and stay strictly inside it. Cover an absent budget field falling back to the conservative floor, and an auth retry that must fit in the remaining budget rather than resetting it.

**Refresh, isolation, and concurrency.** Test valid, expired, rotated, revoked, malformed, missing, and account-mismatched credentials; unsupported storage modes; different-user isolation. Assert the auth-mutation mutex is never held across a generation — a long image must not block that user's text requests. Assert concurrent 401s produce exactly one managed refresh, not one per request. Assert logout mid-flight returns the image marked `authGenerationChanged` and writes nothing to the new generation's cache. Demonstrate Codex remains the only refresh writer.

**Fallback safety.** Inject every failure class, including timeout after server acceptance. Assert no automatic second generation for ambiguous outcomes, denials, or quota exhaustion, and that no failure class switches transport in v1.

**Mapping and logging.** Prove image bytes, dimensions, usage provenance, unknown values, request IDs, attempts, and warnings survive the Python/PHP/DTO/logging boundary, including error paths and actual log-sink verification.

**Security and bounds.** Test redirects, mismatched account binding, unsupported policy configuration, oversized and malformed bodies, invalid images, timeout propagation, and secret redaction.

**Compatibility and rollback.** Show the app-server path still works, the transport switch changes neither billing provider nor model identity, and ancillary failures never trigger regeneration. Measure latency before claiming a gain.

Extend `scripts/verify.php` for the PHP boundary and add sidecar unit coverage alongside `sidecar/scripts/test-image-generation.py`.

## Rollout

Ship the `refresh` to `refreshToken` fix first, separately, with its own test. The authentication strategy above depends on forced managed refresh actually working.

Then ship `app-server` (default) plus opt-in `direct`. Retain `app-server` as default until the gates pass. Rollback changes transport for new operations; it does not replay an operation whose outcome is unknown.

`auto` is deliberately out of v1. `app-server` plus `direct` delivers the entire orchestration win, while `auto` is the only part that requires an exhaustive route-unavailability classifier — and getting that classification wrong bills the user for a duplicate generation. Revisit it only with deterministic pre-dispatch failure classes in hand.

## Evidence Register

**[P1]** In-session live probe, 2026-09-11, against `https://chatgpt.com/backend-api/codex/images/generations` using the local `~/.codex/auth.json` managed credentials with `codex-cli 0.154.0`. Eight generation requests across varied `model`, `quality`, and `size` values, the last two being a paired text-rendering discriminator. Access token verified unexpired via its `exp` claim before probing. Throwaway scripts and artifacts were written to a session scratchpad outside the repository; no token or base64 payload was logged.

**[S1]** Installed binary string inspection: `codex.exe`, codex-cli 0.154.0, built 2026-09-09. Establishes the `images/generations` and `images/edits` paths and the absence of `gpt-image-2.5`.

**[S2]** Deployed protocol schema via `codex app-server generate-json-schema`. Establishes `GetAccountParams.refreshToken`, the 99-method client surface with no image method, and `chatgptAuthTokens/refresh` as a ServerRequest.

**[S3]** `sidecar/app/main.py` — image orchestration (`generate_image`, `build_image_generation_prompt`, `_read_final_image_items`), credential handling (`auth_file_path`, `clear_auth_json`), and the `account/read` parameter defect at line 465.

**[S4]** `src/Models/CodexImageGenerationModel.php` — call boundary; reads only `getSystemInstruction()` from config.

**[S5]** `src/Runtime/ResponseMapper.php` — `TokenUsage` aggregates, zero-defaulted counters, explicit `additional_data` mapping.

**[S6]** `src/Logging/RequestLogWriter.php` — explicit log context construction.

**[S7]** `src/Runtime/Client.php` — `DEFAULT_TIMEOUT = 20`, `TEXT_GENERATION_TIMEOUT = 360`, `codex_provider_runtime_request_timeout` filter, Connector Approval handling.

**[S8]** Official Sunburst model documentation, explicit public API model selection, retrieved 2026-09-11: https://developers.openai.com/api/docs/models/gpt-image-2.5-sunburst
