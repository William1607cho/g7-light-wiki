<?php

namespace Plugins\G7\Light\Wiki\Support;

use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;

/**
 * 대문 글의 봇 SSR 캐시를 비운다.
 *
 * 코어 `SeoBoardCacheListener` 는 글을 저장할 때 **그 글 상세 URL 과 그 게시판 목록 URL**
 * 만 무효화한다. 대문 글에 실린 "최근 수정·색인" 은 다른 글을 고쳤을 때 낡으므로,
 * 위키 문서가 바뀔 때마다 대문 글 URL 을 따로 비운다.
 *
 * 무효화 수단은 코어가 확장에 열어 둔 `SeoCacheManagerInterface::invalidateByUrl()` 이다
 * (`App\Seo\SeoInvalidationRegistry` 주석이 확장의 직접 호출을 명시한다). 봇 캐시 키는
 * 쿼리스트링 변종까지 포함해 저장되므로 와일드카드 변종도 함께 비운다.
 *
 * **대문 글 자신을 저장할 때는 부르지 않는다** — 코어가 이미 비우고 즉시 재생성까지 한다.
 */
final class FrontCacheInvalidator
{
    /**
     * 이 게시판의 대문 글 봇 캐시를 비운다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $frontPostId  대문 글 ID
     * @param  int  $savedPostId  방금 저장된 글 ID (대문 글 자신이면 아무것도 하지 않는다)
     */
    public static function invalidate(string $slug, int $frontPostId, int $savedPostId): void
    {
        if ($frontPostId <= 0 || $frontPostId === $savedPostId) {
            return;
        }

        try {
            $cache = app(SeoCacheManagerInterface::class);
            $url = WikiUrl::post($slug, $frontPostId);

            $cache->invalidateByUrl($url);
            $cache->invalidateByUrl($url.'?*');
        } catch (\Throwable $e) {
            // 캐시를 못 비운 것이 글 저장을 막으면 안 된다 — 봇 화면이 TTL 만큼 낡을 뿐이다.
            Log::warning('[g7-light-wiki] 대문 글 봇 캐시 무효화 실패', [
                'board_slug' => $slug,
                'front_post_id' => $frontPostId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
