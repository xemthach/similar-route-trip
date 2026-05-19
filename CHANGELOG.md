# Changelog

## 0.4.11 - 2026-05-20

- Added a detail view and per-item retry action for queue items in the admin Tools screen.
- Kept the five-minute queue scheduler, failed-task retry, queue stats, Vietnamese diacritics cleanup, and SEO generation controls intact.

## 0.4.10 - 2026-05-20

- Added a five-minute queue scheduler and cron hook so queued image jobs can be processed automatically in the background.
- Added a retry-failed queue action and a recent queue table in the admin Tools screen for better operational control.
- Kept the Vietnamese diacritics cleanup, topic matrix, prompt matrix, quality gate, similarity controls, and SEO tagging intact.

## 0.4.9 - 2026-05-20

- Connected the post-creation flow to the queue system so featured image jobs can be queued automatically after a draft is created.
- Added queue statistics in the admin Tools screen so batch processing state is visible at a glance.
- Kept the Vietnamese diacritics normalization, topic matrix, prompt matrix, quality gate, and similarity controls intact.

## 0.4.8 - 2026-05-20

- Hardened the Vietnamese diacritics cleanup so ASCII-only route phrases are normalized after FAQ and content assembly, not just before it.
- Verified the create-post pipeline on route `tra-vinh-di-can-tho` now stores content with no ASCII marker leftovers in the generated article body.
- Kept the live preview, quality gate, similarity warning, topic matrix, and AI settings refactor intact.

## 0.4.7 - 2026-05-20

- Added a Vietnamese diacritics guard to generated content so ASCII-only Vietnamese output can be rewritten before saving.
- Applied a mandatory Vietnamese-with-diacritics instruction to every prompt template at runtime.
- Expanded the topic matrix with descriptions, recommended word ranges, forbidden patterns, and clearer admin reporting.
- Refined the AI Settings page labels to better separate global runtime settings from the provider registry.

## 0.4.6 - 2026-05-20

- Added quality score, similarity score, and warning summary to live content preview.
- The preview pipeline now fills structured SEO data even when AI is disabled, so admin sees real title/meta/content guidance.
- Added preview warning styling and rendering in the admin content generator panel.
- Kept draft generation, similarity blocking, SEO tagging, and live REST preview intact.

## 0.4.5 - 2026-05-19

- Fixed the preview pipeline in `ContentGenerator::preview()` so length profiles are initialized before fallback handling.
- Added live REST-backed preview in the admin content generator panel for route, topic, length, SEO title, meta description, and HTML snippet.
- Added preview status and refresh control in the admin UI with supporting styles and localized REST config.
- Kept draft generation, topic divergence, similarity checks, and SEO tag generation intact.

## 0.4.4 - 2026-05-19

- Added a live content preview panel in the admin generator form.
- Added route/topic/length sync in the preview panel via admin JS.
- Added visual styling for the preview panel to make the generator easier to read.
- Kept the similarity and quality controls in place while improving the workflow surface.

## 0.4.3 - 2026-05-19

- Added route table quality and similarity columns in the admin UI.
- Added similarity breakdown meta for generated posts to aid QA and debugging.
- Added stronger admin notices after post creation, including quality and similarity scores.
- Kept prompt/topic divergence and fallback behaviour intact while improving visibility.

## 0.4.2 - 2026-05-19

- Expanded preview and create-post fallback to work across all topics, not just route landing.
- Added topic-aware fallback sections for price guide, comparison, airport route, hospital route, travel guide, and food guide.
- Normalized structured AI responses with nested article payloads so schema drift does not leak into frontend output.
- Strengthened prompt guidance with section sequencing and length-aware instructions.

## 0.4.1 - 2026-05-19

- Hardened content generation fallback so AI preview failures can still produce a draft post.
- Expanded manual fallback articles with longer route-specific sections, route FAQs, and optional route review snippets.
- Improved preview/create flow to avoid leaking thin or broken AI output into posts.
- Kept auto tag generation and similarity checks intact while making the pipeline more resilient.

## 0.4.0 - 2026-05-19

- Added topic-driven content generation (`route_landing`, `price_guide`, `travel_guide`, `food_guide`, `faq_article`).
- Added content length profiles (`short`, `standard`, `long`, `deep`, `custom`) with prompt context variables.
- Added pre-insert quality gate (word count, H1/meta checks, section depth, keyword density guard).
- Added duplicate-content hash guard to block identical regenerated articles.
- Added automatic SEO tag generation on successful post creation.
- Extended admin and REST content generation inputs: topic, content length, min/max words, primary and secondary keywords.
- Hardened generated-content pipeline by stopping post creation when quality gate fails.

## 0.3.0 - 2026-05-19

- Added optional runtime adapter for AI Commerce Agent config without copying API keys.
- Added `ai_commerce_agent` AI mode in Similar Route Trip.
- Added native Gemini text generation adapter for Google Gemini API keys.
- Added `ai_config_source` route column and post meta `_srt_ai_config_source`.
- Improved Content Generator with route table, Generate Draft, Regenerate Draft, Edit Post, View Post, and Unlink actions.
- Improved post creation validation and duplicate handling with edit/view links.

## 0.2.1 - 2026-05-19

- Added multi-key and multi-model AI settings.
- Added per-key provider, base URL, content models, image models, priority, weight, enable flag, and test status.
- Added ShopAIKey-compatible defaults based on the live OpenAPI docs: `https://api.shopaikey.com`, Bearer auth, `/v1/chat/completions`, `/v1/models`, `/v1/images/generations`.
- Added active-key and weighted routing for AI generation.
- Added admin actions to test active key or all keys and store last status/message/models.
- Updated REST AI test endpoint to return active and per-key results.

## 0.2.0 - 2026-05-19

- Added additive DB migration for route/post mapping fields.
- Added `wp_srt_queue` and `wp_srt_logs` tables.
- Added independent AI Settings with encrypted API key storage.
- Added OpenAI-compatible, ShopAIKey-compatible, Gemini-compatible, custom endpoint, and disabled AI provider modes.
- Added AI provider abstraction for text, image, connection testing, and model listing.
- Added Route Generator with bulk creation and duplicate detection.
- Added Content Generator with route-to-post mapping and duplicate protection.
- Added Prompt Templates with editable defaults and placeholder resolution.
- Added AI/external image generation flow with Media Library upload and featured image support.
- Added admin Logs and Tools pages.
- Added admin-only REST endpoints for AI tests, route generation, content generation, post creation, and image generation.
- Preserved existing shortcodes, public route REST endpoints, imports, and Distance Calculator read-only pricing bridge.

## 0.1.0 - 2026-05-19

- Initial standalone plugin scaffold.
- Added independent `wp_srt_routes` table.
- Added import from Flavor Mien Tay theme options.
- Added import from legacy Taxi Route Engine table.
- Added Distance Calculator Map read-only pricing bridge.
- Added admin page, REST API, shortcodes, schema registry, and taxi SEO prompt builder.
