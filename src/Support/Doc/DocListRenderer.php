<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use App\Enums\TotalRelation;
use App\Support\Query\BoundedCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Modules\Sirsoft\Board\Http\Resources\PostCollection;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;
use Plugins\G7\Light\Wiki\Support\WikiDocListSource;
use Plugins\G7\Light\Wiki\Support\WikiPostLoader;

/**
 * 위키 게시판의 **글 목록 응답**을 문서 목록으로 바꾼다.
 *
 * 미들웨어에서 떼어 냈다. 미들웨어는 "위키 게시판인가" 를 보고 이쪽으로 넘기는 일만 한다.
 *
 * ## 무엇으로 바꾸는가
 *
 * | 요청 | 결과 |
 * |---|---|
 * | 검색어 있음 | 정규화 제목에 정규화 검색어가 **들어 있는** 문서. `title_norm` 오름차순 |
 * | `?sort_by=g7lw-recent` | **최근 수정순** 전체 문서. 페이지 넘김 있음 |
 * | `?sort_by=g7lw-random` | **무작위** 문서 10건. **1쪽 고정** |
 * | 그 밖(모드 없음·허용 밖 값) | **대문 글 1건**만 담은 목록. 대문이 지정돼 있지 않으면 원본 그대로 |
 *
 * **검색어가 모드보다 먼저다.** 화면에 검색창이 있고, 사용자가 방금 친 것이 검색어다.
 * 검색어가 있으면 `sort_by` 는 무시한다(페이지 넘김도 검색 결과 기준으로 돈다).
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
 * 항목의 키 집합·값 형식이 원본 응답과 같다.
 *
 * `board` 와 `abilities` 는 **원본 응답의 것을 그대로 둔다** — 같은 게시판·같은 요청자라
 * 값이 같다. 바꾸는 것은 `data` 와 `pagination` 뿐이다.
 *
 * 페이지 크기와 현재 페이지도 **원본 응답의 `pagination` 에서 읽는다.** 게시판 설정과
 * 모바일 판정이 이미 반영된 값이라, 같은 규칙을 다시 구현하지 않는다. 그래서 최근 수정
 * 목록의 한 쪽 개수도 게시판 설정을 그대로 따른다(PC 와 모바일이 다를 수 있다).
 * 무작위 모드만은 현재 페이지를 **1 로 고정**한다 — 요청마다 순서가 달라 2쪽이 성립하지 않는다.
 */
final class DocListRenderer
{
    public function __construct(
        private readonly string $slug,
        private readonly int $boardId,
        private readonly Request $request,
    ) {}

    public function apply(JsonResponse $response): JsonResponse
    {
        $data = $response->getData(true);
        $payload = $data['data'] ?? null;

        if (! is_array($payload)
            || ! isset($payload['data']) || ! is_array($payload['data'])
            || ! isset($payload['pagination']) || ! is_array($payload['pagination'])) {
            return $response;
        }

        $perPage = (int) ($payload['pagination']['per_page'] ?? 0);

        if ($perPage < 1) {
            return $response;
        }

        $mode = $this->mode();
        $target = $this->targetIds($mode);

        if ($target === null) {
            return $response;
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
            $target['truncated'] ? WikiDocListSource::LIST_LIMIT : null,
        );

        $collection = new PostCollection(new Paginator($posts, $perPage, $page));
        $collection->setTotalNormalPosts($total);
        $collection->setOrderDirection('asc');

        $built = $collection->toArray($this->request);

        $payload['data'] = $this->withBoardKeys($built['data'], $payload['board'] ?? []);
        $payload['pagination'] = $built['pagination'];

        $data['data'] = $payload;

        // setData 는 이 응답이 쥐고 있는 인코딩 옵션 그대로 되쓴다.
        $response->setData($data);

        return $response;
    }

