<?php

namespace Plugins\G7\Light\Wiki\Support;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;

/**
 * 목록 항목으로 쓸 글을 **코어 목록과 같은 모양으로** 적재한다.
 *
 * 컬럼·관계가 코어 `PostRepository::paginate()` 와 어긋나면 항목의 키는 같아도 값이 빈다
 * (댓글 수 0, 썸네일 null, 본문 미리보기 빈 문자열). 그래서 그 메서드가 쓰는 목록을
 * 그대로 옮겨 적고, 어디서 온 값인지 주석으로 묶어 둔다.
 *
 * 코어가 바뀌면 여기도 같이 바뀌어야 한다. 항목을 **만드는** 일은 코어
 * `PostResource::toListArray()` 가 하고, 이 클래스는 그 입력을 갖추는 일만 한다.
 */
final class WikiPostLoader
{
    /**
     * 코어 `PostRepository::paginate()` 의 목록 전용 컬럼.
     *
     * `content` 는 빼고 `SUBSTRING(content,1,200)` 만 뜬다 — 항목의 `content_preview` 가
     * 이 별칭(`content_preview_raw`)을 쓴다.
     *
     * @return list<mixed>
     */
    private static function columns(): array
    {
        return [
            'id', 'board_id', 'user_id', 'parent_id', 'category',
            'title', 'author_name', 'content_mode', 'content_thumbnail_url',
            'is_notice', 'is_secret', 'status', 'depth',
            'view_count', 'comments_count', 'replies_count', 'attachments_count',
            'trigger_type', 'ip_address', 'created_at', 'updated_at', 'deleted_at',
            DB::raw('SUBSTRING(content, 1, 200) as content_preview_raw'),
        ];
    }

    /** 코어 `PostRepository::paginate()` 가 미리 적재하는 관계 */
    private const RELATIONS = ['user', 'user.avatarAttachment', 'thumbnailAttachment'];

    /**
     * 글 ID 목록을 **넘긴 순서 그대로** 모델로 적재한다.
     *
     * `whereIn` 은 순서를 보장하지 않으므로 PHP 에서 다시 세운다. 목록의 순서는
     * 호출부가 정한 것(대문 1건, 또는 `title_norm` 오름차순)이어야 한다.
     *
     * @param  list<int>  $postIds
     * @return list<Post>
     */
    public static function load(int $boardId, array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $posts = Post::query()
            ->where('board_id', $boardId)
            ->whereIn('id', $postIds)
            ->with(self::RELATIONS)
            ->get(self::columns())
            ->keyBy('id');

        // `Post::isNew()` 가 게시판 `new_display_hours` 를 보려면 board 관계가 필요하다.
        // 코어 컨트롤러도 같은 이유로 손수 주입한다 — 게시판 1건만 읽으므로 추가 질의는 1회다.
        $board = $posts->isEmpty() ? null : Board::find($boardId);

        $ordered = [];

        foreach ($postIds as $id) {
            $post = $posts->get($id);

            if ($post === null) {
                continue;
            }

            if ($board !== null) {
                $post->setRelation('board', $board);
            }

            $ordered[] = $post;
        }

        return $ordered;
    }
}
