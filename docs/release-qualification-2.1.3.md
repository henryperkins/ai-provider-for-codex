# v2.1.3 local release qualification

Qualified on October 11, 2026, from v2.1.2 commit `2c0359165724780acecad92d3b60c3f33da76f79` plus the timeout fix and synchronized 2.1.3 version metadata. The complete source patch, source hashes, command exit codes, dependency locks, protocol schemas, logs and package manifest were saved before publication. The user explicitly authorized qualification, commit, push and publication of v2.1.3.

WordPress AI's standard 90-second image-generation budget becomes 360 seconds only when the effective first image preference is `codex/codex-image`. Nondefault feature budgets, other preferred providers and explicit SDK request options retain their limits. A later `wpai_default_request_timeout` site policy can retain 90 seconds. See [image request budgets](image-generation-timeouts.md).

## Complete local matrix

| Configuration | PHP 7.4.33 | PHP 8.3.35 |
| --- | --- | --- |
| WordPress 7.0, Core SDK 1.3.1 | Pass | Pass |
| WordPress 7.1, Core SDK 1.3.1 | Pass | Pass |
| WordPress 7.0.3 + AI 1.4.0 + MCP Adapter 0.7.0 | Pass | Pass |
| WordPress 7.1 + AI 1.4.0 + MCP Adapter 0.7.0 | Pass | Pass |

All eight final configurations ran the complete `scripts/verify.sh` with actual exit 0 and no omitted steps. Each run includes the PHP syntax and release consistency checks, request-log writer modes with 57/53/53 assertions, runtime contracts with 36 assertions, baseline SDK contracts with 32 cases / 53 assertions, 26 JavaScript tests, 54 Python unittest cases plus five usage assertions, and the WordPress integration script.

Composer strict validation, locked dependency installation and fresh PHPStan analysis passed on both PHP versions. Standalone SDK 1.5.0 contracts passed 32 cases / 53 assertions and runtime contracts passed 36 assertions on each PHP version, without replacing any site's Core SDK. Fresh SDK 1.5.0 PHPStan analysis also passed in an isolated copy on PHP 8.3.35. The canonical dependency lock remained unchanged.

Every latest-stack matrix configuration passed the 11 persisted request-log assertions against a real disposable MySQL database. Those checks cover success and error persistence, disabled logging, failed INSERTs and duplicate counts, with four intercepted consumer calls per run. The provider logging bridge remains enabled.

## Codex and consuming-feature checks

Official Codex 0.162.1 was downloaded and its archive SHA-256 verified against the official GitHub release asset digest. The real startup/protocol smoke passed eight checks in a network-disabled container with isolated credential-free storage. No login or inference was attempted.

The generated 0.162.1 schemas validated 138 requests produced by all 31 generation-contract regression cases, with zero undeclared request fields. These cases cover terminal turn failures and interruptions, ephemeral output recovery, image capability and quota causes, successful image retry, JSON output and complete-turn usage. This is fixture/protocol evidence, not successful authenticated generation.

The installed release ZIP also passed the real WordPress AI image ability and MCP consumer checks on both PHP versions: 17 assertions, four inert image HTTP calls and zero live requests per run. The actual image ability forwards 360 seconds by default and preserves a later 90-second site policy. Both MCP 2025-11-25 and 2026-07-28 were exercised through the real wire orchestrator, preserving actor identity and the feature budget. An unapproved MCP consumer made no runtime request.

MCP uses an explicitly public qualification wrapper that delegates to the real AI image ability. The AI plugin's stock image ability retains its private MCP metadata; the release does not expose new public abilities. These checks exercise the in-process STDIO wire boundary, not a deployed HTTP gateway.

## Qualified package

- File: `scriptorium-ai-provider-for-codex-2.1.3.zip`
- Size: 122,893 bytes
- Files: 48, with no duplicate paths
- SHA-256: `76361af1740174d5fc96b35a9f027ec8e2ab531ef69a99ce6a0d8383ca7b6394`

Every packaged file matches the qualified source bytes. The runtime file set matches the v2.1.2 release; development tooling, tests, internal documents and local artifacts remain excluded. The exact ZIP installed and activated as a regular directory on disposable WordPress 7.1 / PHP 8.3.35 / AI 1.4.0 / MCP Adapter 0.7.0. All 48 installed file hashes match the ZIP. The installed package passed integration and 11 persisted logging assertions. Plugin Check 2.1.0 reported no errors for both the installed ZIP and the development tree with release exclusions.

## Saved setup and failed-run evidence

The initial WP-CLI tar extraction truncated long bundled SDK filenames. Re-extraction from official WordPress ZIPs preserved complete paths, and all four matrix sites plus the package site passed official Core checksum verification. AI 1.4.0 and Plugin Check also passed WordPress.org plugin checksum verification.

The first matrix attempt caught a diagnostics assertion still expecting 2.1.2 after the version bump. That assertion now expects 2.1.3; the complete eight-configuration matrix then passed. Initial consumer-harness setup failures were retained and resolved by initializing the linked actor before the request, using an explicit public wrapper for the private AI ability, and supplying the wrapper's required description. No timeout, permission or approval assertions were weakened. Failed logs and exit codes remain separate from the final passing qualification.

## Tools and production boundary

The qualification used MySQL 8.4.11, WP-CLI 2.12.0, Composer 2.10.3, Node 24.21.0 and Python 3.14.4. The network-disabled Codex smoke container supplied Python 3.11.2. Exact executable versions and container image digests are in the saved evidence.

GitHub Actions and billing resolution remain outside the release workflow. No production installation, device-code login, forced account refresh or authenticated image generation was performed for v2.1.3. The previously documented v2.1.2 intended-host results remain historical evidence, not fresh v2.1.3 qualification.

The transport remains synchronous. The separate Windows gateway and Cloudflare configuration were not changed. A 360-second WordPress budget does not extend a hosting deadline or Cloudflare's default proxy deadline. Successful live images, full production error/de-duplication coverage, reboot recovery and long-term deployment stability remain unverified. Intended-host checks in [the release gate](local-release-gate.md) still apply before a deployment claim.
