# Upstream compatibility remediation — 2026-10-11

The local 2.1.2 candidate addresses all eleven retained audit findings. It is based on main `131102572160dda58cc436d814430093af91f3e7` in an isolated worktree. The original October 11 audit and its six WordPress/SDK findings remain unchanged; the five Codex findings are retained in that audit's extension. GitHub Actions is permanently outside the qualification workflow.

## Audited targets

| Dependency | Version | Exact release commit |
| --- | --- | --- |
| WordPress AI | 1.4.0 | `e44ed4956da2ab8a419024818cb3fcf08e77a31f` |
| PHP AI Client | 1.5.0 standalone; Core SDK 1.3.1 separately | `77a59957763d550a9c92025a15bf565048b977b5` |
| MCP Adapter | 0.7.0 | `54ed266a8c46b71fdb8e5b8a7d35668f90fb8994` |
| Codex stable | 0.162.1 | `092d3acd6bec3e3a14bdc7e7a2810ab628ab759d` |
| Codex preview | 0.163.0-alpha.5 | `cf4041fa09238f33cf106e3046801ed23590bae8` |

Release availability was rechecked on October 11. These are dated qualification targets; the preview is reported separately from stable support.

## Retained findings and resolutions

| Finding | Candidate behavior | Regression evidence |
| --- | --- | --- |
| P1: AI 1.4 removes the global logging option | Uses the active shared manager/public helper, retaining an explicit older-AI fallback | Real AI 1.4 database with the retired option absent; success/error persistence, disabled logging and duplicate counts |
| P2: failed persistence appears successful | Explicit sink/storage `false` returns false; void custom sinks remain compatible; generation remains best effort | Default sink with faulted INSERT plus current and legacy writer contracts |
| P2: schema-less JSON is advertised but ignored | Sends the internal `json_object` request, adds a JSON-only instruction and validates output in sidecar and PHP | Both SDK versions, plaintext/fences/nonfinite values rejected, valid scalars/arrays/objects retained |
| P2: SDK RequestOptions ignored | Text and image transport honor explicit timeout and redirects, including fractional and zero values | Both SDK versions; explicit options override the provider filter, absent options retain defaults |
| P2: self-approval revocation documentation is inaccurate | Documents AI 1.4's consuming-extension approvals and preserves actionable caller errors | Real AI and MCP consumer approval guards; unapproved consumers make zero runtime calls |
| P3: reasoning-token detail discarded | Maps optional thought tokens as a completion-token subset, preserving unknown and explicit zero | Both SDK versions; total remains input plus output |
| P2: interrupted/failed turns accept partial output | Requires terminal `completed` and no turn error for text and images | Exact Codex schemas plus completed/failed/interrupted/in-progress/malformed fixtures |
| P2: ephemeral history fallback cannot recover output | Keeps ephemeral isolation; reads actual terminal items/notifications; missing output fails | No `thread/read` sent; streamed and terminal recovery fixtures |
| P2: image quota cause/reset discarded | Returns typed HTTP 429 with bounded quota type, limit ID and reset metadata | Schema-valid failure notifications; partial failed images rejected, successful retry retained |
| P2: multi-response usage undercounted | Prefers complete fresh-thread `total`, falling back to `last` when necessary | Different total/last fixture; reasoning detail not added twice |
| P2: `max`/`ultra` silently dropped | Preserves current known efforts; malformed/unknown values fail explicitly before HTTP | PHP model contracts and actual sidecar builders against stable schemas |

Additional fixes preserve capability RPC cause/code, send the declared initialize fields and `initialized` acknowledgement, use the current image tool name, and route transport/invalid-JSON failures through the logging bridge. Diagnostics treats status-zero transport failures as unreachable. Valid JSON numbers too large for Python's float preserve their original text without corrupting the HTTP envelope's JSON. The package cleanup trap now returns success after a successful build outside a Git checkout. Existing `refreshToken` coverage is retained.

The logging bridge remains. Persisted/de-duplication evidence is bounded to the exercised consumer paths, and does not establish an upstream replacement for every provider success/error path.

## Local qualification

| Configuration | PHP 7.4.33 | PHP 8.3.35 |
| --- | --- | --- |
| WordPress 7.0, Core SDK | Pass | Pass |
| WordPress 7.1, Core SDK | Pass | Pass |
| WordPress 7.0.3 + AI 1.4.0 + MCP 0.7.0 | Pass | Pass |
| WordPress 7.1 + AI 1.4.0 + MCP 0.7.0 | Pass | Pass |

All eight configurations ran the complete `scripts/verify.sh`, each with actual exit 0. Composer strict validation, dependency installation and PHPStan passed on both PHP versions. Latest SDK 1.5.0 contracts ran independently on both versions without replacing the site's Core SDK; each SDK run passed 25 cases / 39 assertions. MySQL was 8.4.11, WP-CLI 2.12.0, Node 22.23.2, Python 3.11.13 and Composer 2.8.12. Linux test copies normalize Windows CRLF to LF; binary bytes are unchanged.

