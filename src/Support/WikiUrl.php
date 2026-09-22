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

    /**
     * 글 상세 화면.
     */
    public static function post(string $slug, int $postId): string
    {
        return '/board/'.rawurlencode($slug).'/'.$postId;
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
