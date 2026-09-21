<?php

namespace Plugins\G7\Light\Wiki\Support;

use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;

/**
 * 자리표시가 실린 문서들의 봇 SSR 캐시를 비운다.
 *
 * 코어 `SeoBoardCacheListener` 는 글을 저장할 때 **그 글 상세 URL 과 그 게시판 목록 URL**
 * 만 무효화한다. 자리표시("최근 작성·최근 수정·랜덤·색인·둘러보기")는 **다른 글**을 고쳤을 때
 * 낡으므로, 위키 문서가 바뀔 때마다 자리표시가 실린 문서들의 URL 을 따로 비운다.
 *
 * 무효화 수단은 코어가 확장에 열어 둔 `SeoCacheManagerInterface::invalidateByUrl()` 이다
 * (`App\Seo\SeoInvalidationRegistry` 주석이 확장의 직접 호출을 명시한다). 봇 캐시 키는
 * 쿼리스트링 변종까지 포함해 저장되므로 와일드카드 변종도 함께 비운다.
 *
 * **방금 저장된 글 자신은 건너뛴다** — 코어가 이미 비우고 즉시 재생성까지 한다.
 */
final class FrontCacheInvalidator
{
    /**
     * 글 여러 개의 봇 캐시를 한 번에 비운다.
     *
     * 0 이하·중복·방금 저장된 글은 알아서 걸러낸다. 한 건이 실패해도 나머지는 계속 비운다 —
     * 캐시를 못 비운 것이 글 저장을 막으면 안 되고, 하나가 막혔다고 나머지까지 낡히면
     * 화면이 더 오래 어긋난다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  list<int>  $postIds  비울 글 ID 목록
     * @param  int  $savedPostId  방금 저장된 글 ID (목록에 있어도 건너뛴다)
     */
    public static function invalidatePosts(string $slug, array $postIds, int $savedPostId): void
    {
        if ($slug === '') {
            return;
        }

        $targets = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $postIds),
            static fn (int $id): bool => $id > 0 && $id !== $savedPostId
        )));

        if ($targets === []) {
            return;
        }

        try {
            $cache = app(SeoCacheManagerInterface::class);
        } catch (\Throwable $e) {
            Log::warning('[g7-light-wiki] 봇 캐시 관리자를 가져오지 못했습니다', [
                'board_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($targets as $postId) {
            try {
                $url = WikiUrl::post($slug, $postId);

                $cache->invalidateByUrl($url);
                $cache->invalidateByUrl($url.'?*');
            } catch (\Throwable $e) {
                // 봇 화면이 TTL 만큼 낡을 뿐이다. 저장은 계속된다.
                Log::warning('[g7-light-wiki] 문서 봇 캐시 무효화 실패', [
                    'board_slug' => $slug,
                    'post_id' => $postId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
