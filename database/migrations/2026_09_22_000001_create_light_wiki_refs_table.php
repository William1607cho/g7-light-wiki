<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 본문에서 뽑아 둔 표기 — "이 글의 이 자리에 이런 표기가 있었다" 한 줄.
 *
 * 링크(`link`)·분류(`category`)·별칭(`alias`)·사건(`event`) 네 종류를 한 표에 담는다.
 * 종류마다 표를 나누면 저장 훅이 네 번 지우고 네 번 쓰게 되고, 역링크·분류 목록·연표가
 * 서로 다른 표를 조인해야 한다. 한 표에 `kind` 로 두면 글 하나의 동기화가 **삭제 1회 +
 * 삽입 1회**로 끝난다.
 *
 * 본문의 정본은 코어 `board_posts` 다. 이 표는 그 본문에서 뽑아 낸 **색인**이라 지워도
 * `light-wiki:rebuild` 로 다시 만들 수 있다.
 *
 * 테이블명에 `g7_` 를 **직접 붙이지 않는다** — 코어가 `DB_PREFIX` 로 이미 붙이므로
 * 여기에 또 쓰면 `g7_g7_…` 가 된다(이 사이트에 실제 사례가 있다).
 *
 * `target_norm` 의 collation 을 `utf8mb4_bin` 으로 **명시**하는 이유는 `light_wiki_docs`
 * 와 같다. 기본값 `utf8mb4_unicode_ci` 는 악센트·전각/반각·대부분의 이모지를 서로 같게
 * 보므로, 무엇을 같은 이름으로 볼지를 DB 의 암묵 규칙이 정하게 된다. 그 판단은
 * `TitleNormalizer` 만 한다. 링크 대상이 문서 제목과 맞물리려면 두 컬럼의 비교 방식이
 * 같아야 한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('light_wiki_refs', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->unsignedBigInteger('board_id')->comment('위키 게시판 ID');
            $table->unsignedBigInteger('post_id')->comment('표기가 실린 글 ID');
            $table->string('kind', 16)->comment('표기 종류 — link / category / alias / event');
            $table->string('target', 200)->comment('원문 대상 (문서명·분류명·별칭·연표 키)');
            $table->string('target_norm', 200)
                ->collation('utf8mb4_bin')
                ->comment('정규화 대상 — 대조 기준 (TitleNormalizer)');
            $table->string('sort_key', 64)
                ->nullable()
                ->collation('utf8mb4_bin')
                ->comment('연표 전용 — 칸별 정수 비교가 문자열 정렬로 되도록 정규화한 키');
            $table->string('label', 300)->nullable()->comment('연표 전용 — 사건 설명');
            $table->unsignedInteger('seq')->default(0)->comment('한 글 안에서의 등장 순서');
            $table->timestamps();

            // 인덱스명을 명시한다 — 자동 생성명은 prefix 가 섞여 길이·중복 문제가 난다.
            //
            // 역링크·분류 소속은 "이 게시판에서 이 종류로 이 이름을 가리킨 행" 을 찾는다.
            $table->index(['board_id', 'kind', 'target_norm'], 'light_wiki_refs_board_kind_target_idx');
            // 글 하나의 행을 지우고 다시 쓰는 동기화 경로.
            $table->index('post_id', 'light_wiki_refs_post_idx');
            // 연표는 게시판 전체를 키 순으로 훑는다.
            $table->index(['board_id', 'kind', 'sort_key'], 'light_wiki_refs_board_kind_sort_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('light_wiki_refs', function (Blueprint $table) {
                $table->comment('위키 본문 표기 색인 (링크·분류·별칭·연표)');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('light_wiki_refs');
    }
};
