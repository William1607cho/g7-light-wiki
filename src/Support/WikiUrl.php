<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 주소 조립 — 프론트 경로와 이 플러그인 API 경로를 한곳에서 만든다.
 *
 * 프론트 경로는 `templates/wc-community/routes.json` 의 것과 같아야 한다:
 * 상세 `/board/{slug}/{id}`, 작성 `/board/{slug}/write`, 목록 `/board/{slug}`.
 */
final class WikiUrl
{
    /** 플러그인 API 접두어 (`PluginRouteServiceProvider` 가 붙이는 것과 같다) */
    public const API_PREFIX = '/api/plugins/g7-light-wiki';

    /** 목록 화면의 위키 전용 정렬 — 최근 수정순 */
    public const SORT_RECENT = 'g7lw-recent';

    /** 목록 화면의 위키 전용 정렬 — 무작위 */
    public const SORT_RANDOM = 'g7lw-random';

    /**
     * 글 상세 화면.
     */
    public static function post(string $slug, int $postId): string
    {
        return '/board/'.rawurlencode($slug).'/'.$postId;
    }

    /**
     * 게시판 목록 화면 — 위키 전용 정렬을 얹을 수 있다.
     *
     * 템플릿(`board/index`)이 API 로 넘기는 파라미터는 화이트리스트라 새 이름을 만들 수 없다.
     * 이미 넘어가는 `sort_by` 에 얹는 이유가 그것이다. 코어는 모르는 정렬 값을 오류 없이
     * `id` 로 폴백하므로, 목록 가공이 아직 없는 동안에도 링크가 깨지지 않는다.
     *
     * `$sortBy` 는 위 두 상수만 받는다. **그 밖의 값은 무시하고** 정렬 없는 목록 주소를 준다 —
     * 오타 하나가 죽은 링크가 되는 것보다, 정렬만 빠진 정상 목록으로 가는 편이 낫다.
     *
     * 주소는 언제나 **상대 경로**다. 절대 주소는 같은 사이트라도 외부 링크로 분류돼
     * 새 탭에서 열린다(2026-09-21).
     */
    public static function boardList(string $slug, string $sortBy = ''): string
    {
        $path = '/board/'.rawurlencode($slug);

        if (! in_array($sortBy, [self::SORT_RECENT, self::SORT_RANDOM], true)) {
            return $path;
        }

        return $path.'?'.http_build_query(['sort_by' => $sortBy]);
    }

    /**
     * 글 작성 화면.
     */
    public static function write(string $slug): string
    {
        return '/board/'.rawurlencode($slug).'/write';
    }

    /**
     * 없는 문서의 빨간 링크가 가는 곳 — 제목을 세션에 한 번 싣고 작성 화면으로 302 한다.
     */
    public static function newDoc(int $boardId, string $title): string
    {
        return self::API_PREFIX.'/new?'.http_build_query(['board' => $boardId, 'title' => $title]);
    }
}
