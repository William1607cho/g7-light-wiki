<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use App\Enums\TotalRelation;
use App\Support\Query\BoundedCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Modules\Sirsoft\Board\Http\Resources\PostCollection;
use Plugins\G7\Light\Wiki\Support\WikiBoardListQuery;
use Plugins\G7\Light\Wiki\Support\WikiPostLoader;

/**
 * 위키 게시판의 **글 목록 응답**을 문서 목록으로 바꾼다.
 *
 * 미들웨어에서 떼어 냈다. 미들웨어는 "위키 게시판인가" 를 보고 이쪽으로 넘기는 일만 한다.
 *
 * ## 무엇을 쥐고 무엇을 넘기는가
 *
 * **어느 문서를 늘어놓을지는 {@see DocListTarget} 이 고른다** — 검색·최근 수정·무작위·대문의
 * 규칙과 "검색어가 모드보다 먼저다" 는 전부 그쪽에 있다. 이 클래스는 고른 결과를 받아
 * **응답을 되쓰는 일**만 한다.
 *
 * ## 갈아 끼우지 않아도 `board.wiki` 는 싣는다
 *
 * 위키 게시판이면 **어느 경로로 나가든** 게시판 정보에 {@see WikiBoardFlag} 의 칸을 얹는다.
 * 대문이 지정되지 않아 목록을 그대로 두는 경우도 마찬가지다 — 값이 들쭉날쭉하면 같은
 * 게시판의 화면이 요청마다 달라진다. 위키가 **아닌** 게시판에는 미들웨어가 여기까지 오지 않는다.
 *
 * 공지 글도 함께 걸러진다. 코어는 공지를 별도 목록이 아니라 같은 `data` 배열 앞에 섞어
 * 보내므로(`PostRepository::buildSortedPostList()`), 목록을 갈아 끼우면 공지도 빠진다.
 * **대문 글만은 공지여도 남는다** ({@see WikiBoardListQuery} 의 공지 예외).
 *
 * ## 항목을 손으로 만들지 않는다
 *
 * 항목 변환은 **코어 것을 그대로 쓴다**: `PostCollection` → `PostResource::toListArray()`.
 * 글도 코어 `PostRepository::paginate()` 와 같은 컬럼·같은 관계로 적재한다
 * ({@see WikiPostLoader}). 그래서 비밀글 미리보기 가림 같은 코어 규칙이 그대로 적용되고,
 * 항목의 키 집합·값 형식이 원본 응답과 같다.
 *
 * `board` 와 `abilities` 는 **원본 응답의 것을 그대로 둔다** — 같은 게시판·같은 요청자라
 * 값이 같다. 바꾸는 것은 `data` 와 `pagination` 뿐이다.
 *
 * 페이지 크기와 현재 페이지도 **원본 응답의 `pagination` 에서 읽는다.** 게시판 설정과
 * 모바일 판정이 이미 반영된 값이라, 같은 규칙을 다시 구현하지 않는다. 그래서 최근 수정
 * 목록의 한 쪽 개수도 게시판 설정을 그대로 따른다(PC 와 모바일이 다를 수 있다).
 * 무작위 모드만은 현재 페이지를 **1 로 고정**하고 **뽑는 개수도 그 한 쪽에 맞춘다** —
 * 요청마다 순서가 달라 2쪽이 성립하지 않으므로, 한 쪽에 안 들어가는 문서를 뽑아 봐야
 * 볼 수 없는 채로 모바일에 뜻 없는 2쪽 페이저만 만든다.
 */
final class DocListRenderer
{
    /** 어느 문서를 늘어놓을지 고르는 쪽 */
    private readonly DocListTarget $target;

    public function __construct(
        private readonly string $slug,
        private readonly int $boardId,
        private readonly Request $request,
    ) {
        $this->target = new DocListTarget($slug, $boardId, $request);
    }

    public function apply(JsonResponse $response): JsonResponse
    {
        $data = $response->getData(true);
        $payload = $data['data'] ?? null;

        if (! is_array($payload)) {
            // 모양을 모르는 응답은 건드리지 않는다.
            return $response;
        }

        $mode = $this->target->mode();

        // 갈아 끼우지 못해도 깃발은 싣는다(위 "갈아 끼우지 않아도" 참조).
        $payload = $this->rebuild($payload, $mode) ?? $payload;

        $data['data'] = WikiBoardFlag::withWiki($payload, WikiBoardFlag::listValue($mode));

        // setData 는 이 응답이 쥐고 있는 인코딩 옵션 그대로 되쓴다.
        $response->setData($data);

        return $response;
    }

    /**
     * 코어 글 목록을 문서 목록으로 갈아 끼운다.
     *
     * `null` 은 "갈아 끼우지 않는다" 는 뜻이다 — 응답 모양이 다르거나, 대문이 지정되지
     * 않았거나, 검색어가 정규화 후 빈 문자열이 되는 경우다.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function rebuild(array $payload, string $mode): ?array
    {
        if (! isset($payload['data']) || ! is_array($payload['data'])
            || ! isset($payload['pagination']) || ! is_array($payload['pagination'])) {
            return null;
        }

        $perPage = (int) ($payload['pagination']['per_page'] ?? 0);

        if ($perPage < 1) {
            return null;
        }

        $target = $this->target->targetIds($mode, $perPage);

        if ($target === null) {
            return null;
        }

        $postIds = $target['ids'];

        // 무작위는 요청마다 순서가 달라 2쪽이 성립하지 않는다(1쪽 항목이 2쪽에 다시 나온다).
        $page = $mode === ListMode::RANDOM
            ? 1
            : max(1, (int) ($payload['pagination']['current_page'] ?? 1));

        // 코어 simplePaginate 와 같은 방식: 한 개 더 떠서 "다음 쪽이 있는가" 를 판정한다.
        $window = array_slice($postIds, ($page - 1) * $perPage, $perPage + 1);
        $posts = WikiPostLoader::load($this->boardId, $window);

        // 상한에 걸려 잘렸으면 총 건수를 "정확히 N" 이라고 말하지 않는다 — 코어가
        // `BoundedCount` 로 정확도를 함께 나르는 이유가 그것이다.
        $total = new BoundedCount(
            count($postIds),
            $target['truncated'] ? TotalRelation::AtLeast : TotalRelation::Exact,
            $target['truncated'] ? WikiBoardListQuery::LIST_LIMIT : null,
        );

        $collection = new PostCollection(new Paginator($posts, $perPage, $page));
        $collection->setTotalNormalPosts($total);
        $collection->setOrderDirection('asc');

        $built = $collection->toArray($this->request);

        $payload['data'] = $this->withBoardKeys($built['data'], $payload['board'] ?? []);
        $payload['pagination'] = $built['pagination'];

        return $payload;
    }

    /**
     * 항목마다 `show_category`·`slug` 를 얹는다 — 코어 `PostCollection::withBoardInfo()` 와 같은 규칙.
     *
     * @param  iterable<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $board  원본 응답의 게시판 정보
     * @return list<array<string, mixed>>
     */
    private function withBoardKeys(iterable $items, array $board): array
    {
        $showCategory = $board['show_category'] ?? false;
        $out = [];

        foreach ($items as $item) {
            $item['show_category'] = $showCategory;
            $item['slug'] = $board['slug'] ?? $this->slug;

            $out[] = $item;
        }

        return $out;
    }
}
