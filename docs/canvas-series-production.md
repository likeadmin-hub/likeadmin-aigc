# Canvas series production

New conversations freeze `series_core` and use the current conventional short-drama production service. Previously frozen conversations retain their existing stages. Story setting and outline are explicit user decisions; individual episode production uses current input contracts, model selection, dialogue splitting, shot duration and continuity logic. Canvas projection does not ask a second model to rewrite those results.

## Pages and ownership

The project list contains roots only. The existing canvas create API accepts `canvas_project_id` and a stable `request_key` to add a page. Current/save retain page document IDs for all graph, generation and conversation operations. Current returns `canvas_project_id`, `page_title`, and `pages`. PC routes use `canvas_id` for the project and `page_id` for the active page. Existing URLs without `page_id` continue to open the first page. The same project can therefore hold many episodes while each graph remains bounded to 200 nodes.

Next episode creation checks completion of the current episode's video nodes and has a durable replay receipt. It returns `next_page_id`. Models, production task bindings, series continuity and eligible subject/scene references are carried forward. Previous episode graph and conversations stay on their original page. The page menu also permits independent blank pages. Saving must succeed before the PC switches pages.

Reuse requires matching server-owned identity, prompt, style/model configuration and original owned asset receipt. Reused references do not submit generation tasks. Changing generation parameters invalidates reuse. Missing or changed source material fails validation and does not silently generate replacements. Project deletion remains soft, rejects active runs and retains historical assets.

## Upgrade and rollback

Apply `upgrade_20261009_canvas_pages.sql` and `upgrade_20261009_canvas_production.sql` before starting the new code. They add idempotent page and internal production relation tables and do not rewrite existing canvas JSON. It remains within `aigc_short_drama` and uses existing authenticated API permissions; no new permission or provider endpoint is introduced. No changes to AIGC canvas or shared UI are required.

For rollback, stop starting new series work and let active paid production tasks reach a known state. Preserve the relation table and all page documents; do not flatten or delete episode pages. Old code does not understand project grouping, so restore this feature before editing grouped projects. No deployment or release package is part of this source change.

## Validation

Run `CanvasSeriesProductionTest` with conventional story/reliability/continuity/dialogue tests, plus PC composer/document tests. Local MySQL acceptance scripts `p7_series_pages.php` and `p7_series_observer.php` use `SHORT_DRAMA_AGENT_TEST=local-existing`, verify actual persistence/ownership/retry behavior and roll back fixtures. The observer test rejects any call to the chat provider. These checks are not a claim of successful real upstream generation of 500 episodes.

Internal production project IDs are recorded with the canvas stage binding and omitted from conventional project lists. This keeps engine reuse from presenting each page as a duplicate conventional project.
