<?php

namespace Plugins\G7\Light\Wiki\Http\Middleware;

use App\Enums\TotalRelation;
use App\Support\Query\BoundedCount;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Http\Resources\PostCollection;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;
use Plugins\G7\Light\Wiki\Support\WikiDocQuery;
use Plugins\G7\Light\Wiki\Support\WikiPostLoader;

/**
 * 위키 게시판의 **글 목록 응답**을 문서 목록으로 바꾼다.
 *
 * 붙는 곳은 `plugin.php::getMiddleware()` 의 `targets` 하나뿐이다:
 * `api.modules.sirsoft-board.boards.posts.index`.
 * 관리자 게시물 API(`api.modules.sirsoft-board.admin.*`)와 홈 위젯 API 는 대상이 아니라
 * 코어 게이트(`ExtensionMiddlewareGate`)가 이 미들웨어를 **실행조차 하지 않는다.**
 *
 * ## 첫 줄에서 가른다
 *
 * 위키 게시판이 아니면 **응답 객체를 건드리지 않는다**. 코어가 200 이 아닌 응답
 * (비공개 게시판의 401 등)을 냈을 때도 그대로 내보낸다.
 *
 * ## 무엇으로 바꾸는가
 *
 * | 요청 | 결과 |
 * |---|---|
 * | 검색어 없음 | **대문 글 1건**만 담은 목록. 대문이 지정돼 있지 않으면 원본 그대로 |
 * | 검색어 있음 | 정규화 제목에 정규화 검색어가 **들어 있는** 문서. `title_norm` 오름차순 |
 *
 * 한 글자 검색도 받는다. 검색 대상 필드(제목·내용·작성자)를 무엇으로 고르든 **제목 검색**으로
 * 처리한다 — 위키에서 제목은 문서 이름이고, 코어 사용자 목록은 어차피 `search_field` 를
 * `all` 로 고정한다.
 *
 * 공지 글도 함께 걸러진다. 코어는 공지를 별도 목록이 아니라 같은 `data` 배열 앞에 섞어
 * 보내므로(`PostRepository::buildSortedPostList()`), 목록을 갈아 끼우면 공지도 빠진다.
 *
 * ## 항목을 손으로 만들지 않는다
 *
 * 항목 변환은 **코어 것을 그대로 쓴다**: `PostCollection` → `PostResource::toListArray()`.
 * 글도 코어 `PostRepository::paginate()` 와 같은 컬럼·같은 관계로 적재한다
 * ({@see WikiPostLoader}). 그래서 비밀글 미리보기 가림 같은 코어 규칙이 그대로 적용되고,
 * 항목의 키 집합·값 형식이 원본 응답과 같다(2026-09-21 실측: 키 29개 일치).
 *
 * `board` 와 `abilities` 는 **원본 응답의 것을 그대로 둔다** — 같은 게시판·같은 요청자라
 * 값이 같다. 바꾸는 것은 `data` 와 `pagination` 뿐이다.
 *
 * 페이지 크기와 현재 페이지도 **원본 응답의 `pagination` 에서 읽는다.** 게시판 설정과
 * 모바일 판정이 이미 반영된 값이라, 같은 규칙을 다시 구현하지 않는다.
 */
class WikiBoardListExtension
{
    /** 한 번에 다룰 문서의 최대 수 (색인 자리표시와 같은 상한) */
    private const MAX_DOCS = WikiDocQuery::INDEX_LIMIT;

