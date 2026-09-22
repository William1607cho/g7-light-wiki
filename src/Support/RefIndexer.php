<?php

namespace Plugins\G7\Light\Wiki\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Light\Wiki\Models\WikiRef;

/**
 * 표기 색인 표(`light_wiki_refs`)를 글과 맞춰 두는 곳 — 훅 리스너와 `light-wiki:rebuild` 가 함께 쓴다.
 *
 * {@see DocIndexer} 가 제목을 맡듯, 이쪽은 **본문에서 뽑은 표기**를 맡는다.
 *
 * ## 표기를 뽑는 글
 *
 * 위키 게시판의 **본글**만이다. 답글(`parent_id`)은 문서가 아니므로(제목이 "Re: …" 로 겹친다)
 * 문서 색인에도 오르지 않는다. 답글에서 건 링크가 역링크 목록에 실리면 "그 문서로 가 보니
 * 답글이더라" 가 된다.
 *
 * 비밀글·블라인드 글의 표기는 **뽑아 둔다**. 그 글 자신의 화면에서는 자기 분류 줄·별칭 줄이
 * 보여야 하기 때문이다. 남의 목록(역링크·분류 소속·연표)에서 빼는 일은 조회할 때 한다
 * ({@see WikiRefQuery}).
 *
 * ## 이전 상태
 *
 * {@see sync()} 와 {@see forget()} 은 **지우기 직전의 행을 돌려준다.** 봇 캐시를 비울 때
 * "저장 전에 이 글이 무엇을 가리키고 있었나" 가 필요한데, 훅 인자에는 그것이 `after_update`
 * 에만 있다(`$snapshot`). 표에서 읽으면 생성·수정·복원·삭제가 모두 같은 코드로 덮인다.
 */
final class RefIndexer
{
    /**
     * 글 하나의 표기를 표에 맞춥니다 (생성·수정·복원 공통).
     *
     * @param  object  $post  코어 Post 모델
     * @return array{before: list<array{kind: string, target_norm: string}>, after: list<array{kind: string, target_norm: string}>}
     *                이전·이후 표기 (봇 캐시 무효화가 쓴다)
     */
    public static function sync(object $post): array
    {
        $postId = (int) ($post->id ?? 0);
        $boardId = (int) ($post->board_id ?? 0);

        if ($postId < 1 || $boardId < 1) {
            return ['before' => [], 'after' => []];
        }

        if (($post->parent_id ?? null) !== null) {
            // 답글로 바뀐 글이 표에 남아 있으면 지운다.
            return ['before' => self::forget($postId), 'after' => []];
        }

        $rows = RefExtractor::extract(
            (string) ($post->content ?? ''),
            (string) ($post->content_mode ?? 'text'),
        );

        $before = self::currentPairs($postId);

        try {
            // 지우고 다시 쓴다. 같은 글의 표기는 순서·개수가 통째로 바뀌므로 줄 단위로
            // 맞춰 넣는 것보다 이쪽이 싸고, 남는 줄이 생기지 않는다.
            WikiRef::query()->where('post_id', $postId)->delete();

            if ($rows !== []) {
                WikiRef::query()->insert(array_map(
                    static fn (array $row): array => $row + [
                        'board_id' => $boardId,
                        'post_id' => $postId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    $rows,
                ));
            }
        } catch (QueryException $e) {
            // 표기 색인이 글 저장을 막으면 안 된다 — 기록만 남기고 넘어간다
            // (`light-wiki:rebuild` 로 정리된다).
            Log::warning('[g7-light-wiki] 본문 표기 색인에 실패했습니다', [
                'post_id' => $postId,
                'board_id' => $boardId,
                'rows' => count($rows),
            ]);

            return ['before' => $before, 'after' => []];
        }

        return [
            'before' => $before,
            'after' => array_map(
                static fn (array $row): array => ['kind' => $row['kind'], 'target_norm' => $row['target_norm']],
                $rows,
            ),
        ];
    }

    /**
     * 글 하나의 표기를 표에서 지웁니다.
     *
     * @return list<array{kind: string, target_norm: string}> 지우기 직전의 표기
     */
    public static function forget(int $postId): array
    {
        if ($postId < 1) {
            return [];
        }

        $before = self::currentPairs($postId);

        WikiRef::query()->where('post_id', $postId)->delete();

        return $before;
    }

    /**
     * 게시판 하나의 표기를 통째로 지웁니다 (게시판 삭제 뒤처리).
     *
     * @return int 지운 줄 수
     */
    public static function forgetBoard(int $boardId): int
    {
        if ($boardId < 1) {
            return 0;
        }

        return WikiRef::query()->where('board_id', $boardId)->delete();
    }

    /**
     * 지금 표에 있는 그 글의 표기 (종류 + 정규화 대상).
     *
     * @return list<array{kind: string, target_norm: string}>
     */
    private static function currentPairs(int $postId): array
    {
        return WikiRef::query()
            ->where('post_id', $postId)
            ->get(['kind', 'target_norm'])
            ->map(static fn (WikiRef $ref): array => [
                'kind' => (string) $ref->kind,
                'target_norm' => (string) $ref->target_norm,
            ])
            ->all();
    }
}
