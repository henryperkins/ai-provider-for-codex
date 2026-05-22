# Local Runtime Generation Transport Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix Codex text generation so the documented default localhost sidecar URL works while preserving WordPress AI Client provider/model attribution.

**Architecture:** Keep Codex registered as a normal WordPress AI Client provider, but do not send localhost runtime traffic through the AI Client HTTP transporter because WordPress core's transporter adapter uses `wp_safe_remote_request()`, which rejects `http://127.0.0.1:4317`. Generation should call the existing runtime `Client::post()` path, which uses `wp_remote_request()` and shares the same response parsing and health mapping as admin/auth/snapshot calls.

**Tech Stack:** PHP 7.4, WordPress 7.0+ AI Client, WP HTTP API, WP-CLI verification via `scripts/verify.sh`, PHPStan, Plugin Check.

---

## Review Finding Being Fixed

The uncommitted change routes [src/Models/CodexTextGenerationModel.php](../../../src/Models/CodexTextGenerationModel.php) generation through [src/Runtime/Client.php](../../../src/Runtime/Client.php) `send_with_transporter()`. The WordPress AI Client transporter delegates to `wp_safe_remote_request()`, and the documented default runtime URL `http://127.0.0.1:4317` is rejected before the request reaches the sidecar.

Confirmed behavior from review:

```text
Client::send_with_transporter(..., "GET", "/v1/health")
RuntimeException: Network error occurred while sending request to http://127.0.0.1:4317/v1/health: A valid URL was not provided.
```

The same URL through `wp_remote_request()` reaches the local sidecar and returns `401`, proving the sidecar is reachable and the regression is specific to the safe transporter path.

## File Responsibility Map

- Add/track `scripts/plugin-check-release.sh`: make the release-style Plugin Check wrapper an intentional part of the change set because this plan and the readiness checklist reference it.
- Modify `scripts/verify.php`: add regression coverage that exercises Codex text generation against the default loopback runtime URL with a mocked runtime response.
- Modify `src/Models/CodexTextGenerationModel.php`: route text generation through `Client::post()` again.
- Modify `src/Runtime/Client.php`: remove the now-unused AI Client transporter helper and imports; keep shared response parsing and transport-error normalization.
- Modify `PLUGIN-SUBMISSION-READINESS-CHECKLIST.md`: add the local-runtime generation regression check to the release verification notes.

## Dirty Worktree And Commit Rules

This checkout already has unrelated edits. Treat them as user-owned. Implementation may inspect the whole working tree, but commits must stage only the hunks/files that belong to this plan.

- Before every optional commit, run `git status --short` and `git diff -- <paths>` for the task's files.
- Stage modified files with `git add -p <paths>`, not whole-file `git add`, unless every hunk in that file belongs to this plan.
- For the new `scripts/plugin-check-release.sh`, use `git add scripts/plugin-check-release.sh` only after confirming its full content matches Task 1.
- Before committing, run `git diff --cached --name-only` and `git diff --cached -- <paths>` to confirm no unrelated hunks are staged.
- If the implementation is not being committed incrementally, skip commit steps and still run the final verification.

## Non-Goals

- Do not change admin/auth/snapshot traffic; those paths already use `Client::request()` and were not the regression.
- Do not widen the sidecar beyond loopback or change `CODEX_WP_HOST`.
- Do not register a global replacement HTTP transporter for the AI Client registry; that would affect built-in providers and unrelated plugins.
- Do not deliver AI Request Logging or Connector Approvals attribution for Codex prompts in this change. The removed `send_with_transporter()` docblock claimed those integrations, but the path was broken by `wp_safe_remote_request()` URL rejection and never functioned end-to-end against the documented loopback runtime URL. If that attribution becomes a requirement, a follow-up task should add it via a targeted filter (e.g., `http_request_args` for runtime URLs) or a custom PSR-18 client — not by restoring the broken safe-transporter routing.
- Do not edit the `0.1.1` changelog bullet about provider/connector registration. That bullet describes the canonical-registration alignment shipped in commit `06d85e7` and is accurate as written; the loopback transport is an internal detail and not user-visible.

---

### Task 1: Track The Release Plugin Check Wrapper And Establish Baseline

**Files:**
- Add/track: `scripts/plugin-check-release.sh`

This plan uses `scripts/plugin-check-release.sh` for the baseline and final release check, so the wrapper must be committed intentionally instead of remaining an untracked local helper.

