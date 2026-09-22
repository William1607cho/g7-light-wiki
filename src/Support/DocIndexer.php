<?php

namespace Plugins\G7\Light\Wiki\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Light\Wiki\Models\WikiDoc;

/**
 * 색인 표(`light_wiki_docs`)를 글과 맞춰 두는 곳 — 훅 리스너와 `light-wiki:rebuild` 가 함께 쓴다.
 *
 * ## 문서가 되는 글
 *
 * 위키 게시판의 **본글**만 문서다. 답글(`parent_id`)은 제목이 "Re: …" 로 겹치기 쉬워
 * 문서로 세지 않는다 — 세면 답글 두 개가 서로 제목 충돌로 막힌다.
 *
 * 비밀글·블라인드 글은 색인에 **남긴다**. 제목의 임자는 그 글이고, 목록에 보일지는
 * 조회할 때 따로 거른다({@see WikiDocListQuery}).
 */
final class DocIndexer
{
    /**
     * 글 하나를 색인에 맞춥니다 (생성·수정·복원 공통).
     *
     * @param  object  $post  코어 Post 모델
     */
    public static function sync(object $post): void
    {
        $postId = (int) ($post->id ?? 0);
        $boardId = (int) ($post->board_id ?? 0);

        if ($postId < 1 || $boardId < 1) {
            return;
        }

        if (($post->parent_id ?? null) !== null) {
            // 답글로 바뀐 글이 색인에 남아 있으면 지운다.
            self::forget($postId);

            return;
        }

        $title = (string) ($post->title ?? '');
        $normalized = TitleNormalizer::normalize($title);

        if (! TitleNormalizer::isRegistrable($normalized)) {
            Log::warning('[g7-light-wiki] 정규화 제목이 비어 있거나 너무 길어 위키 문서로 등록하지 않습니다', [
                'post_id' => $postId,
                'board_id' => $boardId,
                'normalized_length' => mb_strlen($normalized, 'UTF-8'),
            ]);

            self::forget($postId);

            return;
        }

        try {
            WikiDoc::query()->updateOrCreate(
                ['post_id' => $postId],
                [
                    'board_id' => $boardId,
                    'title' => mb_substr($title, 0, TitleNormalizer::MAX_LENGTH, 'UTF-8'),
                    'title_norm' => $normalized,
                    'edited_at' => $post->updated_at ?? now(),
                ],
            );
        } catch (QueryException $e) {
            // (board_id, title_norm) 유니크 충돌 — 검증 훅을 우회한 동시 저장에서만 난다.
            // 글 저장 자체는 이미 끝났으므로 막지 않고 기록만 남긴다(`light-wiki:rebuild` 로 정리).
            Log::warning('[g7-light-wiki] 위키 문서 색인 충돌 — 이 글은 색인에 들어가지 못했습니다', [
                'post_id' => $postId,
                'board_id' => $boardId,
            ]);
        }
    }

    /**
     * 글 하나를 색인에서 지웁니다.
     *
     * @return int 지운 줄 수
     */
    public static function forget(int $postId): int
    {
        if ($postId < 1) {
            return 0;
        }

        return WikiDoc::query()->where('post_id', $postId)->delete();
    }

    /**
     * 게시판 하나의 색인을 통째로 지웁니다 (게시판 삭제 뒤처리).
     *
     * @return int 지운 줄 수
     */
    public static function forgetBoard(int $boardId): int
    {
        if ($boardId < 1) {
            return 0;
        }

        return WikiDoc::query()->where('board_id', $boardId)->delete();
    }
}
