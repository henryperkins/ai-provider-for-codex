# WordPress.org Submission Readiness Checklist

This checklist is specific to `ai-provider-for-codex`.

Use it before submitting a zip to the WordPress.org plugin directory and again before publishing the first approved SVN release.

Last reviewed: 2026-04-04

## Current Status Summary

- `[pass]` Header version and readme stable tag currently match: `0.1.0`
- `[pass]` `Requires at least` and `Requires PHP` are aligned between the main plugin file and `readme.txt`
- `[pass]` Local verification script currently passes
- `[pass]` Plugin Check has been run locally against the release-style file set and currently reports no findings
- `[pass]` A release-specific packaging step now builds a submission zip that excludes dev-only and local artifact files
- `[pass]` The public readme now documents the external service/runtime requirement, Terms of Use, privacy, and data handling
- `[pass]` A standalone GPL-2.0-or-later LICENSE file is present in the project root
- `[pass]` The release zip includes `sidecar/` so the readme installation instructions work from the installed plugin directory
- `[needs decision]` The plugin name and slug still reference `Codex`, which may create trademark or naming-review risk
- `[verify at submission time]` `Tested up to: 7.0` is reasonable right now, but it must be re-checked against the current stable or release-candidate WordPress version on submission day

## 1. WordPress.org Account And Ownership

- [ ] The submitting WordPress.org account has 2FA enabled.
- [ ] If the plugin is owned by a company, the submitting account uses an official company-domain email.
- [ ] `plugins@wordpress.org` is whitelisted so review mail is not missed.
- [ ] The `Contributors:` header in [`readme.txt`](./readme.txt) uses valid WordPress.org usernames only.
- [ ] Every future committer who will get SVN access also has 2FA enabled.

Repo notes:

- `Contributors: lakefrontdigital` is present in [`readme.txt`](./readme.txt). Verify that this is the exact WordPress.org username that should own the plugin.

## 2. Name, Slug, And Trademark Risk

- [ ] The final plugin display name has been approved internally.
- [ ] The expected slug has been checked for trademark and naming issues.
- [ ] The plugin does not begin its slug with a third-party trademark unless you can prove legal ownership or representation.
- [ ] If review requires a slug change, update the text domain and all slug-specific identifiers before resubmitting.

Repo notes:

- The current display name is `AI Provider for Codex` in [`plugin.php`](./plugin.php).
- The current text domain is `ai-provider-for-codex` in [`plugin.php`](./plugin.php).
- Because the current name leads with `Codex`, this is one of the highest review-risk items.

## 3. Main Plugin Headers And Readme Metadata

- [ ] `Version:` in the main plugin file matches `Stable tag:` in `readme.txt`.
- [ ] `Text Domain:` matches the final plugin slug.
- [ ] `Requires at least:` is set in the main plugin file and reflects the real minimum supported WordPress version.
- [ ] `Requires PHP:` is set in the main plugin file and reflects the real minimum PHP version.
- [ ] `Tested up to:` in `readme.txt` is set to the current major WordPress version you actually tested against.
- [ ] The short description under the readme header is plain text and under 150 characters.
- [ ] The readme passes the official validator with no formatting errors.

Repo notes:

- `Version: 0.1.0` in [`plugin.php`](./plugin.php)
- `Stable tag: 0.1.0` in [`readme.txt`](./readme.txt)
- `Text Domain: ai-provider-for-codex` in [`plugin.php`](./plugin.php)
- `Requires at least: 7.0` in [`plugin.php`](./plugin.php)
- `Requires PHP: 8.0` in [`plugin.php`](./plugin.php)
- `Tested up to: 7.0` in [`readme.txt`](./readme.txt)

## 4. Public Documentation Quality

- [ ] The readme explains exactly what the plugin does in user-facing terms.
- [ ] The readme clearly documents any required external service, local runtime, daemon, or system package.
- [ ] Installation instructions describe the supported installation paths, not just local developer workflows.
- [ ] Support expectations are documented.
- [ ] The changelog reflects the shipping version.

Repo notes:

- The current readme now explains the localhost sidecar, hosting requirements, external service, Terms, privacy, and data handling in WordPress.org-facing language.
- The new implementation detail doc is in [`sidecar/HOW-IT-WORKS.md`](./sidecar/HOW-IT-WORKS.md), but that is internal documentation and not a substitute for the public readme.

## 5. Service, Privacy, And Consent Disclosures

- [ ] The readme clearly explains that the plugin requires a local sidecar runtime and the `codex` CLI.
- [ ] The readme clearly states what external service is involved.
- [ ] The readme links to the relevant Terms of Use for the service.
- [ ] The readme links to the relevant privacy policy.
- [ ] The readme explains what user data leaves WordPress, what stays local, and what is stored on disk.
- [ ] If any communication with external systems happens automatically, the behavior is documented and justified as part of the service.
- [ ] The plugin does not track users without explicit and authorized consent.

Repo notes:

- The plugin uses a localhost sidecar and `codex app-server`, documented in [`readme.txt`](./readme.txt), [`sidecar/README.md`](./sidecar/README.md), and [`sidecar/HOW-IT-WORKS.md`](./sidecar/HOW-IT-WORKS.md).
- The current public readme includes Terms and privacy links and now explicitly describes what data is stored in WordPress versus in the sidecar.

## 6. Functional Completeness

- [ ] The submitted zip is a complete, installable plugin.
- [ ] The plugin does not depend on unpublished premium code or a missing companion package to function as described.
- [ ] Any required companion runtime or service is already real, documented, and installable.
- [ ] Activation, deactivation, and uninstall behavior are implemented and safe.
- [ ] Admin settings and flows work on a clean WordPress install that meets the minimum requirements.