- [ ] **Step 1: Confirm the wrapper content**

If `scripts/plugin-check-release.sh` already exists, verify it matches the content below. If it does not exist, create it with this content:

```bash
#!/usr/bin/env bash
set -euo pipefail

# Runs Plugin Check against the symlinked dev checkout while skipping the
# dev-only files that scripts/release-exclude.txt strips from the release zip.
#
# Plugin Check's --exclude-* flags do literal substring matching (see
# Plugin_Check/.../Abstract_File_Check.php), so glob entries in
# release-exclude.txt (e.g. *.pyc, *.swp) cannot be auto-derived; the lists
# below are the dev-checkout subset of release-exclude.txt that survives that
# limitation. Plugin Check already excludes .git, vendor, vendor_prefixed,
# vendor-prefixed, and node_modules by default — do not re-add those here.
# When you add a dev-only file to release-exclude.txt, also add it here if it
# is a literal name that lands in a developer's working tree.

SLUG="ai-provider-for-codex"

if [[ -z "${WP_PATH:-}" ]]; then
	echo "Set WP_PATH=/path/to/wordpress and retry." >&2
	exit 1
fi

EXCLUDE_DIRECTORIES="scripts,sidecar/scripts"
EXCLUDE_FILES=".gitignore,.distignore,phpstan-stubs.php,LOCAL-SIDECAR-SPEC.md,PLUGIN-SUBMISSION-READINESS-CHECKLIST.md,README.md,codex-app.err,composer.json,composer.lock,package.json,package-lock.json,phpstan.neon,phpstan-baseline.neon"

if ! command -v wp >/dev/null 2>&1; then
	echo "This script requires wp-cli." >&2
	exit 1
fi

if [[ ! -d "${WP_PATH}" ]]; then
	echo "WordPress path does not exist: ${WP_PATH}" >&2
	echo "Set WP_PATH=/path/to/wordpress and retry." >&2
	exit 1
fi

wp --path="${WP_PATH}" plugin check "${SLUG}" \
	--exclude-directories="${EXCLUDE_DIRECTORIES}" \
	--exclude-files="${EXCLUDE_FILES}" \
	"$@"
```

- [ ] **Step 2: Make the wrapper executable and visible to Git**

Run:

```bash
chmod +x scripts/plugin-check-release.sh
git add -N scripts/plugin-check-release.sh
git diff --check -- scripts/plugin-check-release.sh
```

Expected: `git diff --check` produces no output.

- [ ] **Step 3: Establish the Plugin Check baseline before transport code changes**

Run:

```bash
WP_PATH=/home/dev/wp-hperkins-com bash scripts/plugin-check-release.sh
```

Expected: `Success: Checks complete. No errors found.` If the baseline already has findings, note them so Task 6's post-fix run can be compared against the same starting state.

- [ ] **Step 4: Commit the wrapper if this work is being committed incrementally**

Run:

```bash
git status --short
git add scripts/plugin-check-release.sh
git diff --cached -- scripts/plugin-check-release.sh
git commit -m "test: add release plugin-check wrapper"
```

Expected: the staged diff contains only `scripts/plugin-check-release.sh`.

---

### Task 2: Add A Failing Regression Check

**Files:**
- Modify: `scripts/verify.php`

**Precondition:** Before adding the test, confirm the regression code is still in the working tree. Run:

```bash
git diff src/Models/CodexTextGenerationModel.php
```

Verify the diff still shows `generateTextResult()` routing through `$client->send_with_transporter( $this->getHttpTransporter(), 'POST', '/v1/responses/text', ... )`. If that diff is gone, do not reintroduce broken routing in this shared dirty checkout. Record `pre-fix failure unavailable because the transport fix was already present` and continue with the regression coverage; Step 3 is only required to fail when this precondition shows the broken routing is still present.

- [ ] **Step 1: Add AI Client message imports**

Add these imports after the existing `use AIProviderForCodex\Runtime\Settings;` line:

```php
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
```

- [ ] **Step 2: Insert generation-path coverage after `$codex_provider_base_url` is set**

Find this existing line:

```php
$codex_provider_base_url = Settings::get_base_url();
```

Immediately after it, insert the regression check below. The mock intentionally returns `$preempt` when WordPress marks the request with `reject_unsafe_urls`; `pre_http_request` runs before `wp_http_validate_url()`, so preempting safe requests would hide the current failure instead of proving the regression.

