<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 위키 문서 색인 — "이 게시판의 이 제목은 이 글" 한 줄.
 *
 * 본문·제목의 정본은 코어 `board_posts` 다. 이 표는 **정규화 제목으로 글을 찾기 위한
 * 색인**이라 지워도 `light-wiki:rebuild` 로 다시 만들 수 있다.
 *
 * 테이블명에 `g7_` 를 **직접 붙이지 않는다** — 코어가 `DB_PREFIX` 로 이미 붙이므로
 * 여기에 또 쓰면 `g7_g7_…` 가 된다(이 사이트에 실제 사례가 있다).
 *
 * `title_norm` 의 collation 을 `utf8mb4_bin` 으로 **명시**한다. 기본값
 * `utf8mb4_unicode_ci` 는 악센트·전각/반각·뒤 공백은 물론 대부분의 이모지를 서로 같게
 * 보기 때문에(가중치 미배정), 어떤 제목이 충돌인지를 DB 의 암묵 규칙이 정하게 된다.
 * 무엇을 같은 문서로 볼지는 `TitleNormalizer` 만 정하고, DB 는 바이트만 비교한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('light_wiki_docs', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->unsignedBigInteger('board_id')->comment('위키 게시판 ID');
            $table->unsignedBigInteger('post_id')->comment('문서 글 ID');
            $table->string('title', 200)->comment('원문 제목 (표시용)');
            $table->string('title_norm', 200)
                ->collation('utf8mb4_bin')
                ->comment('정규화 제목 — 유일성 판정 기준 (TitleNormalizer)');
            $table->timestamp('edited_at')->nullable()->comment('마지막 수정 시각 (최근수정 목록 정렬)');
            $table->timestamps();

            // 인덱스명을 명시한다 — 자동 생성명은 prefix 가 섞여 길이·중복 문제가 난다.
            $table->unique('post_id', 'light_wiki_docs_post_uq');
            $table->unique(['board_id', 'title_norm'], 'light_wiki_docs_board_title_uq');
            $table->index(['board_id', 'edited_at'], 'light_wiki_docs_board_edited_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('light_wiki_docs', function (Blueprint $table) {
                $table->comment('위키 문서 색인 (게시판별 정규화 제목 → 글)');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('light_wiki_docs');
    }
};
