# Tuyen Di Pho Bien (Simular Route Trip)

Version: `0.4.13`

Independent WordPress plugin for popular taxi routes, Similar Route recommendations, Distance Calculator Map pricing bridge, SEO prompts, REST API, and shortcodes.

## Safety

- Runs beside the existing `taxi-route-engine` plugin.
- Uses its own table: `wp_srt_routes`.
- Uses its own namespace/prefix: `SimilarRouteTrip`, `SRT_`, `srt_`.
- Does not modify the current theme or existing booking plugin.

## Features

- Import routes from `flavormt_theme_options.routes`.
- Import routes from legacy `wp_taxi_routes`.
- Read vehicle/service pricing from Distance Calculator Map.
- Generate per-vehicle price matrix.
- Admin pages: **All Routes**, **Import / Sync**, **Route Generator**, **Content Generator**, **Prompt Templates**, **AI Settings**, **Image Sources**, **Logs**, **Queue / Workers**.
- Create new routes manually or in bulk with duplicate detection.
- Generate SEO landing content from route data.
- Optional AI text/image generation with separate runtime, content-provider, image-provider, and image-source fallback controls.
- Multi-key and multi-model AI routing with per-key test status.
- Optional runtime use of AI Commerce Agent provider config without copying API keys.
- Upload generated/external images to Media Library, set featured image, and insert selected images into article content.
- Background jobs for batch content generation plus a legacy queue bridge for older image tasks.
- Worker-based queue processing with retry support and a Queue / Workers admin screen.
- Logs for import, sync, AI requests, post creation, and errors.
- REST namespace: `/wp-json/similar-route-trip/v1`.
- Shortcodes:
  - `[srt_route slug="tra-vinh-di-ben-tre" field="price"]`
  - `[srt_route_card slug="tra-vinh-di-ben-tre"]`
  - `[srt_route_table]`
  - `[srt_route_faq slug="tra-vinh-di-ben-tre"]`
  - `[srt_similar_routes slug="tra-vinh-di-ben-tre"]`

## Versioning

This plugin uses semantic versioning:

- `0.1.x`: scaffold/import/bridge foundation
- `0.2.x`: route generator, AI settings, AI content/image generation, post mapping, queue, logs
- `0.3.x`: richer CRUD manager and visual landing template preview
- `0.4.x`: topic matrix, prompt matrix, quality gate, similarity checks, Vietnamese diacritics cleanup, live preview, queue cron, and queue detail controls
- `1.0.0`: stable production release

## AI setup

The plugin can use its own AI settings or read AI Commerce Agent provider config at runtime.

1. Open **Tuyen Di Pho Bien -> AI Settings**.
2. Set **AI Provider Mode** to **Own Config** or **Use AI Commerce Agent Config**.
3. Add one or more API keys with provider, base URL, content models, image models, priority, and weight.
4. For ShopAIKey, use base `https://api.shopaikey.com` or `https://api.shopaikey.com/v1`; the plugin supports OpenAI-compatible `/v1/chat/completions`, `/v1/models`, and `/v1/images/generations`.
5. Enter temperature, max tokens, and timeout.
6. Enable content and/or image generation, then use **Test Active Key** or **Test All Keys**.

API keys are encrypted before being stored in `wp_options`, masked in admin, and never printed to the frontend.

When using AI Commerce Agent config, Similar Route Trip reads active providers from `wp_ai_provider_settings` at runtime and does not copy the API key into SRT settings.

## Prompt templates

Prompt templates live in **Tuyen Di Pho Bien -> Prompt Templates** and support placeholders:

- `{{route.from}}`, `{{route.to}}`, `{{route.slug}}`
- `{{route.distance}}`, `{{route.duration}}`, `{{route.price}}`, `{{route.formatted_price}}`
- `{{route.vehicle_prices}}`, `{{route.similar_routes}}`
- `{{site.name}}`, `{{site.phone}}`, `{{site.service_area}}`

Templates can be edited or reset to defaults.

## Safety notes

- The plugin keeps data in its own tables: `wp_srt_routes`, `wp_srt_queue`, `wp_srt_jobs`, `wp_srt_logs`.
- Migration from `0.1.0` to `0.2.0` is additive and does not drop route rows.
- AI generation is disabled by default.
- Existing shortcodes and public route REST endpoints remain compatible.

## GitHub release flow

1. Update code and version.
2. Update `CHANGELOG.md`.
3. Run lint and smoke tests.
4. Commit with a release message.
5. Tag the release, for example `v0.4.11`.
6. Push `main` and the tag to GitHub.

Example:

```bash
git add .
git commit -m "Release Similar Route Trip 0.4.11"
git tag -a v0.4.11 -m "Similar Route Trip 0.4.11"
git push origin main
git push origin v0.4.11
```

## GitHub release notes template

```md
## Highlights
- ...

## QA
- Lint: pass
- Preview: pass
- Create post: pass
- Queue: pass

## Changed files
- ...

## Notes
- ...
```
