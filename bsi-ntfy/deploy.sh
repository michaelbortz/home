#!/usr/bin/env bash
# Spielt bsi-ntfy auf das Hetzner-Webhosting (ssh-Alias hetzner-micha) nach ~/public_html/ntfy
# und richtet den Cronjob ein. Die Domain ntfy.fbortz.de zeigt auf ntfy/public.
# data/ (Datenbank, Sitzungen) bleibt auf dem Server unangetastet.
set -euo pipefail
cd "$(dirname "$0")"

HOST=hetzner-micha
APP=/usr/www/users/aijnuy/ntfy
CRON="*/15 * * * * /usr/bin/php $APP/bin/poll.php >> $APP/data/cron.log 2>&1"

tar -cz .htaccess src bin public | ssh "$HOST" "mkdir -p $APP/data && chmod 700 $APP/data && tar -xz -C $APP"
ssh "$HOST" "set -e
  for f in $APP/src/*.php $APP/bin/*.php $APP/public/index.php; do php -l \$f >/dev/null; done
  rm -f $APP/index.php $APP/style.css $APP/enroll.js $APP/qrcode.min.js
  (crontab -l 2>/dev/null | grep -v 'ntfy/bin/poll.php' || true; echo '$CRON') | crontab -
  echo 'Deploy ok. Cron:'; crontab -l | grep poll.php"
