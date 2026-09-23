<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;

/**
 * 위키화 판정에 쓰는 게시판 사실 — 값 객체 + 조회.
 *
 * 판정 자체는 {@see ConvertEligibility} 가 한다. 여기서는 **세기만** 한다.
 *
 * 글 수는 `posts_count` 칸이 아니라 `board_posts` 행을 직접 센다. 그 칸은 휴지통 글을
 * 빼고 세므로(스테이징 test-wiki: 칸 29, 행 30) 휴지통에만 글이 있는 게시판을 빈
 * 게시판으로 잘못 본다. 휴지통·답글·공지·블라인드 모두 한 행으로 센다.
 */
final class BoardFacts
{
    public function __construct(
        public readonly int $boardId,
        public readonly string $type,
        public readonly int $postRows,
        public readonly int $categoryCount,
        public readonly bool $alreadyWiki,
    ) {}

    /**
     * 게시판 모델로부터 사실을 모은다. 호출부가 트랜잭션 안에서 잠근 모델을 넘기면
     * 판정과 쓰기 사이에 글이 끼어들 틈이 없다.
     *
     * @param  list<int>  $wikiBoardIds  이미 위키로 등록된 게시판 id
     */
    public static function of(Board $board, array $wikiBoardIds): self
    {
        $type = $board->type instanceof \BackedEnum ? (string) $board->type->value : (string) $board->type;
        $categories = is_array($board->categories) ? $board->categories : [];

        return new self(
            (int) $board->id,
            $type,
            Post::withTrashed()->where('board_id', $board->id)->count(),
            count($categories),
            in_array((int) $board->id, $wikiBoardIds, true),
        );
    }
}
