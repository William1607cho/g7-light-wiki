<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Illuminate\Http\Request;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiBoardListQuery;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 목록 화면이 **어느 문서를 늘어놓을지** 고른다 — 요청 하나에 대한 글 ID 목록.
 *
 * {@see DocListRenderer} 에서 떼어 냈다(동작 무변경 정리). 렌더러는 "응답을 어떻게 되쓰는가"
 * 만 쥐고, "무엇을 고르는가" 는 이쪽에 있다. 둘은 한 요청 안에서만 살아 있는 값 객체다.
 *
 * ## 무엇을 고르는가
 *
 * | 요청 | 고르는 것 |
 * |---|---|
 * | 검색어 있음 | 정규화 제목에 정규화 검색어가 **들어 있는** 문서. `title_norm` 오름차순 |
 * | `?sort_by=g7lw-recent` | **최근 수정순** 전체 문서 |
 * | `?sort_by=g7lw-random` | **무작위** `min(20, 한 쪽 개수)`건 |
 * | 그 밖 | **대문 글 1건**. 대문이 지정돼 있지 않으면 `null`(원본 그대로) |
 *
 * **검색어가 모드보다 먼저다.** 화면에 검색창이 있고, 사용자가 방금 친 것이 검색어다.
 * 검색어가 있으면 `sort_by` 는 무시한다(페이지 넘김도 검색 결과 기준으로 돈다).
 *
 * 한 글자 검색도 받는다. 검색 대상 필드(제목·내용·작성자)를 무엇으로 고르든 **제목 검색**으로
 * 처리한다 — 위키에서 제목은 문서 이름이고, 코어 사용자 목록은 어차피 `search_field` 를
 * `all` 로 고정한다.
 *
 * 노출 조건(삭제·답글·공지·권한 범위)은 **조회 쪽 한 곳**에 있다
 * ({@see WikiBoardListQuery::coreListConditions()}). 여기서 다시 걸지 않는다.
 */
final class DocListTarget
{
    public function __construct(
        private readonly string $slug,
        private readonly int $boardId,
        private readonly Request $request,
    ) {}

    /**
     * 이 요청이 그릴 모드 — 검색어가 있으면 모드는 없는 것으로 본다.
     *
     * 검색 결과는 페이지 넘김이 정상으로 돌아야 하므로, 무작위 모드의 "1쪽 고정" 이
     * 검색 결과에 걸리면 안 된다.
     */
    public function mode(): string
    {
        return $this->rawSearch() === '' ? ListMode::fromRequest($this->request) : ListMode::NONE;
    }

    /**
     * 이 요청이 보여 줄 문서의 글 ID 목록.
     *
     * `null` 은 "원본 응답을 그대로 두라" 는 뜻이다 — 대문이 지정되지 않았거나,
     * 검색어가 정규화 후 빈 문자열이 되는 경우다(빈 검색어로 전부 훑지 않는다).
     *
     * @param  int  $perPage  이 요청의 한 쪽 개수 — 무작위 개수의 상한으로 쓰인다
     * @return array{ids: list<int>, truncated: bool}|null
     */
    public function targetIds(string $mode, int $perPage): ?array
    {
        $search = $this->rawSearch();

        if ($search !== '') {
            return $this->searchIds($search);
        }

        return match ($mode) {
            ListMode::RECENT => $this->recentIds(),
            ListMode::RANDOM => $this->randomIds($perPage),
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

        $found = WikiBoardListQuery::searchPostIds(
            $this->slug,
            $this->boardId,
            $normalized,
            WikiBoardListQuery::LIST_LIMIT
        );

        return [
            'ids' => $found,
            'truncated' => count($found) >= WikiBoardListQuery::LIST_LIMIT,
        ];
    }

    /**
     * 최근 수정순 전체 문서.
     *
     * @return array{ids: list<int>, truncated: bool}
     */
    private function recentIds(): array
    {
        $ids = WikiBoardListQuery::recentPostIds(
            $this->slug,
            $this->boardId,
            WikiBoardListQuery::LIST_LIMIT
        );

        return [
            'ids' => $ids,
            'truncated' => count($ids) >= WikiBoardListQuery::LIST_LIMIT,
        ];
    }

    /**
     * 무작위 문서 — 쿨다운 안이면 직전에 뽑은 것을 그대로 쓴다.
     *
     * 되쓰는 id 도 노출 판정을 다시 받는다. 3초 사이에 글이 지워지거나 권한이 바뀔 수 있고,
     * 그때 안 보여야 할 문서를 캐시가 되살리면 안 된다.
     *
     * **뽑는 개수는 한 쪽에 들어가는 만큼이다**({@see WikiBoardListQuery::randomCount()}).
     * 개수는 쿨다운 캐시 키에도 들어간다 — 한 쪽 개수가 다른 두 화면이 서로의 직전 결과를
     * 되쓰면 안 된다.
     *
     * @return array{ids: list<int>, truncated: bool}
     */
    private function randomIds(int $perPage): array
    {
        $count = WikiBoardListQuery::randomCount($perPage);

        $ids = RandomDrawCache::remember(
            $this->boardId,
            $this->request,
            $count,
            fn (): array => WikiBoardListQuery::randomPostIds(
                $this->slug,
                $this->boardId,
                $count
            )
        );

        return [
            'ids' => WikiBoardListQuery::visiblePostIds($this->slug, $this->boardId, $ids),
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
            'ids' => WikiBoardListQuery::visiblePostIds($this->slug, $this->boardId, [$frontPostId]),
            'truncated' => false,
        ];
    }

    /** 요청의 검색어 원문 (앞뒤 공백만 털어 낸 것) */
    private function rawSearch(): string
    {
        $search = $this->request->query('search');

        return is_string($search) ? trim($search) : '';
    }
}
