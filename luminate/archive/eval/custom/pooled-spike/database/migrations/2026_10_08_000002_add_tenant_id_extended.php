<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = ['topic_gift_quizzes', 'topic_gift_questions', 'topic_gift_quiz_attempts', 'topic_gift_attempt_answers', 'course_progress'];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            if (Schema::hasTable($t) && !Schema::hasColumn($t, 'tenant_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->string('tenant_id', 100)->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'tenant_id')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn('tenant_id'));
            }
        }
    }
};