```php
$codex_provider_generation_requests = [];
$codex_provider_with_mock_runtime(
	static function ( $preempt, array $args, string $url ) use ( $codex_provider_base_url, $codex_provider_http_json_response, &$codex_provider_generation_requests ) {
		if ( 0 !== strpos( $url, $codex_provider_base_url ) ) {
			return $preempt;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '/v1/responses/text' !== $path ) {
			return $preempt;
		}

		if ( ! empty( $args['reject_unsafe_urls'] ) ) {
			return $preempt;
		}

		$codex_provider_generation_requests[] = [
			'url'  => $url,
			'args' => $args,
		];

		return $codex_provider_http_json_response(
			200,
			[
				'requestId'    => 'codex-verify-generation',
				'outputText'   => 'Local runtime generation path works.',
				'finishReason' => 'stop',
				'usage'        => [
					'inputTokens'  => 5,
					'outputTokens' => 6,
				],
				'account'      => [],
				'rateLimits'   => [],
			]
		);
	},
	static function () use ( $codex_provider_assert, &$codex_provider_generation_requests, $codex_provider_temporary_model_a, $codex_provider_temporary_user_id ) {
		$model  = AiClient::defaultRegistry()->getProviderModel( 'codex', $codex_provider_temporary_model_a );
		$result = $model->generateTextResult(
			[
				new UserMessage(
					[
						new MessagePart( 'Return a short verification sentence.' ),
					]
				),
			]
		);

		$codex_provider_assert(
			'Local runtime generation path works.' === $result->toText(),
			'Codex text generation should return the mocked local runtime output.'
		);
		$codex_provider_assert(
			1 === count( $codex_provider_generation_requests ),
			'Codex text generation should make exactly one local runtime request.'
		);

		$request_args = $codex_provider_generation_requests[0]['args'] ?? [];
		$request_body = json_decode( (string) ( $request_args['body'] ?? '' ), true );

		$codex_provider_assert(
			empty( $request_args['reject_unsafe_urls'] ),
			'Codex text generation should not use the AI Client safe HTTP transporter for the loopback runtime request.'
		);
		$codex_provider_assert(
			is_array( $request_body ) && $codex_provider_temporary_user_id === (int) ( $request_body['wpUserId'] ?? 0 ),
			'Codex text generation should send the current WordPress user ID to the runtime.'
		);
	}
);
```

- [ ] **Step 3: Run verification and confirm it fails before the fix when the broken routing is present**

Run:

```bash
WP_PATH=/home/dev/wp-hperkins-com bash scripts/verify.sh
```

Expected before Task 3 when the precondition shows the broken `send_with_transporter()` routing is present:

```text
Error: Network error occurred while sending request to http://127.0.0.1:4317/v1/responses/text: A valid URL was not provided.
```

If the precondition showed the fix was already present before this task began, `scripts/verify.sh` may pass here; note that the red phase was unavailable because the code was already fixed. If the precondition showed broken routing and verification still passes, the mock is invalid because it is preempting the safe transporter path.

- [ ] **Step 4: Commit the regression test if this work is being committed incrementally**

```bash
git status --short
git diff -- scripts/verify.php
git add -p scripts/verify.php
git diff --cached -- scripts/verify.php
git commit -m "test: cover codex loopback generation transport"
```

---

### Task 3: Route Generation Through The Existing Local Runtime Client

**Files:**
- Modify: `src/Models/CodexTextGenerationModel.php`

- [ ] **Step 1: Replace the transporter call with `Client::post()`**

In `generateTextResult()`, replace this call:

```php
$response = $client->send_with_transporter(
	$this->getHttpTransporter(),
	'POST',
	'/v1/responses/text',
	array_filter(
```

with:

```php
$response = $client->post(
	'/v1/responses/text',
	array_filter(
```

Keep the existing request body array and `array_filter()` callback unchanged.

- [ ] **Step 2: Confirm the resulting try block has this shape**

```php
try {
	$response = $client->post(
		'/v1/responses/text',
		array_filter(
			[
				'wpUserId'          => $wp_user_id,
				'requestId'         => wp_generate_uuid4(),
				'input'             => $this->flatten_prompt( $prompt ),
				'systemInstruction' => $config->getSystemInstruction(),
				'model'             => $model_id,
				'modelPreferences'  => [ $model_id ],
				'reasoningEffort'   => $this->extract_reasoning_effort(),
				'responseFormat'    => $this->build_response_format(),
				'context'           => [
					'surface'    => 'wordpress-ai-client',
					'pluginSlug' => 'ai-provider-for-codex',
				],
			],
			static function ( $value ): bool {
				return null !== $value && '' !== $value && [] !== $value;
			}
		)
	);
} catch ( RuntimeRequestException $exception ) {
	if ( $exception->is_auth_required() ) {
		ConnectionService::invalidate_local_connection( $wp_user_id );
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are escaped at the render boundary.
	throw self::runtime_exception( $exception->getMessage() );
}
```

