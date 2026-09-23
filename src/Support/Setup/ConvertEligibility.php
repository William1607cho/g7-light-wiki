<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

/**
 * 기존 게시판을 위키로 바꿀 수 있는가 — 순수 판정 (DB·HTTP·설정 접근 없음).
 *
 * **위키화 조건은 이 클래스 한 곳에만 적는다.** 화면의 "위키화 가능" 목록과 저장 직전
 * 재판정이 모두 이것을 부른다.
 *
 * 조건 (하나라도 어긋나면 거부, 사유는 아래 순서로 첫 번째 하나):
 * 1. 이미 위키 게시판이 아니다 — 수동 등록 위키(관리 대상 아님)도 여기서 걸린다.
 * 2. 유형이 `basic` 이다 — 위키 화면은 기본형 게시판에만 있다.
 * 3. 글 행이 0 이다 — 휴지통·답글·공지 포함. 글이 있는 게시판에 시드를 섞지 않는다.
 * 4. 카테고리가 0 개다 — 위키 분류는 `[[분류:…]]` 문서로 한다.
 *
 * 글에 붙지 않은 임시 첨부는 보지 않는다(2일 뒤 자동 삭제되고 게시판의 내용이 아니다).
 */
final class ConvertEligibility
{
    public const OK = null;

    public const ALREADY_WIKI = 'already_wiki';

    public const NOT_BASIC = 'not_basic';

    public const HAS_POSTS = 'has_posts';

    public const HAS_CATEGORIES = 'has_categories';

    /**
     * 거부 사유 (통과면 null).
     */
    public static function reason(BoardFacts $facts): ?string
    {
        if ($facts->alreadyWiki) {
            return self::ALREADY_WIKI;
        }

        if ($facts->type !== NewBoardData::BOARD_TYPE) {
            return self::NOT_BASIC;
        }

        if ($facts->postRows > 0) {
            return self::HAS_POSTS;
        }

        if ($facts->categoryCount > 0) {
            return self::HAS_CATEGORIES;
        }

        return self::OK;
    }

    /**
     * 위키화할 수 있는가.
     */
    public static function allows(BoardFacts $facts): bool
    {
        return self::reason($facts) === self::OK;
    }

    /**
     * 거부 사유의 HTTP 상태 — 이미 위키면 충돌(409), 나머지는 입력 문제(422).
     */
    public static function status(string $reason): int
    {
        return $reason === self::ALREADY_WIKI ? 409 : 422;
    }
}
