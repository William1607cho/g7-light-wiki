<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

use Modules\Sirsoft\Board\Enums\SecretMode;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\PostService;
use Plugins\G7\Light\Wiki\Support\DocIndexer;
use Plugins\G7\Light\Wiki\Support\RefIndexer;

/**
 * 시드 문서를 만들고 색인·추출 표를 채운다.
 *
 * 글은 모듈 서비스(`PostService::createPost`)로 만든다 — 방문자 글쓰기 API 와 같은 경로라
 * 코어 훅(게시판 글 수 동기화·SEO 캐시·활동 로그)이 그대로 돈다. 알림은 끈다
 * (`skip_notification` — 모듈 자신의 이커머스 문의 연동과 같은 방식).
 *
 * 색인은 훅에 맡기지 않고 **직접** 채운다. 이 시점에는 설정에 아직 이 게시판이 없어
 * (설정 저장이 트랜잭션의 마지막 단계다) `PostIndexListener` 가 아무것도 하지 않는다.
 * 순서는 리스너와 같다 — 제목 색인 다음 표기 추출.
 *
 * 제목 중복은 따로 막지 않는다. 새 게시판이거나 글이 0건인 게시판이라 겹칠 수 없다.
 */
final class SeedWriter
{
    public function __construct(private readonly PostService $posts) {}

    /**
     * 시드 3건을 만든다.
     *
     * @param  int  $authorId  작성자 (저장 직전에 후보인지 확인한 사용자)
     * @param  string  $ipAddress  요청 IP (`board_posts.ip_address` 는 NOT NULL)
     * @return array<string, int> 시드 키 => 글 id
     */
    public function write(Board $board, int $authorId, string $ipAddress): array
    {
        $ids = [];

        foreach (SeedDocuments::all() as $seed) {
            $post = $this->posts->createPost(
                (string) $board->slug,
                [
                    'title' => $seed['title'],
                    'content' => $seed['content'],
                    'content_mode' => $seed['content_mode'],
                    'user_id' => $authorId,
                    'ip_address' => $ipAddress,
                    'is_notice' => false,
                    // 방문자 글쓰기와 같은 규칙: 비밀글 필수 게시판이면 비밀글로.
                    'is_secret' => $board->secret_mode === SecretMode::Always,
                ],
                [],
                [],
                ['skip_notification' => true],
            );

            DocIndexer::sync($post);
            RefIndexer::sync($post);

            $ids[$seed['key']] = (int) $post->id;
        }

        return $ids;
    }
}
