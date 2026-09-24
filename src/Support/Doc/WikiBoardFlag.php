<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

/**
 * 응답의 게시판 정보에 얹는 **위키 표시 칸** — `board.wiki`.
 *
 * 화면(템플릿)은 플러그인 설정을 읽을 방법이 없다. 그래서 "이 게시판이 위키인가" 와
 * 화면이 분기하는 데 필요한 값은 **응답에 실어 보내는 것이 유일한 길**이다.
 *
 * ## 키 하나로 끝낸다
 *
 * `board.wiki` 가 **있으면 위키 게시판**이고, 없으면 아니다. 따로 `is_wiki` 를 두지 않는다 —
 * 같은 사실을 두 곳에 적으면 한쪽만 고쳐질 수 있고, 값을 `wiki` 안에 모아 두면 코어가
 * 나중에 게시판 정보에 새 칸을 만들어도 이름이 부딪히지 않는다.
 *
 * ## 세 응답의 자리가 같다
 *
 * 목록·상세·글쓰기 폼 메타 셋 다 응답 봉투(`{success, message, data}`)의 `data.board` 에
 * 게시판 정보가 있다. 그래서 얹는 메서드는 하나이고, 담기는 값만 다르다.
 *
 * | 응답 | 값 |
 * |---|---|
 * | 목록 | `{"list_mode": "none"|"recent"|"random", "front_post_id": <대문 글 id>|null}` — 모드는 **실제로 적용된** 것 |
 * | 상세 | `{"front_post_id": <대문 글 id>|null}` |
 * | 폼 메타 | `{}` — 위키라는 사실 자체가 전부다 |
 *
 * `list_mode` 가 주소의 `sort_by` 가 아니라 **적용된 모드**인 것이 중요하다. 검색어가 있으면
 * 서버가 모드를 무시하므로({@see DocListTarget::mode()}), 주소만 보고 제목을 고르면
 * `?search=…&sort_by=g7lw-recent` 에서 틀린 제목이 뜬다.
 *
 * `front_post_id` 는 목록과 상세가 **같은 이름·같은 모양**이다. 목록 화면의 "대문으로 이동"
 * 버튼이 상세 화면 버튼과 같은 값을 읽도록 한다.
 *
 * **위키가 아닌 게시판에는 아무것도 얹지 않는다.** 그 판정은 미들웨어 첫 줄의
 * {@see \Plugins\G7\Light\Wiki\Support\WikiBoardSettings::isWikiBoard()} 한 곳이 하고,
 * 이 클래스는 다시 판정하지 않는다.
 */
final class WikiBoardFlag
{
    /** 게시판 정보 안에 실리는 칸 이름 */
    public const KEY = 'wiki';

    /**
     * 응답 봉투의 `data`(= `$payload`)에 게시판 정보가 있으면 거기에 칸을 얹는다.
     *
     * 게시판 정보가 없거나 배열이 아니면 **그대로 돌려준다** — 모양을 모르는 응답에 칸을
     * 새로 만들지 않는다.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withWiki(array $payload, mixed $value): array
    {
        if (! isset($payload['board']) || ! is_array($payload['board'])) {
            return $payload;
        }

        $payload['board'][self::KEY] = $value;

        return $payload;
    }

    /**
     * 목록 응답에 실을 값.
     *
     * @param  string  $mode  {@see ListMode} 의 값
     * @return array{list_mode: string, front_post_id: ?int}
     */
    public static function listValue(string $mode, ?int $frontPostId): array
    {
        return ['list_mode' => $mode, 'front_post_id' => $frontPostId];
    }

    /**
     * 상세 응답에 실을 값.
     *
     * @return array{front_post_id: ?int}
     */
    public static function pageValue(?int $frontPostId): array
    {
        return ['front_post_id' => $frontPostId];
    }

    /**
     * 글쓰기 폼 메타에 실을 값 — 빈 **객체**다.
     *
     * 빈 PHP 배열은 JSON 에서 `[]` 가 된다. 화면은 `board.wiki` 가 있는지만 보므로 둘 다
     * 참이지만, 세 응답의 값이 모두 객체여야 읽는 쪽이 한 가지 모양만 기억하면 된다.
     */
    public static function formMetaValue(): \stdClass
    {
        return new \stdClass();
    }
}
