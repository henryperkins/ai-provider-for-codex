# Codex Image Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add text-to-image generation to Scriptorium AI Provider for Codex through the existing local sidecar and WordPress AI Client image model contract.

**Architecture:** Store Codex app-server provider capabilities in the per-user connection snapshot, expose a synthetic `codex-image` model only when `imageGeneration` is true, and route `ImageGenerationModelInterface` calls through a new `/v1/responses/image` sidecar endpoint. The sidecar starts an ephemeral app-server turn without passing `codex-image` as a runtime model, captures the Phase 0-verified `imageGeneration` item result, and returns base64 PNG data for PHP to map into AI Client DTOs.

**Tech Stack:** WordPress plugin PHP 7.4+, `wordpress/php-ai-client` 1.3.1 DTOs, Python 3.11 sidecar, Codex app-server JSON-RPC, WP-CLI verification scripts.

**Implementation Status:** Implemented and verified on 2026-06-18. The final verification pass included `composer validate --strict`, `composer phpstan`, `php scripts/test-request-log-writer.php`, all sidecar Python tests, `WP_PATH=/home/dev/wp-hperkins-com bash scripts/verify.sh`, `bash scripts/package-release.sh`, zip file-list inspection, `WP_PATH=/home/dev/wp-hperkins-com bash scripts/plugin-check-release.sh`, and `git diff --check`.

---

## Phase 0 Findings

- Local CLI: `codex-cli 0.141.0`.
- `modelProvider/capabilities/read` with `{}` returns `imageGeneration`, `namespaceTools`, and `webSearch` booleans.
- A ChatGPT-authenticated image turn produced `item/started` and `item/completed` notifications with `params.item.type === "imageGeneration"` and base64 PNG in `params.item.result`.
- In `0.141.0`, `item/completed` carried a non-empty `result` while `params.item.status` was `"generating"`. Treat a non-empty result on `item/completed` as deliverable; do not require status to equal `"completed"`.
- For ephemeral threads, `thread/read` with `includeTurns: true` fails with `ephemeral threads do not support includeTurns`. Fallback reads must use plain `thread/read` and parse returned turns only if present.
- No stable underlying runtime model field was observed. Keep `runtimeModel` omitted or `null`.

## Tasks

### Task 1: Request Log Type Support

**Files:**
- Modify: `src/Logging/RequestLogWriter.php`
- Modify: `scripts/test-request-log-writer.php`

- [ ] Add failing standalone request-log tests proving image entries use `type: image`, `operation: codex:responses/image`, and previews do not include base64 or saved paths.
- [ ] Run `php scripts/test-request-log-writer.php` and confirm the new image assertions fail against the current hardcoded text constants.
- [ ] Add explicit `type` and `operation` support to `RequestLogWriter::build_entry()` with text defaults preserved.
- [ ] Re-run `php scripts/test-request-log-writer.php` and confirm the text and image cases pass.

### Task 2: Capability Snapshot Persistence

**Files:**
- Modify: `src/Database/Installer.php`
- Modify: `src/Auth/ConnectionSnapshotRepository.php`
- Modify: `sidecar/app/main.py`
- Modify: `scripts/verify.php`

- [ ] Add failing verification coverage for schema version `6`, `capabilities_json`, snapshot read/list/upsert decoding, and missing-capabilities fail-closed behavior.
- [ ] Run `WP_PATH=/home/dev/wp-hperkins-com bash scripts/verify.sh` and confirm the new assertions fail before implementation.
- [ ] Bump `Installer::SCHEMA_VERSION` to `6` and add `capabilities_json longtext NOT NULL` to the snapshot table.
- [ ] Read and write `capabilities_json` in `ConnectionSnapshotRepository`, keeping `$data` and `$formats` aligned.
- [ ] Add sidecar `modelProvider/capabilities/read` snapshot probing that returns false booleans when unsupported.
- [ ] Re-run the targeted verification and confirm the snapshot assertions pass.

### Task 3: Typed Catalog And Provider Dispatch

**Files:**
- Modify: `src/Provider/ModelCatalogState.php`
- Modify: `src/Provider/ModelCatalog.php`
- Modify: `src/Provider/CodexProvider.php`
- Modify: `src/Admin/UserConnectionPage.php`
- Modify: `src/Plugin.php`
- Modify: `scripts/verify.php`

