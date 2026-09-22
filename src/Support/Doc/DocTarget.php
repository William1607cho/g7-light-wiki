<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

/**
 * 지금 그리고 있는 문서가 무엇인가 — 게시판과 글을 가리키는 값 묶음.
 *
 * 전에는 `slug`·`boardId`·`postId`·`titleNorm` 네 값이 미들웨어의 메서드마다 인자로
 * 따라다녔다(`context()` 는 인자가 8개였다). 늘 함께 움직이는 값이라 하나로 묶었다.
 */
final class DocTarget
{
    /**
     * @param  string  $slug  게시판 슬러그
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  글 ID (없으면 0)
     * @param  string  $titleNorm  정규화한 제목 (없으면 빈 문자열)
     */
    public function __construct(
        public readonly string $slug,
        public readonly int $boardId,
        public readonly int $postId,
        public readonly string $titleNorm,
    ) {}
}