- [ ] **Step 3: Run the focused verification**

Run:

```bash
WP_PATH=/home/dev/wp-hperkins-com bash scripts/verify.sh
```

Expected after this task:

```text
Success: AI Provider for Codex verification passed.
```

- [ ] **Step 4: Commit the transport fix if this work is being committed incrementally**

```bash
git status --short
git diff -- src/Models/CodexTextGenerationModel.php scripts/verify.php
git add -p src/Models/CodexTextGenerationModel.php scripts/verify.php
git diff --cached -- src/Models/CodexTextGenerationModel.php scripts/verify.php
git commit -m "fix: keep codex generation on local runtime transport"
```

---

### Task 4: Remove The Unsafe Transporter Helper

**Files:**
- Modify: `src/Runtime/Client.php`

- [ ] **Step 1: Remove unused imports**

Replace the import block:

```php
use RuntimeException;
use Throwable;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
```

with:

```php
use RuntimeException;
```

- [ ] **Step 2: Delete `send_with_transporter()` completely**

Remove the entire method that begins with:

```php
public function send_with_transporter(
	HttpTransporterInterface $transporter,
	string $method,
	string $path,
	array $body = []
): array {
```

and ends with:

```php
	return $this->process_response(
		(int) $response->getStatusCode(),
		(string) ( $response->getBody() ?? '' ),
		''
	);
}
```

Do not delete `process_response()` or `normalize_transport_error_string()`. `request()` and `normalize_transport_error_message()` still use them.

Also update the `normalize_transport_error_string()` docblock so it no longer claims the normalizer is shared between two transport paths. After this task, only `normalize_transport_error_message()` (the `\WP_Error` wrapper used by `request()`) calls it. Delete the second paragraph entirely:

```php
 * Shared by the `wp_remote_request` and AI Client transporter paths so both
 * surface the same admin-facing guidance on connect/timeout failures.
```

No replacement paragraph is needed — the leading summary sentence (`Maps a raw transport error message to a clearer runtime message.`) already describes the function accurately.

- [ ] **Step 3: Confirm there are no references to the removed helper**

Run:

```bash
rg -n "send_with_transporter|HttpTransporterInterface|RequestOptions|HttpMethodEnum|AI Client transporter" src scripts
```

Expected:

```text
```

- [ ] **Step 4: Run static checks**

Run:

```bash
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
composer phpstan
```

Expected:

```text
No syntax errors detected ...
[OK] No errors
```

- [ ] **Step 5: Commit the cleanup if this work is being committed incrementally**

```bash
git status --short
git diff -- src/Runtime/Client.php
git add -p src/Runtime/Client.php
git diff --cached -- src/Runtime/Client.php
git commit -m "refactor: remove codex ai-client transporter bridge"
```

---

### Task 5: Add A Readiness Note For The Regression

**Files:**
- Modify: `PLUGIN-SUBMISSION-READINESS-CHECKLIST.md`

The `0.1.1` changelog bullet in `readme.txt` ("Align provider and connector registration with the canonical WordPress AI Client registration flow") describes the canonical-registration alignment in commit `06d85e7`, not transport. It is accurate as written and does not need editing — the user-visible behavior of Codex prompt generation does not change with this fix.

- [ ] **Step 1: Add a readiness note for the regression**

In `PLUGIN-SUBMISSION-READINESS-CHECKLIST.md`, under `## 9. Testing And Verification`, add this repo note after the existing local verification bullet:

