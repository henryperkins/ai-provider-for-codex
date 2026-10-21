# Local release gate

GitHub Actions and billing resolution are outside this repository's release qualification. Use saved local evidence. A passing fixture, package build, or startup smoke test does not establish authenticated generation or deployment stability.

## Audited upstream baseline (2026-10-11)

| Dependency | Stable target | Exact upstream commit |
| --- | --- | --- |
| WordPress AI | 1.4.0 | e44ed4956da2ab8a419024818cb3fcf08e77a31f |
| PHP AI Client | 1.5.0 standalone; Core's bundled SDK checked separately | 77a59957763d550a9c92025a15bf565048b977b5 |
| MCP Adapter | 0.7.0 | 54ed266a8c46b71fdb8e5b8a7d35668f90fb8994 |
| Codex CLI | 0.162.1 | 092d3acd6bec3e3a14bdc7e7a2810ab628ab759d |

Codex 0.163.0 passed the credential-free startup/protocol smoke on October 21, 2026, but has not received full release qualification or the tool-surface probe. The qualified pin remains 0.162.1. Recheck available upstream releases before the next qualification; these pins describe an audited baseline rather than a permanent latest-version claim.

## Local checks

1. Preserve the source revision, full patch including new files, and a manifest of source hashes. Record tool versions, container image digests, dependency locks and every command's actual exit code.
2. Run Composer validation, PHPStan, and `scripts/verify.sh` on WordPress 7.0 and 7.1 with PHP 7.4 and 8.3. Check Core's bundled SDK without loading a second SDK globally.
3. Repeat integration with AI 1.4 and MCP 0.7. Use WordPress 7.0.3 or newer for that stack. Run the focused SDK contracts with both the baseline SDK and standalone 1.5.0.
4. Exercise the default logging sink against a real disposable database: persisted success and error entries, disabled logging, failed INSERTs, and duplicate counts. Keep the bridge until the upstream replacement meets these contracts.
5. Run checksum-verified stable Codex startup/protocol checks without credentials or inference. Cover terminal status, streamed output recovery on ephemeral threads, capability errors, quota/reset failures, and complete-turn usage with deterministic regressions. Save preview results separately.
6. Run `scripts/package-release.sh`, inspect the exact ZIP against source and exclusions, record SHA-256, and install/activate that ZIP on a disposable site. Run release-style Plugin Check and document any remaining findings.
7. For sidecar isolation changes or a Codex version bump, also run the [offline tool-surface probe](../sidecar/scripts/offline-tool-surface-probe/README.md). Qualify model-facing and nested code-mode tool names, policy echoes and absence of Apps/Plugins traffic. Its mock sends no tool calls: image-thread surface checks and fixture-based image delivery must be reported separately from real Codex image-tool execution.

## Intended-host gate

Confirm the intended deployment category before deciding on authentication migration. Select the actual host and service configuration, then validate sidecar startup, loopback/bearer restrictions, per-user isolation, real device-code login, forced refresh, and bounded authorized text/image generation. Record the installed package checksum and Codex version. Do not store credentials in evidence.

Publication follows successful qualification and explicit release authorization. A local candidate is not a published release.