- [ ] Add failing verification coverage that `codex-image` appears only for snapshots with `capabilities.imageGeneration === true`, is absent from settings fallback, does not become `selected_text_model`, is not accepted as preferred text model, and direct `getModelMetadata( 'codex-image' )` fails closed without capability.
- [ ] Add failing verification coverage that image metadata includes `CapabilityEnum::imageGeneration()` and `CapabilityEnum::chatHistory()` but not `outputSchema()`.
- [ ] Add failing verification coverage that `CodexProvider::model( 'codex-image' )` returns an image model and text IDs still return `CodexTextGenerationModel`.
- [ ] Implement catalog `kind`, `text_model_ids`, `image_model_ids`, and `selected_text_model` payload fields while preserving existing `selected_model` as the text selection compatibility field.
- [ ] Keep admin model selection and `wpai_preferred_text_models` text-only.
- [ ] Update provider dispatch to branch by metadata capabilities and throw on mixed text/image metadata.
- [ ] Re-run `WP_PATH=/home/dev/wp-hperkins-com bash scripts/verify.sh` until the catalog/provider assertions pass.

### Task 4: PHP Image Model And Result Mapping

**Files:**
- Create: `src/Models/CodexImageGenerationModel.php`
- Create or modify: `src/Models/LocalRuntimeModelTrait.php`
- Modify: `src/Models/CodexTextGenerationModel.php`
- Modify: `src/Runtime/ResponseMapper.php`
- Modify: `scripts/verify.php`

- [ ] Add failing verification coverage that image generation posts `/v1/responses/image`, rejects file/image prompt parts, invalidates local connection on `auth_required`, maps base64 PNG to `GenerativeAiResult::toImageFile()->isImage()`, and logs image success/failure without base64.
- [ ] Extract shared user/connection/logging/auth-invalidation scaffolding from the text model into a small trait or helper without sharing text prompt parsing.
- [ ] Implement `CodexImageGenerationModel::generateImageResult()` with text-only prompt flattening and capability checks against the current user's effective catalog.
- [ ] Add `ResponseMapper::to_image_generative_ai_result()` with MIME validation, base64 `File`, image candidate, token usage, and additional data.
- [ ] Re-run `WP_PATH=/home/dev/wp-hperkins-com bash scripts/verify.sh` until the PHP image model assertions pass.

### Task 5: Sidecar Image Endpoint

**Files:**
- Modify: `sidecar/app/main.py`
- Add or modify: `sidecar/scripts/test-image-generation.py`

- [ ] Add failing sidecar unit tests for capability normalization, `extract_image_item()` accepting `item/completed` with non-empty result even when status is `"generating"`, rejecting missing result, and `/v1/responses/image` returning `image_generation_unavailable` when capabilities are false.
- [ ] Run the new sidecar test and confirm it fails before implementation.
- [ ] Add `RuntimeState.generate_image()` and route `POST /v1/responses/image`.
- [ ] Start an ephemeral thread without model override, pass text input only, capture image items from notifications, use plain `thread/read` fallback only when returned turns contain items, and return the first PNG result with account/rate-limit refresh.
- [ ] Re-run sidecar tests and the live sidecar manual probe.

### Task 6: Documentation And Release Verification

**Files:**
- Modify: `README.md`
- Modify: `readme.txt`
- Modify: `sidecar/HOW-IT-WORKS.md`
- Modify: `LOCAL-SIDECAR-SPEC.md`
- Modify: `CLAUDE.md`

- [ ] Update docs only after tests prove the feature works.
- [ ] Run `composer validate --strict`.
- [ ] Run `php scripts/test-request-log-writer.php`.
- [ ] Run sidecar Python tests.
- [ ] Run `WP_PATH=/home/dev/wp-hperkins-com bash scripts/verify.sh`.
- [ ] Run `bash scripts/package-release.sh` and inspect `unzip -l` for unintended local artifacts.
- [ ] Run `WP_PATH=/home/dev/wp-hperkins-com bash scripts/plugin-check-release.sh`.
