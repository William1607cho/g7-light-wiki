<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Support\SecretContentGate;
use Plugins\G7\Light\Wiki\Support\WikiLinkGraphQuery;

/**
 * 이 요청자가 이 게시판에서 **어느 비밀글을 읽을 수 있는가** — `[[#외톨이]]`·`[[#필요한문서]]` 가 쓴다.
 *
 * ## 판정은 코어가 한다
 *
 * 비밀글 열람 규칙은 코어 `SecretContentGate::canView()` 한 곳에 있다(작성자 본인, 게시판
 * `posts.read-secret`, 게시판 관리자 …). 여기서는 그 규칙을 옮겨 적지 않고 **게이트를 그대로
 * 부른다.** 넘기는 글은 저장하지 않은 모델(probe)이다.
 *
 * - probe 에 **id 를 넣지 않는다.** 코어는 id 없는 글에 열람 토큰을 인정하지 않는다
 *   (`hasSecretViewToken` 의 `! $post->id`). 비밀번호로 연 글의 토큰은 이 판정에 쓰지 않는다.
 * - `password_verified` 도 넣지 않는다(상세 화면에서 비밀번호를 맞힌 그 글에만 붙는 값이다).
 *
 * ## 두 단계
 *
 * 1. 게시판 단위 — user_id 도 없는 probe 로 한 번 묻는다. 참이면 이 게시판 비밀글은 전부 보인다.
 * 2. 글마다 — 1 이 거짓이고 로그인했으면, 이 게시판 비밀 문서의 작성자를 한 번에 받아 probe 마다
 *    다시 묻는다. 비로그인이면 묻지 않는다 — 코어 게이트의 작성자 판정은 로그인한 사람에게만
 *    성립하고, 게시판 단위 판정은 1 에서 이미 거짓이다.
 *
 * 처음 쓸 때 한 번만 계산한다. 이 두 자리표시가 없는 문서에서는 비용이 없다.
 */
final class SecretScope
{
    private ?bool $all = null;

    /** @var list<int> */
    private array $postIds = [];

    public function __construct(
        private readonly int $boardId,
        private readonly string $slug,
    ) {}

    /** 이 게시판의 비밀글을 전부 읽을 수 있는가 */
    public function all(): bool
    {
        $this->resolve();

        return (bool) $this->all;
    }

    /**
     * 읽을 수 있는 비밀글 ID ({@see all()} 이 참이면 빈 목록 — 조건이 필요 없다).
     *
     * @return list<int>
     */
    public function postIds(): array
    {
        $this->resolve();

        return $this->postIds;
    }

    private function resolve(): void
    {
        if ($this->all !== null) {
            return;
        }

        $gate = app(SecretContentGate::class);

        $this->all = $gate->canView($this->probe(null));

        if ($this->all || ! Auth::check()) {
            return;
        }

        foreach (WikiLinkGraphQuery::secretAuthors($this->boardId) as $postId => $userId) {
            if ($gate->canView($this->probe($userId))) {
                $this->postIds[] = $postId;
            }
        }
    }

    /**
     * 게이트에 넘길 글 — 저장하지 않는다. 게시판 관계는 slug 만 든 모델이다(조회 없음).
     */
    private function probe(?int $userId): Post
    {
        $post = new Post;
        $post->setAttribute('user_id', $userId);
        $post->setRelation('board', (new Board)->forceFill(['slug' => $this->slug]));

        return $post;
    }
}
