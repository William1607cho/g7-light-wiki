# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Per-board wiki mode, configured from an admin screen (`wiki_boards`: board plus an
  optional front page post).
- Document index table `light_wiki_docs`, keyed by a normalized title
  (`utf8mb4_bin`) that is unique per board.
- Duplicate title rejection (HTTP 422) on both the visitor and admin post routes.
- `[[Document]]` and `[[Document|label]]` markup rendered as links on the post detail
  response; missing documents render as a red link to the write form (or plain red text
  without write permission).
- Front page placeholders `[[#최근수정]]`, `[[#최근수정|N]]`, `[[#랜덤]]`, `[[#색인]]`.
- Title pre-fill for the write form, carried through a one-shot session value.
- `GET /api/plugins/g7-light-wiki/random` and `/new` redirect endpoints.
- `light-wiki:rebuild --board=<id> [--dry-run]` artisan command.
- Front page bot cache invalidation when any document on the board changes.
- Korean and English translations.

### Known limits

- Substitution only runs when the post body is stored in HTML mode; plain-text bodies are
  left untouched.
- List, search and mypage excerpts still show raw `[[…]]` markup.
- `[[분류:…]]` and `[[연표:…]]` are reserved and kept as literal text in this phase.