Repo notes:

- The plugin includes activation and uninstall hooks in [`plugin.php`](./plugin.php) and [`uninstall.php`](./uninstall.php).
- The sidecar runtime is real and implemented in [`sidecar/app/main.py`](./sidecar/app/main.py).
- The release zip includes [`sidecar/`](./sidecar), so the installation instructions in [`readme.txt`](./readme.txt) that reference `sidecar/scripts/install-systemd.sh` work from the installed plugin directory.
- The plugin depends on WordPress AI Client support in WordPress 7.0+, so the target audience is limited to environments that actually have that feature available.

## 7. Security And WordPress Coding Expectations

- [ ] All privileged actions check capabilities.
- [ ] State-changing admin actions use nonces.
- [ ] REST routes use permission callbacks.
- [ ] Settings are sanitized on input and escaped on output.
- [ ] Secrets are not exposed to unauthenticated users or in public pages.
- [ ] The plugin does not load executable code from undocumented external systems.
- [ ] Any external communication is securely documented and narrowly scoped.

Repo notes:

- Admin actions and REST routes appear to be protected in the current implementation.
- The sidecar uses loopback plus bearer-token auth and constant-time token comparison.
- Plugin Check 1.9.0 is installed on this site, and a release-style run currently reports no findings.
- Re-run Plugin Check against the exact final submission zip on submission day.

## 8. Release Packaging

- [ ] The submission zip contains only runtime files required by end users.
- [ ] No local logs, transcripts, notes, or machine-generated scratch files are included.
- [ ] No static-analysis config, composer metadata, or development scripts are included unless they are strictly required at runtime.
- [ ] No `.git` or other VCS files are included.
- [ ] The zip installs correctly through the normal WordPress plugin uploader.

Current packaged zip includes:

- [`plugin.php`](./plugin.php)
- [`readme.txt`](./readme.txt)
- [`uninstall.php`](./uninstall.php)
- [`LICENSE`](./LICENSE)
- [`src/`](./src)
- [`assets/`](./assets)
- [`languages/`](./languages)
- [`sidecar/`](./sidecar)

Current packager excludes:

- [`.git/`](./.git)
- [`dist/`](./dist)
- [`codex-app.err`](./codex-app.err)
- [`composer.json`](./composer.json)
- [`composer.lock`](./composer.lock)
- [`phpstan.neon`](./phpstan.neon)
- [`phpstan-baseline.neon`](./phpstan-baseline.neon)
- [`.gitignore`](./.gitignore)
- [`README.md`](./README.md)
- [`LOCAL-SIDECAR-SPEC.md`](./LOCAL-SIDECAR-SPEC.md)
- [`PLUGIN-SUBMISSION-READINESS-CHECKLIST.md`](./PLUGIN-SUBMISSION-READINESS-CHECKLIST.md)
- [`scripts/`](./scripts)
- [`vendor/`](./vendor)
- [`package-lock.json`](./package-lock.json)
- Python build artifacts (`__pycache__`, `*.pyc`, `*.pyo`)

## 9. Testing And Verification

- [ ] Run the plugin’s project-specific verification steps.
- [ ] Test install and activation on a clean site matching the minimum supported versions.
- [ ] Test the sidecar installation path on a host that matches the documented requirements.
- [ ] Validate `readme.txt` with the official validator.
- [ ] Run the Plugin Check plugin and resolve Plugin Repo errors before submission.

Repo notes:

- Local verification currently passes using [`scripts/verify.sh`](./scripts/verify.sh).
- A release-style zip currently builds successfully at `../plugin-builds/ai-provider-for-codex-0.1.0.zip`.
- Plugin Check 1.9.0 is installed on this site and a release-style run currently reports no findings.

## 10. Submission Packet

- [ ] Prepare a brief but precise submission description for the wordpress.org submission form.
- [ ] Use the release zip, not the full repository archive.
- [ ] Confirm the plugin is ready for review now and is not just reserving a name.
- [ ] Confirm the review contact mailbox is actively monitored.

Suggested submission description points for this plugin:

- It is a WordPress AI provider plugin for Codex.
- It uses a localhost sidecar runtime on the same host as WordPress.
- Each WordPress user connects their own account.
- It is intended for environments that can run a local service and the `codex` CLI.

## 11. After Approval

- [ ] Record the approved slug and SVN URL.
- [ ] Update any slug-dependent text domain or identifiers if the approved slug differs from the current one.
- [ ] Commit the release to WordPress.org SVN.
- [ ] Tag the first release with the same version declared in the main plugin header and `readme.txt`.
- [ ] Add plugin assets in the assets SVN area if needed.
- [ ] Reply quickly to any follow-up review messages.

## Immediate Next Actions For This Repo

- [ ] Decide whether the plugin name or slug should change to reduce trademark risk before submission.
- [ ] Re-run Plugin Check against the exact final submission zip on submission day.
- [ ] Validate [`readme.txt`](./readme.txt) with the official readme validator.
- [ ] Re-check `Tested up to` on the actual day of submission.

## Primary Sources

- Planning and submission process: <https://developer.wordpress.org/plugins/wordpress-org/planning-submitting-and-maintaining-plugins/>
- Detailed plugin guidelines: <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>
- Readme rules: <https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/>
- Plugin Check and 2FA requirement for new submissions: <https://make.wordpress.org/plugins/2024/10/01/plugin-check-and-2fa-now-mandatory-for-new-plugin-submissions/>
- Compliance disclaimer guidance: <https://developer.wordpress.org/plugins/wordpress-org/compliance-disclaimers/>