    /**
     * 이 요청이 그릴 모드 — 검색어가 있으면 모드는 없는 것으로 본다.
     *
     * 검색 결과는 페이지 넘김이 정상으로 돌아야 하므로, 무작위 모드의 "1쪽 고정" 이
     * 검색 결과에 걸리면 안 된다.
     */
    private function mode(): string
    {
        return $this->rawSearch() === '' ? ListMode::fromRequest($this->request) : ListMode::NONE;
    }

    /**
     * 이 요청이 보여 줄 문서의 글 ID 목록.
     *
     * `null` 은 "원본 응답을 그대로 두라" 는 뜻이다 — 대문이 지정되지 않았거나,
     * 검색어가 정규화 후 빈 문자열이 되는 경우다(빈 검색어로 전부 훑지 않는다).
     *
     * @return array{ids: list<int>, truncated: bool}|null
     */
    private function targetIds(string $mode): ?array
    {
        $search = $this->rawSearch();

        if ($search !== '') {
            return $this->searchIds($search);
        }

        return match ($mode) {
            ListMode::RECENT => $this->recentIds(),
            ListMode::RANDOM => $this->randomIds(),
            default => $this->frontIds(),
        };
    }

    /**
     * 제목 검색 결과.
     *
     * 상한 판정을 **거른 뒤 건수**로 한다 — 조회가 이미 노출 조건을 걸고 자르기 때문이다.
     *
     * @return array{ids: list<int>, truncated: bool}|null
     */
    private function searchIds(string $search): ?array
    {
        $normalized = TitleNormalizer::normalize($search);

        if ($normalized === '') {
            return null;
        }

        $found = WikiDocListSource::searchPostIds(
            $this->slug,
            $this->boardId,
            $normalized,
            WikiDocListSource::LIST_LIMIT
        );

        return [
            'ids' => $found,
            'truncated' => count($found) >= WikiDocListSource::LIST_LIMIT,
        ];
    }

    /**
     * 최근 수정순 전체 문서.
     *
     * @return array{ids: list<int>, truncated: bool}
     */
    private function recentIds(): array
    {
        $ids = WikiDocListSource::recentPostIds(
            $this->slug,
            $this->boardId,
            WikiDocListSource::LIST_LIMIT
        );

        return [
            'ids' => $ids,
            'truncated' => count($ids) >= WikiDocListSource::LIST_LIMIT,
        ];
    }

    /**
     * 무작위 문서 — 쿨다운 안이면 직전에 뽑은 것을 그대로 쓴다.
     *
     * 되쓰는 id 도 노출 판정을 다시 받는다. 3초 사이에 글이 지워지거나 권한이 바뀔 수 있고,
     * 그때 안 보여야 할 문서를 캐시가 되살리면 안 된다.
     *
     * @return array{ids: list<int>, truncated: bool}
     */
    private function randomIds(): array
    {
        $ids = RandomDrawCache::remember(
            $this->boardId,
            $this->request,
            fn (): array => WikiDocListSource::randomPostIds(
                $this->slug,
                $this->boardId,
                WikiDocListSource::RANDOM_COUNT
            )
        );

        return [
            'ids' => WikiDocListSource::visiblePostIds($this->slug, $ids),
            'truncated' => false,
        ];
    }

    /**
     * 대문 글 1건 — 모드도 검색어도 없을 때의 목록.
     *
     * @return array{ids: list<int>, truncated: bool}|null
     */
    private function frontIds(): ?array
    {
        $frontPostId = WikiBoardSettings::frontPostId($this->boardId);

        if ($frontPostId === null) {
            return null;
        }

        return [
            'ids' => WikiDocListSource::visiblePostIds($this->slug, [$frontPostId]),
            'truncated' => false,
        ];
    }

    /** 요청의 검색어 원문 (앞뒤 공백만 털어 낸 것) */
    private function rawSearch(): string
    {
        $search = $this->request->query('search');

        return is_string($search) ? trim($search) : '';
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