```markdown
- The verification script covers text generation through the default loopback runtime URL so regressions caused by `wp_safe_remote_request()` rejecting `http://127.0.0.1:4317` are caught before release.
```

- [ ] **Step 2: Commit docs if this work is being committed incrementally**

```bash
git status --short
git diff -- PLUGIN-SUBMISSION-READINESS-CHECKLIST.md
git add -p PLUGIN-SUBMISSION-READINESS-CHECKLIST.md
git diff --cached -- PLUGIN-SUBMISSION-READINESS-CHECKLIST.md
git commit -m "docs: document loopback generation verification"
```

---

### Task 6: Final Verification And Release Check

**Files:**
- Verify only; no file changes expected.

- [ ] **Step 1: Check the working tree diff**

Run:

```bash
git diff -- src/Models/CodexTextGenerationModel.php src/Runtime/Client.php scripts/verify.php PLUGIN-SUBMISSION-READINESS-CHECKLIST.md scripts/plugin-check-release.sh
```

Expected:

```text
The diff shows the tracked Plugin Check wrapper, the regression test, generation returning to Client::post(), removal of send_with_transporter() plus the now-orphaned imports and docblock paragraph, and the readiness-checklist note only. No changes to readme.txt.
```

- [ ] **Step 2: Run all local verification commands**

Run:

```bash
git diff --check
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
composer phpstan
node --input-type=module --check < assets/connectors.js
WP_PATH=/home/dev/wp-hperkins-com bash scripts/verify.sh
WP_PATH=/home/dev/wp-hperkins-com bash scripts/plugin-check-release.sh
bash scripts/package-release.sh
```

Expected:

```text
git diff --check produces no output.
PHP lint reports no syntax errors.
PHPStan reports [OK] No errors.
Node syntax check exits 0.
scripts/verify.sh reports Success: AI Provider for Codex verification passed.
plugin-check-release.sh reports Success: Checks complete. No errors found — matching the baseline captured in Task 1.
package-release.sh creates ../plugin-builds/ai-provider-for-codex-0.1.1.zip.
```

- [ ] **Step 3: Confirm the fix preserves the intended boundaries**

Run:

```bash
rg -n "wp_safe_remote_request|send_with_transporter|wp_remote_request|/v1/responses/text" src scripts
```

Expected:

```text
src/Runtime/Client.php still uses wp_remote_request().
src/Models/CodexTextGenerationModel.php still sends /v1/responses/text through Client::post().
No send_with_transporter references remain.
No plugin code calls wp_safe_remote_request() for Codex runtime traffic.
```

- [ ] **Step 4: Create the final commit if requested**

```bash
git status --short
git diff -- src/Models/CodexTextGenerationModel.php src/Runtime/Client.php scripts/verify.php PLUGIN-SUBMISSION-READINESS-CHECKLIST.md scripts/plugin-check-release.sh
git add -p src/Models/CodexTextGenerationModel.php src/Runtime/Client.php scripts/verify.php PLUGIN-SUBMISSION-READINESS-CHECKLIST.md
git add scripts/plugin-check-release.sh
git diff --cached --name-only
git diff --cached -- src/Models/CodexTextGenerationModel.php src/Runtime/Client.php scripts/verify.php PLUGIN-SUBMISSION-READINESS-CHECKLIST.md scripts/plugin-check-release.sh
git commit -m "fix: restore codex loopback generation transport"
```

Expected: the staged files are exactly `src/Models/CodexTextGenerationModel.php`, `src/Runtime/Client.php`, `scripts/verify.php`, `PLUGIN-SUBMISSION-READINESS-CHECKLIST.md`, and `scripts/plugin-check-release.sh`; the staged hunks do not include unrelated readme, version, UI, sidecar, or packaging changes.

## Self-Review Checklist

- The plan fixes the confirmed regression by removing the generation dependency on the AI Client HTTP transporter.
- The plan adds a regression check that fails on the current uncommitted change and passes once generation returns to `Client::post()`. Task 2's precondition guards against the test silently passing on already-reverted code without telling the implementer to reintroduce broken routing in the shared checkout.
- The plan keeps provider registration and model creation in the WordPress AI Client registry, so callers still use Codex through the canonical provider/model API.
- The plan avoids a global transporter replacement that could alter behavior for Anthropic, Google, OpenAI, or other providers.
- The plan explicitly names the trade-off in Non-Goals: AI Request Logging / Connector Approvals attribution for Codex prompts is not delivered here, because the previous routing was non-functional. Any future attribution work will be a separately scoped task.
- The plan tracks `scripts/plugin-check-release.sh`, establishes a Plugin Check baseline before the fix so any post-fix delta is attributable to the change, and updates `PLUGIN-SUBMISSION-READINESS-CHECKLIST.md` so future releases re-run the loopback generation case.
- The plan uses patch staging for dirty modified files so unrelated existing changes do not enter the optional incremental commits or final commit.
