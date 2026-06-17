# Codex Text-To-Image Generation Design

**Date:** 2026-06-17

**Goal:** Add text-to-image generation to Scriptorium AI Provider for Codex through the existing local Codex sidecar, preserving per-user ChatGPT/Codex authentication and billing while exposing a real WordPress AI Client image-generation model.

**Status:** Design spec. No implementation has been performed.

## Background And Problem

WordPress/ai 1.0.2 tightened image-generation provider handling so image generation is offered only through providers that actually advertise image-generation support. This plugin currently advertises text generation only:

- `readme.txt` says "Text generation only; vision and image generation are routed to other providers."
- `src/Provider/ModelCatalog.php` creates only `CapabilityEnum::textGeneration()` and text input/output modalities.
- `src/Models/CodexTextGenerationModel.php` implements only `TextGenerationModelInterface`.
- `sidecar/app/main.py` exposes only `POST /v1/responses/text` for generation.

That was correct when the plugin was first cut over, but current Codex ChatGPT auth now reports provider-backed image generation. A direct `codex app-server` capability probe against the installed `codex-cli 0.140.0` returned:

```json
{
  "namespaceTools": true,
  "imageGeneration": true,
  "webSearch": true
}
```

The design must not solve this by adding OpenAI API keys or direct PHP calls to the Images API. The defining product constraint remains: WordPress talks to the localhost sidecar, and the sidecar talks to `codex app-server` using each WordPress user's isolated Codex/ChatGPT auth state.

## Scope

**In scope for v1**

- Text-to-image generation only.
- One WordPress AI Client image-generation model exposed by the `codex` provider when the active Codex app-server provider reports `imageGeneration: true`.
- A sidecar image endpoint backed by Codex app-server turns, not the OpenAI Platform Images API.
- Base64 PNG image results mapped into WordPress AI Client `File`, `MessagePart`, `Candidate`, and `GenerativeAiResult` DTOs.
- Request logging, auth invalidation, Connector Approval error handling, rate-limit/account refresh, and verification coverage aligned with the existing text path.

**Out of scope for v1**

- Reference-image editing, masks, inpainting, outpainting, or variation flows.
- Direct OpenAI API key billing or `/v1/images/*` calls from PHP.
- Media Library attachment insertion. Callers receive image files through the AI Client result; WordPress/ai or the caller can decide whether to persist them.
- Transparent-background model fallback or `gpt-image-1.5` specific controls.
- A new wp-admin image-generation UI.

## Reference Points

The implementation should re-check these during planning and execution because Codex app-server schema can change with the CLI version.

- `https://github.com/openai/codex` is the upstream Codex source reference.
- Installed local reference at design time: `codex-cli 0.140.0`.
- Generated app-server schema command:

```bash
codex app-server generate-json-schema --out /tmp/codex-app-schema
```

Schema and source facts verified at design time:

- `v2/ModelProviderCapabilitiesReadResponse.json` requires boolean `imageGeneration`.
- `v2/ThreadReadResponse.json` includes `imageGeneration` thread items with `id`, `status`, `result`, optional `revisedPrompt`, and optional `savedPath`.
- `codex-rs/model-provider/src/provider.rs` defaults provider capabilities to `image_generation: true` for OpenAI-backed providers and disables it for unsupported providers such as Bedrock.
- `codex-rs/core/src/tools/spec_plan.rs` exposes image generation only when current auth uses the Codex backend, provider capabilities allow image generation, and the active model supports image input modalities.
- `codex-rs/protocol/src/models.rs` parses Responses API `image_generation_call` items with base64 `result`.
- `codex-rs/core/src/stream_events_utils.rs` persists completed image-generation results as PNG files under `generated_images/<session>/<call>.png`.

WordPress AI Client facts verified at design time:

- `ImageGenerationModelInterface::generateImageResult(array $prompt): GenerativeAiResult` is the image-generation provider contract.
- `GenerativeAiResult::toImageFile()` and `toImageFiles()` expect candidates whose content includes image `File` DTOs.
- `File` accepts base64 image data when the MIME type is supplied.

## Phase 0: Blocking App-Server Shape Probe

Implementation must start with a live app-server probe before any PHP, database, or sidecar feature work. The spec intentionally depends on current Codex app-server behavior that does not exist in this repository, so implementers must pin the live contract from the deployed `codex` CLI version.

Required Phase 0 outputs:

