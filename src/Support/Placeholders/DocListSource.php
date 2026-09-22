<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

/**
 * 자리표시 렌더러가 쓰는 목록 공급자.
 *
 * 렌더러는 **DB 를 모른다.** 전에는 호출부가 클로저 여섯 개를 생성자로 밀어 넣어
 * 인자가 11개까지 늘었다. 그 클로저들이 하는 일이 결국 "목록 하나 가져오기" 라서,
 * 인터페이스 하나로 묶었다.
 *
 * 실제 구현은 {@see \Plugins\G7\Light\Wiki\Support\DocLists} 다. 단위 시험은 이 인터페이스의
 * 가짜 구현을 넣어 치환 결과만 본다.
 *
 * ## 같은 목록을 두 번 묻지 않는다 — 랜덤만 빼고
 *
 * 결과가 정해져 있는 것(최근수정·최근작성·색인·연표)은 구현이 기억해 두고 한 번만 조회한다.
 * **랜덤은 일부러 기억하지 않는다** — 한 문서에 랜덤을 두 번 쓰면 서로 다른 문서가
 * 나오는 편이 자연스럽다.
 */
interface DocListSource
{
    /**
     * 최근 수정 문서.
     *
     * @return list<array{post_id: int, title: string}>
     */
    public function recent(int $limit): array;

    /**
     * 최근 작성 문서.
     *
     * @return list<array{post_id: int, title: string}>
     */
    public function created(int $limit): array;

    /**
     * 색인에 늘어놓을 문서 전부.
     *
     * @return list<array{id: int, title: string, title_norm: string}>
     */
    public function index(): array;

    /** 랜덤 문서 1건의 글 ID (후보가 없으면 null). */
    public function randomOne(): ?int;

    /**
     * 랜덤 문서 N 건 (서로 다른 문서).
     *
     * @return list<array{post_id: int, title: string}>
     */
    public function randomMany(int $limit): array;

    /**
     * 그 분류에 속한 문서.
     *
     * @return array{items: list<array{post_id: int, title: string}>, more: int}
     */
    public function membersOf(string $name): array;

    /**
     * 사건 목록 — 이름이 있으면 그 문서와 그 문서를 가리킨 문서들의 것.
     *
     * @return array{items: list<array{post_id: int, title: string, target: string, label: string}>, more: int}
     */
    public function timeline(?string $docName): array;
}
