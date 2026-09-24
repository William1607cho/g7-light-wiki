# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [v0.1.1] - 2026-09-24

### Added

- The wiki board list response, title search results included, now carries
  `board.wiki.front_post_id` — the same name and shape the document page response already uses,
  `null` when no front page is set. `list_mode` is unchanged, and boards that are not wikis get
  no `board.wiki` at all, as before. This lets the template draw a "go to front page" button on
  the list screen from the same value as the one on the document page.

### Changed

- The front page created by the set-up now opens with a place for the wiki's introduction and
  a short guide for the operator — creating the first document, grouping documents into
  categories, listing a category on the front page, aliases and the timeline — which can be
  deleted once read. Every example is inside `<code>`, so nothing in it is replaced on screen or
  extracted on save. The browse placeholder and the closing links to the index and syntax help
  documents stay. Front pages that already exist are not touched.
- The Korean wording of the set-up screen and its release error uses "만들기" (create) in place
  of "마련" (prepare), matching the tab title and the "new board" option that already said
  "만들기". English strings are unchanged.
- `scripts/` is excluded from release archives, alongside `tests/`.
- In the set-up screen's list of wiki boards, a board's name now links to its front page
  (`/board/{slug}/{front_post_id}`) instead of the board list. The value is the
  `front_post_id` the settings response already carried for each row; when it is `null` the
  link stays on the board list. Whether the front page post still exists is not checked, the
  same as the template's "Go to Front Page" button on a document page.
- The Korean label of the `managed_boards` setting reads "세트 설치로 만든 게시판" instead of
  "…마련한 게시판", the last "마련" left in text the plugin can show.

## [v0.1.0] - 2026-09-23

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
- Wiki set-up API (admin, `/api/plugins/g7-light-wiki/admin/wiki-setup`). It can create a new
  basic board as a wiki, or turn an empty basic board into one, and in the same request writes
  three seed documents — "대문" (front page, registered as the board's front post), "모든 문서"
  (index) and "위키 문법 도움말" (syntax help, a placeholder until its text is written). Creating
  or converting requires both `core.plugins.update` and `sirsoft-board.boards.create`.
  - The board, its settings, its manager, the seed posts, the document index and the settings
    file write all happen in one database transaction; the settings file is written last so a
    failure rolls everything back.
  - A new board starts from the board module's own `basic_defaults` (the database column
    defaults differ from them) with view count, replies, comments and admin notifications off,
    and the saving administrator as its board manager. Converting changes only those four
    settings and adds the saving administrator as manager only when the board has none.
  - A board can be converted only when it has no post rows at all — trashed posts, replies and
    notices included (the `posts_count` column skips trashed posts) — and no categories.
  - The seed author is chosen per request from users with the `admin` role.
  - The "위키 문법 도움말" seed now carries the full syntax guide: links, categories and aliases,
    timeline events, placeholders, the automatic footer, how to show markup literally, and when
    markup is not recognised. Every example sits inside inline code, so none of it is rendered or
    registered as a category, alias, event or backlink. Only wikis set up after this change get
    it; existing syntax documents are left as they are.
  - After the commit the bot (SSR) cache of the three seed documents is cleared. The board
    module renders and caches a post's bot page the moment the post is created, which here is
    before the board is registered as a wiki, so the cached page would show raw `[[…]]` markup
    until it expired.
  - Boards set up this way are recorded in two new settings keys, `managed_boards` and
    `setup_state`. Boards registered by hand in `wiki_boards` are not recorded and keep working
    exactly as before.
- Releasing a wiki (`DELETE …/wiki-setup/boards/{id}`) removes a set-up board from the wiki
  settings and deletes this plugin's index rows for it. The board and its posts stay; `[[…]]`
  markup shows as plain text again. Only boards set up by this plugin can be released there.
- Settings screen for the set-up API. A new "Set Up a Wiki Board" tab (opened by default) holds
  the set-up form (create a new board or turn an empty board into a wiki, plus the seed author),
  the list of boards set up here with a release button behind a confirmation dialog, and notes
  about home widgets, menus and uninstalling. A "please finish setting up" banner shows while no
  set-up board exists. The previous screen moved unchanged into a second tab. Errors show the
  server's message and field errors as returned; the screen decides nothing on its own.
- The settings screen's first tab now reads the set-up status from the right place. It had been
  looking one level too deep into the data source, so the author list, the list of set-up
  boards and the "please finish setting up" banner all came out empty.
- Releasing a hand-registered board on the second tab now asks for confirmation first, in a box
  that opens inside that row, and says that the change takes effect only after Save. Confirming
  runs the same list update as before on the screen's own state, and what is saved is unchanged.
  (A first attempt used a modal that wrote to the page state through `$parent._local`; the Save
  button then still sent the old list, so a set-up board could be "removed" without the server
  ever seeing it. The modal is gone.) The tabs are renamed
  "Create a Wiki Board" and "Registered Manually", and the error for dropping a set-up board on the
  second tab now points to the first tab's Release button.
- `GET …/wiki-setup` now also returns `needs_setup`, `default_author_id` and, per set-up board,
  `seed_count` (seed posts still alive in that board) and `author` (`{id, name}`). Existing
  fields are unchanged.
- Uninstalling is refused while any board set up by this plugin still exists. Uninstalling never
  deletes boards or posts; tables and settings are dropped by the core only with `--delete-data`,
  and the refusal check reads no plugin table, so it is safe when those tables are already gone.

### Changed

- Saving the wiki board list (`PUT …/admin/wiki-boards`) now refuses to drop a board that was set
  up by this plugin (422); release it instead, so the set-up record and the wiki list cannot
  drift apart. Hand-registered wiki boards can be added and removed as before.

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

### Fixed

- The alphabetical index (`[[#색인]]`) no longer starts its first column lower than the others.
  The gap between consonant groups came from the heading's top margin, and a CSS multi-column
  layout keeps that margin at the top of the first column while dropping it after a column
  break. The gap now hangs below each group instead, so every column starts at the top of the
  index. Group spacing is unchanged (`1.25em` of the body size — the same distance the heading's
  `1em` used to give at its own larger font size).
- `[[#둘러보기]]` now keeps space **above** its two-column block. It had no margin at all, so the
  paragraph before it sat directly against the block. The margin is five body lines (`10em` at
  the measured 36px line height over 18px body text) and is the same when the block folds to one
  column on narrow screens. It sits above rather than below because the block usually ends a
  front page's own text: a margin below would instead push apart the block and the placeholders
  written after it. Adjacent margins collapse, so a preceding element's own bottom margin does
  not add to it.

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