- Generate schema from the deployed CLI:

```bash
codex app-server generate-json-schema --out /tmp/codex-app-schema
```

- Run one authenticated text-to-image turn against `codex app-server` using the same ChatGPT-authenticated account path the sidecar will use.
- Record the exact `modelProvider/capabilities/read` request shape, response shape, and boolean field path.
- Record the exact JSON-RPC notification that carries the image result during a turn, including `method`, `params.item.type`, status field, and base64 result field path.
- Record the exact `thread/read` fallback shape for completed image-generation items.
- Update this spec or the implementation plan if the observed live shape differs from the design-time assumption below.

Design-time assumption, to be verified in Phase 0:

- Raw Responses API items are `type: "image_generation_call"` with base64 `result`.
- Codex app-server v2 wraps those as thread items with `type: "imageGeneration"`, `result`, `status`, optional `revisedPrompt`, and optional `savedPath`.
- `item/completed` may carry the app-server thread item wrapper, while lower-level raw events may carry the snake_case Responses item. The sidecar parser must be written against the observed JSON-RPC event shape, not against the names in this paragraph.

If Phase 0 cannot produce an authenticated completed image item, stop and do not advertise `codex-image`.

## Recommended Architecture

Add image generation as a first-class provider model alongside the existing text model.

```text
WordPress AI Client
  -> CodexProvider
  -> CodexImageGenerationModel
  -> Runtime\Client POST /v1/responses/image
  -> sidecar RuntimeState.generate_image()
  -> codex app-server ephemeral thread/turn
  -> imageGeneration thread item
  -> base64 PNG GenerativeAiResult
```

The provider should expose:

- normal Codex text models from the runtime snapshot, unchanged;
- one synthetic image-generation model with ID `codex-image`, only when the user's runtime snapshot includes `capabilities.imageGeneration === true`.

The synthetic model is intentional. Codex app-server uses the active Codex backend and hosted image-generation tool internally; the WordPress AI Client needs a stable model ID with image-generation metadata, not a fake claim that every text model is itself an image model.

## Alternative Approaches Considered

### Custom Plugin REST Action Only

This would add a plugin-specific image endpoint without registering a WordPress AI Client image model. It is simpler internally but does not solve WordPress/ai 1.0.2 capability routing. WordPress would still treat the `codex` provider as text-only.

### Direct OpenAI Image API From PHP

This would use the documented OpenAI Image API directly. It is the wrong architecture here because it needs API-key billing and bypasses the local sidecar, per-user ChatGPT/Codex auth, local account snapshots, and Connector Approval integration.

### Mark Existing Text Models As Image-Capable

This is too broad. Existing Codex text models are configured around text prompts, reasoning effort, JSON schema output, and text result mapping. Image generation has different output DTOs, no JSON-schema response format, different failure modes, and image-specific capability gating. It should be a separate model implementation.

## Component 1: Capability Snapshot

Extend sidecar account snapshots to include app-server provider capabilities.

Current `RuntimeState.account_snapshot()` calls:

- `account/read`
- `account/rateLimits/read`
- `model/list`

Add:

```json
{ "method": "modelProvider/capabilities/read", "params": {} }
```

Normalize the result to:

```json
{
  "capabilities": {
    "imageGeneration": true,
    "namespaceTools": true,
    "webSearch": true
  }
}
```

If the method fails because an older Codex CLI does not support it, the sidecar must return all capability booleans as `false` and include no hard failure in the snapshot. The provider should not advertise image generation from an unknown capability state.

Store capabilities in a dedicated `capabilities_json` column on `{prefix}codex_provider_connection_snapshots`. Capabilities are connection snapshot state, not model metadata, and should not be hidden inside `models_json` or `rate_limits_json`.

Schema mechanics:

- Bump `Installer::SCHEMA_VERSION` from `'5'` to `'6'` so `Installer::maybe_upgrade()` re-runs `dbDelta()`.
- Add `capabilities_json longtext NOT NULL` to the snapshot table definition.
- Update `ConnectionSnapshotRepository::get()` and `list_active_for_site_catalog()` to decode the new column into `$row['capabilities']`.
- Update `ConnectionSnapshotRepository::upsert()` in lockstep: add the `capabilities_json` entry to `$data` and the matching `%s` entry to `$formats` at the same relative position. The current parallel-array pattern is easy to misalign.
- Ensure uninstall cleanup still drops the whole custom table; no separate option cleanup is needed for capabilities.
- Extend `scripts/verify.php` to prove an activation/upgrade creates the new column and that existing snapshots without capabilities fail closed.

