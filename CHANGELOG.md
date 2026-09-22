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

### Changed

- Placeholder headings size with the body text (`1.25em`) instead of a fixed `1.125rem`, so a
  site that enlarges its body text keeps the heading proportional.
- 내부 구조 정리(동작 변경 없음).

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
