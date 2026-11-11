#!/bin/sh
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"
php=$(command -v php || echo /usr/local/bin/php)
dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
script="${dir}/../../../occ"
if [ ! -f "$script" ]; then
    script="/var/www/html/occ"
fi
"$php" "$script" aria2 error "$1" "$2" "$3" >/dev/null 2>&1