## Component 2: Model Catalog Split

Change `ModelCatalog` from "all runtime models are text models" to a typed catalog.

Required behavior:

- Text metadata remains unchanged for runtime models returned by `model/list`.
- Image metadata is exposed as a separate model only when the current user's connection snapshot says `capabilities.imageGeneration === true`.
- Fallback site models remain text-only. A site fallback list is not proof that a disconnected user has image-generation entitlement.
- `ModelCatalogState` must carry a model kind discriminator through the catalog. The current entry shape is only `array{id,label}`; v1 should add `kind: text|image` or an equivalent internal field so `ModelCatalog::create_metadata()` can choose the correct metadata.
- Update every `ModelCatalogState` docblock and return shape touched by the new discriminator, including `get_effective_catalog()`, `get_user_snapshot_catalog()`, `get_settings_catalog()`, `normalize_models()`, and `empty_catalog()`.
- `normalize_models()` remains text-only for runtime `model/list` entries. Append the synthetic image entry after normalization only when snapshot capabilities allow it.
- `model_ids` must include `codex-image` only when image generation is available for the current user.
- `getModelMetadata( 'codex-image' )` must be capability-gated through the same catalog path as `listModelMetadata()`. Direct lookup must not synthesize image metadata for a user whose current snapshot lacks `imageGeneration: true`.

Image model metadata:

```php
new ModelMetadata(
    'codex-image',
    'Codex Image',
    [ CapabilityEnum::imageGeneration() ],
    [
        new SupportedOption( OptionEnum::inputModalities(), [ [ ModalityEnum::text() ] ] ),
        new SupportedOption( OptionEnum::outputModalities(), [ [ ModalityEnum::image() ] ] ),
        new SupportedOption( OptionEnum::systemInstruction() ),
        new SupportedOption( OptionEnum::customOptions() ),
    ]
)
```

Do not advertise `outputSchema()` for the image model. Reasoning effort may remain text-only unless implementation proves Codex image turns accept it usefully.

`CodexProvider::createModel()` must instantiate by capability, not by provider-wide default:

- image-generation metadata -> `CodexImageGenerationModel`
- text-generation metadata -> `CodexTextGenerationModel`

If metadata has both capabilities, throw a runtime exception instead of guessing. The design should keep the two models disjoint.

`CodexProvider::createModel()` currently returns `CodexTextGenerationModel` unconditionally. The implementation must change that branch before the provider can pass WordPress AI Client's `ImageGenerationModelInterface` dispatch guard.

## Component 3: WordPress Image Model

Create `src/Models/CodexImageGenerationModel.php`.

Responsibilities:

- Implement `ImageGenerationModelInterface`.
- Require a logged-in WordPress user.
- Require an existing, non-expired local connection for that user.
- Check that the requested model ID is the synthetic image model ID and that the current user's snapshot supports image generation.
- Flatten text prompt parts only. If the prompt includes any file/image parts, throw a clear "reference images are not supported yet" exception.
- Send `POST /v1/responses/image` to the local runtime.
- Map the sidecar response to `GenerativeAiResult` with one image candidate.
- Mirror success and failure into `RequestLogWriter`.
- Invalidate local connection on `auth_required`, exactly like the text path.

The meaningful reuse target is not the prompt flattener. Add a small shared trait or helper for the common model bracket:

- current user resolution and logged-in guard;
- local connection lookup and expired-connection handling;
- request-log success/error scaffolding;
- `RuntimeRequestException::is_auth_required()` -> `ConnectionService::invalidate_local_connection()`;
- elapsed-time calculation.

Keep prompt parsing separate. The text model intentionally drops non-text parts while flattening; the image model must reject file/image parts for v1.

## Component 4: Sidecar Image Endpoint

Add `POST /v1/responses/image` to `sidecar/app/main.py`.

Request body:

```json
{
  "wpUserId": 123,
  "requestId": "uuid",
  "prompt": "A watercolor header image of...",
  "systemInstruction": "Optional style guidance",
  "context": {
    "surface": "wordpress-ai-client",
    "pluginSlug": "scriptorium-ai-provider-for-codex"
  }
}
```

