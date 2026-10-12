#!/bin/sh
cd /var/www/html
for s in topic-type-gift scorm course-access lrs settings model-fields headless-h5p courses auth; do
  start=$(date +%s)
  XDEBUG_MODE=off timeout 1500 ./vendor/bin/phpunit --testsuite $s --no-coverage > /tmp/suite_$s.txt 2>&1
  end=$(date +%s)
  echo "$s exit=$? secs=$((end-start))" >> /tmp/suites_summary.txt
done
echo ALLDONE >> /tmp/suites_summary.txt
