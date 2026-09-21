<?php

namespace Plugins\G7\Light\Wiki\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Foundation\Http\FormRequest;
use Plugins\G7\Light\Wiki\Models\WikiDoc;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 위키 게시판에서 **정규화 제목이 같은 문서**가 두 개 생기지 않게 막는다 (422).
 *
 * 제목이 곧 문서 이름이므로 같은 제목 두 개는 "같은 문서가 둘" 이라는 뜻이 된다.
 * 방문자·관리자 양쪽 경로를 모두 덮는다:
 *
 *  - `sirsoft-board.user_post.store_validation_rules` / `…update_validation_rules`
 *  - `sirsoft-board.post.store_validation_rules` / `…update_validation_rules` (관리자 화면)
 *
 * 규칙은 `title` 필드에 클로저 하나를 덧붙이는 방식이다 — 검사는 **검증 시점에** 돌아
 * 그 순간의 색인을 본다. 최후의 보루는 `light_wiki_docs` 의 `(board_id, title_norm)`
 * 유니크 인덱스다(동시 저장 경쟁).
 *
 * 답글(`parent_id`)은 문서가 아니므로 검사하지 않는다 — 검사하면 "Re: …" 답글 두 개가
 * 서로 막힌다.
 */
class PostTitleGuardListener implements HookListenerInterface
{
    /** 이 플러그인이 쓰는 훅 우선순위 (코어 10·20, 다른 확장 20·1000 과 겹치지 않는다) */
    private const PRIORITY = 30;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.user_post.store_validation_rules' => [
                'method' => 'guardStore', 'type' => 'filter', 'priority' => self::PRIORITY,
            ],
            'sirsoft-board.user_post.update_validation_rules' => [
                'method' => 'guardUpdate', 'type' => 'filter', 'priority' => self::PRIORITY,
            ],
            'sirsoft-board.post.store_validation_rules' => [
                'method' => 'guardStore', 'type' => 'filter', 'priority' => self::PRIORITY,
            ],
            'sirsoft-board.post.update_validation_rules' => [
                'method' => 'guardUpdate', 'type' => 'filter', 'priority' => self::PRIORITY,
            ],
        ];
    }

    /**
     * 글 작성 검증.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function guardStore(array $rules, $request): array
    {
        return $this->attach($rules, $request, null);
    }

    /**
     * 글 수정 검증 — 자기 자신은 충돌 대상에서 뺀다.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function guardUpdate(array $rules, $request): array
    {
        $postId = $request instanceof FormRequest ? (int) $request->route('id') : 0;

        return $this->attach($rules, $request, $postId > 0 ? $postId : null);
    }

    /**
     * @inheritDoc
     */
    public function handle(...$args): void
    {
        // 이 리스너는 filter 훅만 구독한다.
    }

    /**
     * 위키 게시판이면 `title` 규칙 끝에 중복 검사 클로저를 덧붙인다.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function attach(array $rules, $request, ?int $excludePostId): array
    {
        if (! $request instanceof FormRequest) {
            return $rules;
        }

        $slug = (string) $request->route('slug');
        $boardId = $slug === '' ? null : BoardLookup::id($slug);

        if (! WikiBoardSettings::isWikiBoard($boardId)) {
            return $rules;
        }

        // 답글은 문서가 아니다.
        if ($request->filled('parent_id')) {
            return $rules;
        }

        $message = (string) __('g7-light-wiki::messages.title.duplicate');

        $check = function ($attribute, $value, $fail) use ($boardId, $excludePostId, $message): void {
            if (! is_string($value)) {
                return;
            }

            $normalized = TitleNormalizer::normalize($value);

            if (! TitleNormalizer::isRegistrable($normalized)) {
                return;
            }

            $exists = WikiDoc::query()
                ->where('board_id', $boardId)
                ->where('title_norm', $normalized)
                ->when($excludePostId !== null, fn ($query) => $query->where('post_id', '<>', $excludePostId))
                ->exists();

            if ($exists) {
                $fail($message);
            }
        };

        $existing = $rules['title'] ?? [];

        if (is_string($existing)) {
            $existing = explode('|', $existing);
        } elseif (! is_array($existing)) {
            $existing = [];
        }

        $existing[] = $check;
        $rules['title'] = $existing;

        return $rules;
    }
}
