config(['queue.default' => 'sync']);
$u = \EscolaLms\Core\Models\User::find(3);
$c = \EscolaLms\Courses\Models\Course::find(2);
echo "listeners for CourseFinished: ", count(app('events')->getListeners(\EscolaLms\Courses\Events\CourseFinished::class)), PHP_EOL;
try { event(new \EscolaLms\Courses\Events\CourseFinished($u, $c)); echo "fired OK\n"; } catch (\Throwable $e) { echo get_class($e), ': ', $e->getMessage(), PHP_EOL; }
