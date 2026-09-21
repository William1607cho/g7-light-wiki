# g7-light-wiki

그누보드7(g7) 기본형 게시판을 **가벼운 개인 위키**로 쓰게 하는 플러그인.
게시판별로 켜며, 권한 구성에 따라 공개 위키와 비공개 위키를 겸용합니다.

코어·`sirsoft-board`·템플릿은 **파일 한 줄도 고치지 않습니다.** 전용 테이블 1개,
응답 미들웨어 2개, 훅 리스너 3개, 플러그인 API 3개로만 동작합니다.

- 버전: 0.1.0 (1단계)
- 라이선스: MIT
- 요구: g7 >= 7.0.11, `sirsoft-board` >= 1.1.2

## 하는 일

| 기능 | 설명 |
|---|---|
| 제목 = 문서 이름 | 위키 게시판에서 정규화 제목이 같은 글 두 개를 막습니다(422). |
| 문서 링크 | 본문의 `[[문서명]]`·`[[문서명\|표시 글자]]` 를 그 문서로 가는 링크로 바꿉니다. |
| 빨간 링크 | 없는 문서는 빨간 링크입니다. 누르면 **그 제목이 채워진** 작성 화면이 열립니다. 글쓰기 권한이 없으면 링크 없이 빨간 글자만 보입니다. |
| 대문 자리표시 | 대문 글에서 `[[#최근수정]]`·`[[#최근수정\|N]]`·`[[#랜덤]]`·`[[#색인]]` 이 채워집니다. |

## 쓰는 법

1. 관리자 화면 **플러그인 → 위키 게시판 설정**에서 위키로 쓸 게시판을 고릅니다.
2. 게시판마다 **대문 글 ID** 를 적을 수 있습니다(비워 두면 대문 없이 링크 기능만).
3. 그 게시판에 글을 쓰면 제목이 곧 문서 이름이 됩니다.

### 본문 표기

```
[[설치 방법]]                  → 그 문서로 가는 링크
[[설치 방법|설치하기]]          → 표시 글자를 따로 지정
[[분류:인물]]  [[연표:1.0]]     → 예약 표기. 1단계에서는 글자 그대로 남습니다
```

대문 글에서만 쓰는 자리표시:

```
[[#최근수정]]      최근 고친 문서 10개 (기본값)
[[#최근수정|20]]   개수 지정 (최대 50)
[[#랜덤]]          아무 문서로 보내는 링크
[[#색인]]          가나다(초성)·A~Z·0~9·기타 색인
```

### 주의

- **본문은 HTML 모드(서식 에디터)로 씁니다.** 평문 모드에서는 화면이 본문을 통째로
  이스케이프해 그리기 때문에, 링크를 넣으면 태그가 글자로 보입니다. 그래서 평문 모드
  글은 건드리지 않고 표기를 그대로 둡니다.
- **본문에 `$t:키` 형태의 문자열을 쓰지 마세요.** 방문자 화면과 봇 화면 양쪽이 본문 안의
  `$t:` 토큰을 번역으로 바꿉니다(이 플러그인과 무관한 코어 동작). 이 플러그인의 표기가
  전부 `[[…]]` 인 것도 그래서입니다.
- `<a>`·`<code>`·`<pre>` 안의 `[[…]]` 는 바꾸지 않습니다 — 코드 예시가 링크로 변하지 않습니다.
- 목록·검색·마이페이지의 **요약문**에는 `[[…]]` 가 글자 그대로 보입니다. 치환은 글 상세에서만 일어납니다.

## artisan 명령

```
php artisan light-wiki:rebuild --board=<게시판 ID> [--dry-run]
```

이미 있는 글로 문서 색인을 다시 만듭니다. 플러그인을 나중에 켠 게시판에 씁니다.
정규화 제목이 겹치면 **먼저 만들어진 글**이 그 제목을 가져가고, 나머지는 글 ID 만 보고합니다.
`--dry-run` 은 아무것도 바꾸지 않고 차이만 셉니다.

## 제목 정규화

같은 문서로 볼지 말지는 다음 순서로 정합니다.

1. 유니코드 NFC
2. 전각 ASCII(`Ａ`~`～`) → 반각
3. 앞뒤 공백 제거
4. 연속 공백(NBSP·전각 공백 포함) → 공백 한 칸
5. 라틴 문자 소문자화

NFKC 는 쓰지 않습니다(한글 호환 자모가 바뀝니다). 결과가 빈 문자열이면 위키 문서로
등록하지 않습니다.

## 되돌리기

비활성화하면 미들웨어·훅이 빠져 **응답이 설치 전과 같아집니다**(본문 DB 는 손대지 않으므로
`[[…]]` 가 다시 글자로 보입니다). 색인 표는 남고, `plugin:uninstall --delete-data` 에서만
지워집니다. 언제든 `light-wiki:rebuild` 로 다시 만들 수 있습니다.

---

# g7-light-wiki (English)

A Gnuboard7 plugin that turns a `sirsoft-board` basic board into a **lightweight personal
wiki**. Enabled per board; public and private wikis both work, depending on board permissions.

The core, `sirsoft-board` and the template are **never modified** — the plugin works only
through its own table, two response middlewares, three hook listeners and three plugin API routes.

- Version: 0.1.0 (phase 1)
- License: MIT
- Requires: g7 >= 7.0.11, `sirsoft-board` >= 1.1.2

## What it does

| Feature | Description |
|---|---|
| Title is the document name | Two posts with the same normalized title are rejected (422). |
| Document links | `[[Document]]` and `[[Document\|label]]` in a post body become links. |
| Red links | Missing documents show as red links that open the write form **with the title filled in**. Without write permission they are plain red text. |
| Front page placeholders | `[[#최근수정]]`, `[[#최근수정\|N]]`, `[[#랜덤]]` and `[[#색인]]` are filled in on the front page post. |

## Usage

1. In the admin area, open **Plugins → Wiki Boards** and pick the boards to use as a wiki.
2. Each board may name a **front page post ID** (leave empty for links only).
3. Post titles on those boards become document names.

### Notes

- **Write bodies in HTML mode (the rich editor).** In plain-text mode the front end escapes the
  whole body, so injected links would show up as literal tags. Plain-text posts are left untouched.
- **Never put `$t:key` strings in a post body.** Both the visitor view and the bot renderer
  replace `$t:` tokens with translations — core behaviour unrelated to this plugin, and the
  reason every markup here uses `[[…]]`.
- `[[…]]` inside `<a>`, `<code>` and `<pre>` is left alone.
- List and search **excerpts** still show the raw `[[…]]` — substitution happens on the post
  detail response only.

## Artisan command

```
php artisan light-wiki:rebuild --board=<board id> [--dry-run]
```

Rebuilds the document index from existing posts. On a normalized-title clash the **oldest post**
keeps the title and the rest are reported by post ID only. `--dry-run` changes nothing.

## Uninstalling

Deactivating removes the middleware and hooks, so **responses go back to what they were before
installation**. The index table stays and is dropped only by `plugin:uninstall --delete-data`;
it can always be rebuilt with `light-wiki:rebuild`.