The request does not include the synthetic WordPress model ID as a Codex thread model. `codex-image` is a WordPress AI Client model identifier only. The sidecar should start the Codex app-server thread without a model override for v1, letting Codex choose the account's default/recommended model for the image-generation turn. A future version can add an explicit underlying Codex model option if product usage proves that control is needed.

Initial v1 does not accept:

- `inputImages`
- `mask`
- `background`
- `transparent`
- explicit image model overrides
- multiple candidates

Behavior:

1. Reject with `409 auth_required` if the user's `auth.json` is missing.
2. Start a short-lived `codex app-server` session in that user's isolated `CODEX_HOME`.
3. Call `modelProvider/capabilities/read`; reject with a typed `image_generation_unavailable` error if `imageGeneration` is false.
4. Create an ephemeral thread.
5. Start a turn with text input only.
6. Wait for turn notifications.
7. Capture completed image items from the Phase 0-verified `item/completed` shape. The existing text parser only handles `item.type === "agentMessage"` and must gain an explicit image branch.
8. If no image item was captured by the time `turn/completed` arrives, call `thread/read` and search completed turn items using the Phase 0-verified `type` and base64 field path.
9. Return the first completed image item. If multiple are present, keep v1 deterministic by returning the first and include a count in `additionalData`.
10. Refresh `account/read` and `account/rateLimits/read` after generation, matching the text path.

Response body:

```json
{
  "requestId": "uuid",
  "model": "codex-image",
  "mimeType": "image/png",
  "imageBase64": "iVBORw0KGgo...",
  "revisedPrompt": "Optional revised prompt from Codex",
  "finishReason": "stop",
  "usage": {
    "inputTokens": 0,
    "outputTokens": 0,
    "reasoningOutputTokens": 0
  },
  "account": {},
  "rateLimits": {},
  "artifacts": {
    "savedPath": "/home/user/.codex/generated_images/..."
  }
}
```

`imageBase64` is the source of truth returned to WordPress. `savedPath` is diagnostic metadata from app-server and should not be exposed as a URL or assumed readable by PHP.

The sidecar should continue to default token counts to zero when app-server does not emit usable token usage for image turns. Token accounting should not block image delivery.

`Runtime\Client` already applies the long generation timeout to every `/v1/responses/` path, so `/v1/responses/image` inherits the existing 360s generation timeout. No timeout special case is required unless Phase 0 proves image turns need a different bound.

## Component 5: Result Mapping

Add an image-specific mapper, either in `ResponseMapper` or a small sibling helper.

Mapping rules:

- `imageBase64` + `mimeType` -> `new File( $image_base64, 'image/png' )`.
- `File` -> `new MessagePart( $file )`.
- `MessagePart` -> `new ModelMessage( [ $part ] )`.
- `ModelMessage` -> `new Candidate( ..., FinishReasonEnum::stop() )`.
- `GenerativeAiResult` `additionalData` includes account, rate limits, revised prompt, artifacts, and the raw runtime model ID.

Validation:

- Missing `imageBase64` throws a runtime exception with a clear message.
- Unsupported MIME type throws before constructing a successful result.
- If `revisedPrompt` exists, it goes into `additionalData`; it is not converted to visible text content in the image candidate.

## Component 6: Request Logging

Use the existing `RequestLogWriter` path.

For success:

- `status`: `success`
- `model`: `codex-image`
- `duration_ms`
- `request_id`
- `input_preview`: flattened prompt text
- `output_preview`: `Generated image/png image` plus revised prompt when present
- `user_id`
- token fields from runtime usage when present

For failure:

- `status`: `error`
- `model`: `codex-image`
- `duration_ms`
- `error_message`
- `input_preview`
- `user_id`

Do not log base64 image data. Do not log local generated image paths unless the existing request log already treats local runtime diagnostic paths as acceptable. The safer v1 choice is to omit `savedPath` from log context.

## Component 7: Error Handling

Error codes surfaced by the sidecar:

- `auth_required`: same meaning as text generation; PHP invalidates the local connection.
- `image_generation_unavailable`: app-server provider capabilities do not allow image generation.
- `image_generation_failed`: app-server completed the turn without a completed image item, returned an image item with non-completed status, or produced invalid image data.
- existing transport/HTTP errors: normalized by `Runtime\Client`.

Connector Approval behavior remains transport-level. If WordPress AI Connector Approval blocks the local runtime request, the existing `Client` normalization should surface the actionable Connector Approval message and avoid marking the sidecar itself unreachable.

