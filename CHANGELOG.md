# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Category markup `[[분류:이름]]`. The token is removed from the body (an emptied paragraph
  goes with it) and the document grows a category line at the bottom. A category is not a
  separate entity but a document titled `분류:이름` — so sub-categories come for free, and the
  permission and secret-post rules need no special casing. A missing category renders with the
  existing red-link rule.
- A document titled `분류:…` automatically lists the documents in that category.
  `[[#분류|이름]]` renders the same list anywhere in the board.
- Alias markup `[[별칭:이름]]`. The token is removed from the body and listed as
  "다른 이름" under the document. Links resolve by real title first, alias second; an alias that
  collides with another document's real title loses and is logged.
- Backlinks — every document lists the documents pointing at it (by title or by alias),
  ordered by normalized title, capped at 100. The heading is omitted entirely at zero.
- Timeline markup `[[연표:키|설명]]`. The key orders events and is not shown; only the
  description stays in the body. `[[#연표]]` lists every event in the board by key, and
  `[[#연표|문서명]]` lists that document's events together with those of documents linking to it.
  Keys are `-?정수(.정수)*`, compared field by field, with the shorter key first; a malformed key
  is left in the body verbatim and registers no event.
- Extraction table `light_wiki_refs`, filled from the body on save, restore and delete, and
  rebuilt by `light-wiki:rebuild`. Secret and blinded documents keep their rows but are excluded
  from every list shown to others.
- Bot cache invalidation now also covers the link targets, the category documents and — when an
  alias changes — the documents linking through that alias, capped at 100 per save.
- The index `[[#색인]]` now lays its consonant groups out in up to three columns that shrink to
  two and then one on narrower screens; `[[#색인|N]]` sets the maximum (1–4, anything else 3).
- Two listing modes on the board listing, reached from the `[[#둘러보기]]` column headings.
  `?sort_by=g7lw-recent` lists every document by last edit, newest first, paginated with the
  board's own page size. `?sort_by=g7lw-random` draws 20 distinct documents and stays on a
  single page — a `page` parameter is ignored, because a fresh draw makes page 2 meaningless.
  Any other `sort_by` value keeps the previous behaviour (the front page post alone), and a
  search keyword still wins over both modes.
- The random listing is rate limited per requester and board with a 3 second cooldown. A repeat
  request inside the cooldown is answered with the previous draw rather than an error, so
  hammering "draw again" never replaces the listing with an error screen. The reused ids are
  re-checked for visibility on every request.
- Wiki boards now advertise themselves in their API responses through a single `board.wiki`
  field. Its presence means "this board is a wiki"; there is no separate `is_wiki` flag. The
  listing carries `{"list_mode": "none"|"recent"|"random"}` — the mode actually applied, not the
  one asked for in `sort_by`, because a search keyword overrides the mode. The post detail
  carries `{"front_post_id": <id>|null}`. The post form metadata carries an empty object. A
  board that is not a wiki gets no field at all. A template can read none of the plugin's
  settings, so shipping the value in the response is the only way for a screen to branch on it.
- A new middleware puts that field on the post form **metadata** response
  (`boards.posts.form-meta`). It is deliberately not on `form-data`: the template loads that
  response wholesale into its form state and posts it back when saving, so an extra field there
  would travel into the post-save request body.

### Changed

- Internal tidy-up, no behaviour change: picking which documents a listing shows moved out of
  `Doc\DocListRenderer` into a new `Doc\DocListTarget`. The renderer now only rewrites the
  response; the rules for search, recent, random and the front page — including "a search
  keyword beats the mode" — all live in one place next to each other.
- Wiki post detail responses are now rewritten even when the body is left untouched, so that
  `board.wiki` is present on every document of a wiki board. Previously the response object was
  returned as-is whenever the rewrite produced no change — which would have made the field
  appear on some documents and not others, and the screen would differ document by document.
  Nothing else about the response changes, and boards that are not wikis are still not touched
  at all.
- The random listing now draws `min(20, page size)` documents instead of a fixed 20. The mode is
  pinned to a single page, so anything beyond one page could not be reached anyway, while the
  surplus produced a page-2 pager that led nowhere (a phone showing 15 of 20). The page size
  comes from the same source the recent listing uses — the core response's pagination, which
  already accounts for the board setting and the mobile split.
- The random cooldown cache key now includes the number drawn, so a desktop draw (20) and a
  phone draw (15) no longer reuse each other's previous result inside the 3 second window.

- The board listing, its title search and both new modes now apply the same conditions the core
  user listing applies: replies (`parent_id`) and notices (`is_notice`) are excluded, deleted
  posts stay out, and the core permission scope helper decides the rest. Secret and blinded
  documents remain in the listing with their titles shown and only the preview masked — that is
  what the core does. The admin "include deleted" toggle (`?del=1`) is still not honoured.
- The front page post is exempt from the notice condition alone, so a board whose front page is
  also a notice still shows it in the default listing, the recent listing and search results.
  Every other condition — deleted, reply, permission scope — still applies to it, and no other
  notice becomes visible.
