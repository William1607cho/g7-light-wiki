<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Illuminate\Http\Request;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * 목록 화면이 어느 모드로 그려질지 — `sort_by` 한 칸에서 읽는다.
 *
 * 템플릿(`board/index`)이 목록 API 로 넘기는 파라미터는 화이트리스트라 새 이름을 만들 수 없다.
 * 이미 넘어가는 `sort_by` 에 얹는 이유가 그것이고, 주소를 **만드는** 쪽도 같은 상수를 쓴다
 * ({@see WikiUrl::SORT_RECENT}·{@see WikiUrl::SORT_RANDOM}) — 만드는 쪽과 읽는 쪽이
 * 다른 글자를 들고 있으면 한쪽만 고쳤을 때 링크가 조용히 죽는다.
 *
 * 허용 밖의 값은 {@see NONE} 이다. 코어도 모르는 정렬 값을 오류 없이 `id` 로 폴백하므로
 * (`PostRepository::buildSortedPostList()` 의 정렬 화이트리스트), 오타 하나가 500 이 되지 않는다.
 */
final class ListMode
{
    /** 모드 없음 — 대문 글 1건(지금까지의 동작) */
    public const NONE = 'none';

    /** 최근 수정순 목록 */
    public const RECENT = 'recent';

    /** 무작위 목록 (1쪽 고정) */
    public const RANDOM = 'random';

    /**
     * 요청의 `sort_by` 에서 모드를 읽는다.
     */
    public static function fromRequest(Request $request): string
    {
        return self::fromSortBy($request->query('sort_by'));
    }

    /**
     * `sort_by` 값 하나에서 모드를 정한다 — 요청 객체 없이도 판정할 수 있게 갈라 둔다.
     *
     * 앞뒤 공백만 털어 내고 **대소문자는 구분한다.** 주소는 우리가 만들고, 느슨하게 받으면
     * `G7LW-RECENT` 같은 변종이 봇 캐시 키를 하나 더 만든다(캐시 키는 쿼리 원문으로 갈린다).
     */
    public static function fromSortBy(mixed $sortBy): string
    {
        if (! is_string($sortBy)) {
            return self::NONE;
        }

        return match (trim($sortBy)) {
            WikiUrl::SORT_RECENT => self::RECENT,
            WikiUrl::SORT_RANDOM => self::RANDOM,
            default => self::NONE,
        };
    }
}
