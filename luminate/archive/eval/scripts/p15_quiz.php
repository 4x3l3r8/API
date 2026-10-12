<?php
\Illuminate\Support\Facades\DB::statement("select set_config('app.tenant_id','pooled-c.localhost',false)");
echo "GUC now: " . \Illuminate\Support\Facades\DB::selectOne("select current_setting('app.tenant_id', true) as t")->t . PHP_EOL;
echo "GiftQuiz::find(1): " . (\EscolaLms\TopicTypeGift\Models\GiftQuiz::find(1) ? 'found' : 'null') . PHP_EOL;
echo "raw count topic_gift_quizzes: " . \Illuminate\Support\Facades\DB::table('topic_gift_quizzes')->count() . PHP_EOL;
$m = new \EscolaLms\TopicTypeGift\Models\GiftQuiz();
echo "table=" . $m->getTable() . " connection=" . ($m->getConnectionName() ?? 'default') . " sql=" . $m->newQuery()->where('id', 1)->toSql() . PHP_EOL;