    public function handle(Request $request, Closure $next): mixed
    {
        $slug = (string) $request->route('slug');
        $boardId = $slug === '' ? null : BoardLookup::id($slug);

        if (! WikiBoardSettings::isWikiBoard($boardId)) {
            return $next($request);
        }

        $response = $next($request);

        if (! $response instanceof JsonResponse || $response->getStatusCode() !== 200) {
            return $response;
        }

        try {
            return $this->rewrite($request, $response, $slug, (int) $boardId);
        } catch (\Throwable $e) {
            // 가공 실패가 목록 조회 자체를 막으면 안 된다 — 원본 응답을 그대로 내보낸다.
            Log::warning('[g7-light-wiki] 위키 목록 가공 실패 (원본 응답을 그대로 내보냅니다)', [
                'board_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return $response;
        }
    }

    /**
     * 목록 응답 하나를 문서 목록으로 바꿔 되쓴다.
     */
    private function rewrite(Request $request, JsonResponse $response, string $slug, int $boardId): JsonResponse
    {
        $data = $response->getData(true);
        $payload = $data['data'] ?? null;

        if (! is_array($payload)
            || ! isset($payload['data']) || ! is_array($payload['data'])
            || ! isset($payload['pagination']) || ! is_array($payload['pagination'])) {
            return $response;
        }

        $perPage = (int) ($payload['pagination']['per_page'] ?? 0);
        $page = max(1, (int) ($payload['pagination']['current_page'] ?? 1));

        if ($perPage < 1) {
            return $response;
        }

        $target = $this->targetIds($request, $boardId, $slug);

        if ($target === null) {
            return $response;
        }

        $postIds = $target['ids'];

        // 코어 simplePaginate 와 같은 방식: 한 개 더 떠서 "다음 쪽이 있는가" 를 판정한다.
        $window = array_slice($postIds, ($page - 1) * $perPage, $perPage + 1);
        $posts = WikiPostLoader::load($boardId, $window);

        // 상한에 걸려 잘렸으면 총 건수를 "정확히 N" 이라고 말하지 않는다 — 코어가
        // `BoundedCount` 로 정확도를 함께 나르는 이유가 그것이다.
        $total = new BoundedCount(
            count($postIds),
            $target['truncated'] ? TotalRelation::AtLeast : TotalRelation::Exact,
            $target['truncated'] ? self::MAX_DOCS : null,
        );

        $collection = new PostCollection(new Paginator($posts, $perPage, $page));
        $collection->setTotalNormalPosts($total);
        $collection->setOrderDirection('asc');

        $built = $collection->toArray($request);

        $payload['data'] = $this->withBoardKeys($built['data'], $payload['board'] ?? [], $slug);
        $payload['pagination'] = $built['pagination'];

        $data['data'] = $payload;

        // setData 는 이 응답이 쥐고 있는 인코딩 옵션 그대로 되쓴다.
        $response->setData($data);

        return $response;
    }

    /**
     * 이 요청이 보여 줄 문서의 글 ID 목록.
     *
     * `null` 은 "원본 응답을 그대로 두라" 는 뜻이다 — 대문이 지정되지 않았거나,
     * 검색어가 정규화 후 빈 문자열이 되는 경우다(빈 검색어로 전부 훑지 않는다).
     *
     * @return array{ids: list<int>, truncated: bool}|null
     */
    private function targetIds(Request $request, int $boardId, string $slug): ?array
    {
        $search = $request->query('search');
        $search = is_string($search) ? trim($search) : '';

        if ($search === '') {
            $frontPostId = WikiBoardSettings::frontPostId($boardId);

            if ($frontPostId === null) {
                return null;
            }

            return [
                'ids' => WikiDocQuery::visiblePostIds($slug, [$frontPostId]),
                'truncated' => false,
            ];
        }

        $normalized = TitleNormalizer::normalize($search);

        if ($normalized === '') {
            return null;
        }

        $found = WikiDocQuery::searchPostIds($boardId, $normalized, self::MAX_DOCS);

        return [
            'ids' => WikiDocQuery::visiblePostIds($slug, $found),
            'truncated' => count($found) >= self::MAX_DOCS,
        ];
    }

    /**
     * 항목마다 `show_category`·`slug` 를 얹는다 — 코어 `PostCollection::withBoardInfo()` 와 같은 규칙.
     *
     * @param  iterable<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $board  원본 응답의 게시판 정보
     * @return list<array<string, mixed>>
     */
    private function withBoardKeys(iterable $items, array $board, string $slug): array
    {
        $showCategory = $board['show_category'] ?? false;
        $out = [];

        foreach ($items as $item) {
            $item['show_category'] = $showCategory;
            $item['slug'] = $board['slug'] ?? $slug;

            $out[] = $item;
        }

        return $out;
    }
}