Each full gate includes writer modes with 57/53/53 assertions, runtime/diagnostics with 36 assertions, 26 JavaScript tests, 54 Python unittest cases plus 5 usage assertions, syntax checks and the WordPress integration script. Each of the four latest-stack matrix configurations also passed 11 actual database assertions with four intercepted consumer calls and zero live requests.

The pinned stable Codex protocol probe passed 87 semantic checks over 194 requests and 99 schema-validated fixture notifications, with zero undeclared request fields. Fresh real unauthenticated startup checks passed eight checks each on stable 0.162.1 and preview 0.163.0-alpha.5 using checksum-verified binaries, networking disabled and isolated credential-free storage.

MCP qualification passed 40 assertions and all 13 WP-CLI processes exited 0. It exercises both 2025-11-25 and 2026-07-28 through the real wire orchestrator and real AI title ability, bound to all 48 installed files matching the final ZIP. It checks actor/model attribution, unlinked users, permissions, runtime errors and consuming-extension approvals with inert runtime responses. WordPress Core supplies a 30-second SDK timeout by default; the candidate forwards it. The provider's 360-second generation fallback applies when the caller supplies no SDK timeout. A separate SDK connection timeout has no portable WordPress HTTP mapping.

Fresh SDK 1.5.0 static analysis also passed with PHPStan 2.1.46 on PHP 8.3.35 in a separate network-disabled disposable copy. Reflection confirmed the selected SDK, the result cache was cleared, and the baseline lock/vendor hashes remained unchanged.

## Artifact evidence

The audit and remediation packet are saved under `C:\Users\htper\AppData\Local\Temp\codex-provider-compatibility-20261011`. The remediation subdirectory includes red/green logs, per-command exit codes, tools/images, exact protocol schemas/captures, the complete candidate patch including new files, a source archive/manifest, matrix logs and the final package manifest. The original reports are retained separately.

Final package: `scriptorium-ai-provider-for-codex-2.1.2.zip`, 48 files, SHA-256 `bb0ad2b319a8574550dd7cbe6ed20988d1572949c6637a822dcc76947c83fb65`. ZIP paths, contents, duplicate absence and release exclusions pass inspection against the candidate's canonical Linux bytes. That ZIP installed/activated on the disposable WordPress 7.1 / PHP 8.3.35 / AI 1.4.0 / MCP 0.7.0 site. Its integration script and 11 persisted logging assertions passed. Plugin Check 2.1.0 reports no errors. Three initial exception-output sniff findings were resolved with narrow argument annotations for internal status/cause data; final values remain unchanged and display escaping stays at the render boundary. The complete eight-configuration matrix was repeated after that correction, with every exit 0.

The new disposable containers and their dedicated network were removed after evidence was saved. The qualified worktree, source patch/archive, reports and ZIP remain available. The primary main checkout was clean and unchanged during qualification; integration and publication are recorded separately for the v2.1.2 release.

## Intended-host qualification

Subsequent October 11 validation used a personally operated Windows runtime connected to hperkins.blog through a dedicated Cloudflare HTTPS tunnel and bearer-protected loopback gateway. The installed sidecar matches the final package's bytes and uses official Codex 0.162.1. Runtime checks from the actual WordPress host passed: sidecar reachability, bearer matching, Python 3.14.6, Codex version, writable storage and app-server initialization. The user completed device-code approval, and the linked account's explicit refresh succeeded with `refreshToken: true`.

The existing WordPress `ai/title-generation` ability then generated `Codex Tunnel Verified 20261011` through `codex` / `gpt-6.1-sol` in 4,970 ms. Its `ai_client` success log remained after a fresh page reload, with 17,145 input tokens and 12 output tokens. One matching Codex row was visible. The temporary Title Generation provider/model override was restored to its original default, confirmed on a fresh page; no posts were created or changed.

Gateway qualification passed 14 inert security fixtures and eight public HTTPS checks. Runtime/gateway/connector start, duplicate-launch prevention and explicit restart passed; listeners remain on loopback, with privileged routes requiring the matching bearer. Local log checks found neither runtime nor tunnel credentials. This deployment-specific evidence supplements the unchanged packaged runtime and does not expand the documented same-host installation support.

Complete production error-log and de-duplication coverage, live image generation, reboot/sign-in recovery and long-term deployment stability remain unverified. The provider logging bridge stays in place. The user authorized public release of this qualified v2.1.2 artifact; GitHub Actions and billing resolution remain outside the release workflow. PR #4 disposition remains separate.

See [the repeatable local release gate](local-release-gate.md) for the next qualification run.