The image model should fail closed. If capability state is missing, stale, or false, the image model is not advertised and direct calls to it throw a capability error.

## Component 8: Documentation Updates

Update docs only after implementation lands:

- `README.md`: say the provider supports text generation and, when Codex app-server reports support, text-to-image generation through the local runtime.
- `readme.txt`: replace the current "Text generation only" sentence with scoped wording. Preserve WordPress.org external-service disclosure and clarify that image generation still uses user-initiated Codex/ChatGPT auth through the local sidecar.
- `sidecar/HOW-IT-WORKS.md`: add `POST /v1/responses/image`, capability probing, and image result handling.
- `LOCAL-SIDECAR-SPEC.md`: update the runtime API contract.
- `CLAUDE.md`: update the local architecture summary after code ships.

Do not update public readme claims before the feature is implemented and verified.

## Operational Notes

- Codex app-server persists generated PNG files under the user's `CODEX_HOME` in `generated_images/<session>/<call>.png`. V1 accepts that local artifact growth and does not delete files behind Codex's back. Treat disk usage as a monitored operational concern; add cleanup only after understanding Codex's own retention expectations.
- Capability visibility is snapshot-driven. A newly entitled user may not see `codex-image` until the next scheduled snapshot refresh or an explicit account refresh updates `capabilities_json`.
- If another image-capable provider is installed, this feature only makes `codex-image` available under the `codex` provider. Provider choice remains WordPress AI Client / caller behavior and is outside this plugin's v1 scope.
- SDK enum calls must use the php-ai-client dynamic constructor style, for example `ModalityEnum::image()` and `CapabilityEnum::imageGeneration()`, not constant syntax.

## Testing And Verification

The implementation plan should include focused tests before code changes.

Required test coverage:

- Phase 0:
  - generated schema contains the capability response and image item shape needed by the implementation;
  - authenticated app-server probe records the exact event names and base64 field path used by the sidecar parser.
- `scripts/verify.php`:
  - schema version upgrades from `5` to `6` and creates `capabilities_json`;
  - snapshot repository reads, lists, and writes `capabilities_json` without misaligning `$data` and `$formats`;
  - image model is absent when snapshot capabilities are missing or false;
  - image model is present when `capabilities.imageGeneration` is true;
  - `getModelMetadata( 'codex-image' )` fails closed without image capability and succeeds only with it;
  - provider creates `CodexImageGenerationModel` for the image metadata and `CodexTextGenerationModel` for text metadata;
  - image generation posts to `/v1/responses/image`;
  - base64 PNG runtime response maps to a `GenerativeAiResult` whose `toImageFile()->isImage()` is true;
  - image generation rejects prompt file/image parts with the v1 reference-image unsupported message;
  - image success and failure create request-log entries without base64 data;
  - `auth_required` invalidates local connection;
  - Connector Approval transport blocks stay actionable.
- Sidecar manual probe:
  - `codex app-server generate-json-schema --out <tmp>` still includes `imageGeneration` capability and `imageGeneration` thread item shape;
  - a real ChatGPT-authenticated runtime reports `imageGeneration: true`;
  - a controlled text-to-image request returns a PNG base64 payload or a clear `image_generation_unavailable` error.
- Release gates:
  - `composer validate --strict`;
  - `WP_PATH=/home/dev/wp-hperkins-com bash scripts/verify.sh`;
  - `WP_PATH=/home/dev/wp-hperkins-com bash scripts/plugin-check-release.sh`;
  - `bash scripts/package-release.sh` and inspect the built archive for unintended local artifacts.

## Implementation Notes

- Prefer a small shared model trait/helper for the auth, connection, request-log, auth-invalidation, and elapsed-time bracket. Do not share the prompt flattener between text and image unless it can preserve the image model's required file-part rejection.
- Do not expose a filter for the synthetic image model ID/name in v1; keep the model stable and simple.
- Include `savedPath` only in runtime response `artifacts` and result `additionalData`. Never treat it as a public URL.

## Rollout Notes

This is a feature addition, not a release metadata change by itself. Version strings should remain unchanged during implementation until the normal release process bumps the plugin header, `readme.txt` stable tag, and sidecar version strings together.

The feature should not be announced as generally available until a real runtime probe has confirmed `imageGeneration: true` and the WordPress AI Client result can be consumed as an image file.