- The title search now filters before it truncates. Previously the 2000 row cap was applied to
  the raw index rows and the visibility filter ran afterwards, so a document the requester was
  allowed to see could be pushed past the cap, and the "at least N" total was counted before
  filtering.
- Internal: `WikiDocSearchQuery` becomes `WikiBoardListQuery` — it is no longer only the title
  search but the source of every board listing mode, and the name no longer reads like the
  `Placeholders\DocListSource` interface. The board listing's row cap moves with it
  (`WikiBoardListQuery::LIST_LIMIT`) instead of borrowing the index placeholder's
  `WikiDocListQuery::INDEX_LIMIT`; the two values are equal today but no longer coupled.
  New: `Doc\ListMode` (reads the mode from `sort_by`) and `Doc\RandomDrawCache` (the cooldown).

- Placeholder headings size with the body text (`1.25em`) instead of a fixed `1.125rem`, so a
  site that enlarges its body text keeps the heading proportional.
- `[[#둘러보기]]` now lists **recently edited** documents in its left column instead of recently
  created ones, and each column heading is a link to the matching board listing
  (`/board/<slug>?sort_by=g7lw-recent` · `?sort_by=g7lw-random`). Recently created documents
  remain available through `[[#최근작성]]`.
- 내부 구조 정리(동작 변경 없음).
- Internal: `WikiDocQuery` is split by responsibility into `WikiDocLookup` (resolve a title or
  a placeholder-bearing body to a post), `WikiDocListQuery` (the placeholder list sources and
  their count constants) and `WikiDocSearchQuery` (the board listing's title search and
  visibility filter). Queries, ordering and call signatures are unchanged.

### Added (1.5단계까지)

- Placeholders `[[#최근작성]]` / `[[#최근작성|N]]` (documents ordered by the core post
  `created_at`, default 5, max 50) and `[[#둘러보기]]` / `[[#둘러보기|N]]` (a two-column block:
  recently created on the left, random on the right, default 5 each; laid out with inline
  `style` only, folding to one column on narrow screens).
- `[[#랜덤|N]]` with N of 2 or more now renders a list of N distinct documents. `[[#랜덤]]`
  without N (and `|1`) keeps the single-link behaviour.
- Per-board wiki mode, configured from an admin screen (`wiki_boards`: board plus an
  optional front page post).
- Document index table `light_wiki_docs`, keyed by a normalized title
  (`utf8mb4_bin`) that is unique per board.
- Duplicate title rejection (HTTP 422) on both the visitor and admin post routes.
- `[[Document]]` and `[[Document|label]]` markup rendered as links on the post detail
  response; missing documents render as a red link to the write form (or plain red text
  without write permission).
- Front page placeholders `[[#최근수정]]`, `[[#최근수정|N]]`, `[[#랜덤]]`, `[[#색인]]`. The random
  target is chosen while the front page is rendered, from the documents the requester may read,
  and becomes an ordinary document link — a full page navigation to a plugin URL carries no
  bearer token, so a redirect endpoint would see every visitor as a guest.
- Title pre-fill for the write form, carried through a one-shot session value. The `…/new`
  endpoint only checks that the board is a wiki; write permission is checked on the form-data
  response (where the token is present) and by the core on save. The session value is consumed
  even when it is not used.
- `GET /api/plugins/g7-light-wiki/new` redirect endpoint.
- `light-wiki:rebuild --board=<id> [--dry-run]` artisan command.
- Bot cache invalidation for placeholder-bearing documents when any document on the board
  changes (up to 50 per save, most recently updated first; a warning is logged when the cap
  truncates the list). The front page post is always included.
- Korean and English translations.
- Wiki board post list is rewritten: with no search term it shows the front page post only
  (untouched when no front page post is configured); with a search term it lists documents whose
  normalized title contains the normalized term, ordered by `title_norm`, single characters
  allowed, `%`/`_`/`\` matched literally. Items are produced by the core transformer
  (`PostCollection` → `PostResource::toListArray()`) and `board`/`abilities`/page size are taken
  from the core response, so item keys and value formats are unchanged. Notices are filtered out
  with everything else. Non-200 core responses are passed through untouched.

### Changed

- Placeholders are no longer front-page-only: they are filled in on **any HTML-mode document**
  of a wiki board. Candidates exclude the front page post and the document being rendered.
- Index group headings and the two tour column headings are emphasised with an inline
  `style` (`font-weight:700;font-size:1.125rem;margin:1rem 0 .25rem`) instead of relying on
  template CSS, which carries no rule for the `g7lw-…` classes. No colour is set, so the
  headings inherit it and stay readable in dark mode.

### Known limits

- Substitution only runs when the post body is stored in HTML mode; plain-text bodies are
  left untouched.
- List, search and mypage excerpts still show raw `[[…]]` markup.
- `[[분류:…]]` and `[[연표:…]]` are reserved and kept as literal text in this phase.
