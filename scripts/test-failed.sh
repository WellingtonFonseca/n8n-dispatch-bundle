#!/bin/sh
# Re-runs only the PHPUnit tests that failed in the last run (use after each fix).
# Usage: scripts/test-failed.sh        -> only the red ones from the last run
#        scripts/test-failed.sh --all  -> full suite (records the result for the next "red only")
# The full run (and every run) updates .phpunit.result.cache inside the container.
C=mautic-mautic_web-1
D=/var/www/html/docroot/plugins/N8nDispatchBundle
P=/var/www/html/vendor/bin/phpunit
if [ "$1" = "--all" ]; then
  exec docker exec "$C" sh -c "cd $D && $P --cache-result"
fi
FILTER=$(docker exec "$C" sh -c "cd $D && php -r '
\$f=\".phpunit.result.cache\";
if(!is_file(\$f)){fwrite(STDERR,\"no previous run: run with --all first\n\");exit(2);}
\$d=json_decode(file_get_contents(\$f),true)[\"defects\"]??[];
\$p=[];foreach(array_keys(\$d) as \$k){\$p[]=preg_quote(explode(\" with data set\",\$k)[0],\"/\");}
echo implode(\"|\",array_unique(\$p));'") || exit $?
if [ -z "$FILTER" ]; then echo "No red tests in the last run."; exit 0; fi
exec docker exec "$C" sh -c "cd $D && $P --cache-result --filter '/($FILTER)/'"
