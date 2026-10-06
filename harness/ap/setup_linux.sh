#!/bin/bash
# Lingkungan Linux nyata: Apache 2.4 (mpm_event) + PHP-FPM 7.4 (pool www-data), app di /var/www/html/php74/opr-simulation.
# Arg: <src-dir dengan 5 PHP> <input_data.json> [port=8074]
set -e
SRC=$1; IN=$2; PORT=${3:-8074}; APP=/var/www/html/php74/opr-simulation
install -m 0755 /tmp/claude-0/fpmx/usr/sbin/php-fpm7.4 /opt/php74/usr/sbin/php-fpm7.4 2>/dev/null || { mkdir -p /opt/php74/usr/sbin; install -m 0755 /tmp/claude-0/fpmx/usr/sbin/php-fpm7.4 /opt/php74/usr/sbin/php-fpm7.4; }
mkdir -p /etc/php74-fpm /var/log/php74-fpm /run; chown www-data:www-data /var/log/php74-fpm
sed -e 's/^display_errors.*//' /opt/php74/etc/php.ini > /etc/php74-fpm/php.ini
cat >> /etc/php74-fpm/php.ini <<'INI'
display_errors = Off
log_errors = On
error_log = /var/log/php74-fpm/php_errors.log
opcache.enable = 1
max_execution_time = 30
post_max_size = 64M
upload_max_filesize = 64M
INI
cat > /etc/php74-fpm/php-fpm.conf <<'CONF'
[global]
pid = /run/php74-fpm.pid
error_log = /var/log/php74-fpm/fpm.log
[www]
user = www-data
group = www-data
listen = /run/php74-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = static
pm.max_children = 24
request_terminate_timeout = 0
catch_workers_output = yes
CONF
[ -f /run/php74-fpm.pid ] && kill $(cat /run/php74-fpm.pid) 2>/dev/null || true; sleep 1
LD_LIBRARY_PATH=/opt/php74/usr/lib/x86_64-linux-gnu /opt/php74/usr/sbin/php-fpm7.4 -c /etc/php74-fpm/php.ini -y /etc/php74-fpm/php-fpm.conf
a2enmod -q proxy_fcgi setenvif >/dev/null
cat > /etc/apache2/sites-available/opr74.conf <<VH
Listen $PORT
<VirtualHost *:$PORT>
  DocumentRoot /var/www/html
  DirectoryIndex index.php
  <FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php74-fpm.sock|fcgi://localhost"
  </FilesMatch>
  ProxyTimeout 900
  ErrorLog /var/log/apache2/opr74_error.log
  CustomLog /var/log/apache2/opr74_access.log combined
</VirtualHost>
VH
a2ensite -q opr74 >/dev/null
# aplikasi: berkas milik root, grup www-data; folder aplikasi 2775 (grup dapat menulis input_data.json, berkas sementara atomik, jobs/, data/)
rm -rf "${APP:?}"; mkdir -p $APP
install -o root -g www-data -m 0644 $SRC/run.php $SRC/worker02.php $SRC/worker_functions.php $SRC/index.php $APP/
[ -f $SRC/saved_data_store.php ] && install -o root -g www-data -m 0644 $SRC/saved_data_store.php $APP/
chown root:www-data $APP; chmod 2775 $APP
install -o www-data -g www-data -m 0664 $IN $APP/input_data.json
apache2ctl -k restart 2>/dev/null || apache2ctl start
sleep 1; curl -s -o /dev/null -w "index HTTP %{http_code}\n" http://127.0.0.1:$PORT/php74/opr-simulation/
