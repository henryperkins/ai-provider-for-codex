# Image generation request budgets

The October 11 failure on hperkins.blog stopped `codex:responses/image` after 90.1 seconds. The source published as v2.1.2 at `2c0359165724780acecad92d3b60c3f33da76f79` explains the limit: WordPress AI 1.4's image ability sets an explicit 90-second `RequestOptions` timeout, and the provider correctly forwards it. The provider's 360-second fallback and `codex_provider_runtime_request_timeout` filter run only when no SDK timeout is supplied. Changing the fallback or filtering it therefore cannot fix this consumer's request.

Version 2.1.3 adds a `wpai_default_request_timeout` integration alongside the provider's existing model-preference integration. It replaces only the image-generation feature's standard 90-second budget with 360 seconds, and only when the effective first preference is `codex/codex-image`. A user without image capability, another preferred provider, another feature, a feature budget other than 90 seconds, and a directly supplied SDK timeout keep their existing behavior. Catalog reads remain passive; this filter does not make a runtime request.

To impose a site-specific budget after the provider integration, register a feature timeout filter at priority 20 or later:

```php
add_filter(
	'wpai_default_request_timeout',
	static function ( int $timeout, string $feature_id ): int {
		return 'image-generation' === $feature_id ? 360 : $timeout;
	},
	20,
	2
);
```

That site policy affects the image feature for every preferred provider. It does not change text generation or control requests. The plugin's automatic integration is narrower and checks the effective Codex image preference. A site that explicitly wants a 90-second image budget should also apply its policy at priority 20 or later: the incoming hook value alone cannot distinguish a configured 90-second limit from the feature's standard 90-second default.

## Remote deployment limit

The documented installation is a same-host loopback sidecar. The hperkins.blog deployment uses a separately maintained Windows loopback gateway and Cloudflare tunnel. That gateway's source is not part of the plugin release. The public gateway's `/healthz` responded with HTTP 200 and reported 2.1.2 during diagnosis; health is not evidence of successful image generation.

The published transport remains synchronous. Cloudflare documents a [125-second default proxy read timeout](https://developers.cloudflare.com/support/troubleshooting/http-status-codes/cloudflare-5xx-errors/error-524/), which is independent of this PHP request budget. Extending the image feature's budget fixes its 90-second cutoff; it does not prove that an image taking longer than the proxy deadline can reach the caller. Those generations need a separately qualified polling transport, a supported proxy timeout configuration, or another deployment path that permits the complete generation. A polling change would need the actual gateway's authenticated route policy and both runtime/client changes; it is not included in this release.

The sidecar answers every request with JSON and reports its own failures in an `error` envelope. The runtime client therefore reports an empty or non-JSON error body, or a 5xx without that envelope, as a proxy, tunnel, or gateway response with its HTTP status and host. A Cloudflare cutoff appears as HTTP 524 with the 125-second default rather than a generic invalid-JSON error, and a tunnel that cannot reach the gateway or sidecar appears as HTTP 502 rather than a bare `Bad Gateway`. Runtime diagnostics mark those responses as the sidecar being unreachable.

The browser's request to WordPress has its own hosting deadline. When that deadline ends the request first, the editor receives the host's HTML error page, and WordPress reports "The response is not a valid JSON response." The provider cannot extend that deadline or report it.

## Verification boundaries

`scripts/test-sdk-contracts.php` reproduces the 90-second cutoff before the timeout change and checks the filtered feature timeout through the real SDK, image model, and runtime HTTP client afterward. It also covers unchanged direct caller budgets, capability and preference boundaries, custom site policy, redirects, and token/schema contracts. Fixtures do not perform inference. [The v2.1.3 qualification](release-qualification-2.1.3.md) records local release checks separately from intended-host deployment and successful authenticated image generation.
