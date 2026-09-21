<?php

namespace Plugins\G7\Light\Wiki\Console\Commands;

use Illuminate\Console\Command;
use Modules\Sirsoft\Board\Models\Post;
use Plugins\G7\Light\Wiki\Models\WikiDoc;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 이미 있는 글로 위키 문서 색인을 다시 만든다.
 *
 *   php artisan light-wiki:rebuild --board=<게시판 ID> [--dry-run]
 *
 * 플러그인을 나중에 켠 게시판, 색인이 어긋난 게시판에 쓴다. `--dry-run` 은 **아무것도
 * 바꾸지 않고** 차이만 센다.
 *
 * 정규화 제목이 겹치면 **먼저 만들어진 글(작은 ID)**이 그 제목을 가져가고 나머지는
 * 등록하지 않는다. 출력에는 **글 ID 만** 적는다 — 제목은 적지 않는다.
 */
class RebuildDocsCommand extends Command
{
    protected $signature = 'light-wiki:rebuild
        {--board= : 대상 게시판 ID (위키 게시판이어야 한다)}
        {--dry-run : 바꾸지 않고 차이만 센다}';

    protected $description = '이미 있는 글로 위키 문서 색인을 다시 만듭니다.';

    public function handle(): int
    {
        $boardId = (int) $this->option('board');
        $dryRun = (bool) $this->option('dry-run');

        if ($boardId < 1) {
            $this->error('--board=<게시판 ID> 가 필요합니다.');

            return self::INVALID;
        }

        if (! WikiBoardSettings::isWikiBoard($boardId)) {
            $this->error("게시판 {$boardId} 는 위키 게시판 설정에 없습니다.");

            return self::INVALID;
        }

        [$desired, $conflicts, $skipped] = $this->collect($boardId);

        $current = WikiDoc::query()
            ->where('board_id', $boardId)
            ->get(['post_id', 'title', 'title_norm'])
            ->keyBy('post_id');

        $toAdd = [];
        $toUpdate = [];

        foreach ($desired as $postId => $row) {
            $existing = $current->get($postId);

            if ($existing === null) {
                $toAdd[] = $postId;

                continue;
            }

            if ((string) $existing->title_norm !== $row['title_norm'] || (string) $existing->title !== $row['title']) {
                $toUpdate[] = $postId;
            }
        }

        $toRemove = $current->keys()
            ->map(static fn ($id): int => (int) $id)
            ->reject(static fn (int $id): bool => isset($desired[$id]))
            ->values()
            ->all();

        $this->report($boardId, $dryRun, count($desired), $toAdd, $toUpdate, $toRemove, $conflicts, $skipped);

        if ($dryRun) {
            return self::SUCCESS;
        }

        $this->apply($boardId, $desired, $toRemove);

        $this->info('색인을 갱신했습니다.');

        return self::SUCCESS;
    }

    /**
     * 게시판의 본글을 훑어 "있어야 할 색인" 을 만든다.
     *
     * @return array{0: array<int, array{title: string, title_norm: string, edited_at: mixed}>, 1: list<int>, 2: list<int>}
     */
    private function collect(int $boardId): array
    {
        $desired = [];
        $claimed = [];
        $conflicts = [];
        $skipped = [];

        Post::query()
            ->where('board_id', $boardId)
            ->whereNull('parent_id')
            ->orderBy('id')
            ->chunkById(500, function ($posts) use (&$desired, &$claimed, &$conflicts, &$skipped): void {
                foreach ($posts as $post) {
                    $postId = (int) $post->id;
                    $title = (string) $post->title;
                    $normalized = TitleNormalizer::normalize($title);

                    if (! TitleNormalizer::isRegistrable($normalized)) {
                        $skipped[] = $postId;

                        continue;
                    }

                    if (isset($claimed[$normalized])) {
                        $conflicts[] = $postId;

                        continue;
                    }

                    $claimed[$normalized] = $postId;
                    $desired[$postId] = [
                        'title' => mb_substr($title, 0, TitleNormalizer::MAX_LENGTH, 'UTF-8'),
                        'title_norm' => $normalized,
                        'edited_at' => $post->updated_at ?? $post->created_at ?? now(),
                    ];
                }
            });

        return [$desired, $conflicts, $skipped];
    }

    /**
     * 색인을 실제로 맞춘다 (없는 줄 추가·다른 줄 갱신·남는 줄 삭제).
     *
     * @param  array<int, array{title: string, title_norm: string, edited_at: mixed}>  $desired
     * @param  list<int>  $toRemove
     */
    private function apply(int $boardId, array $desired, array $toRemove): void
    {
        if ($toRemove !== []) {
            // 남는 줄을 먼저 지운다 — 제목이 옮겨 간 경우 유니크 인덱스에 걸리지 않게.
            WikiDoc::query()->where('board_id', $boardId)->whereIn('post_id', $toRemove)->delete();
        }

        foreach ($desired as $postId => $row) {
            WikiDoc::query()->updateOrCreate(
                ['post_id' => $postId],
                [
                    'board_id' => $boardId,
                    'title' => $row['title'],
                    'title_norm' => $row['title_norm'],
                    'edited_at' => $row['edited_at'],
                ],
            );
        }
    }

    /**
     * 결과를 출력한다 — **글 ID 와 건수만** 적는다.
     *
     * @param  list<int>  $toAdd
     * @param  list<int>  $toUpdate
     * @param  list<int>  $toRemove
     * @param  list<int>  $conflicts
     * @param  list<int>  $skipped
     */
    private function report(
        int $boardId,
        bool $dryRun,
        int $documents,
        array $toAdd,
        array $toUpdate,
        array $toRemove,
        array $conflicts,
        array $skipped
    ): void {
        $this->line(($dryRun ? '[dry-run] ' : '').'게시판 '.$boardId);
        $this->line('  문서 후보       : '.$documents);
        $this->line('  추가            : '.count($toAdd).($toAdd === [] ? '' : ' ('.implode(',', $toAdd).')'));
        $this->line('  갱신            : '.count($toUpdate).($toUpdate === [] ? '' : ' ('.implode(',', $toUpdate).')'));
        $this->line('  삭제            : '.count($toRemove).($toRemove === [] ? '' : ' ('.implode(',', $toRemove).')'));
        $this->line('  제목 충돌(제외) : '.count($conflicts).($conflicts === [] ? '' : ' ('.implode(',', $conflicts).')'));
        $this->line('  제목 없음(제외) : '.count($skipped).($skipped === [] ? '' : ' ('.implode(',', $skipped).')'));
        $this->line('  차이 합계       : '.(count($toAdd) + count($toUpdate) + count($toRemove)));
    }
}
