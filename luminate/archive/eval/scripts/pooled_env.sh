cd /var/www/html
for d in pooled-c.localhost pooled-d.localhost; do
  n=$(echo $d | sed 's/-.*//'); 
  VALS='{"APP_NAME":"Pooled","APP_URL":"http://'$d'","DB_DATABASE":"pooled","DB_USERNAME":"pooled","DB_PASSWORD":"pooled_pw","AWS_BUCKET":"pooled","AWS_URL":"http://storage.localhost/pooled","INITIAL_USER_EMAIL":"admin@pooled.test","INITIAL_USER_PASSWORD":"pooled-secret","REDIS_DB":"5"}'
  php artisan domain:update_env $d --domain_values="$VALS" 2>&1 | tail -1
done
grep -h '^DB_DATABASE\|^APP_NAME\|^REDIS_DB' .env.pooled-c.localhost .env.pooled-d.localhost
