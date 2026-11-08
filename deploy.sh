#!/usr/bin/env bash
#
# Updates the live website after a commit (installation with SSH and git).
# Run this ON THE SERVER, in the project folder:  ./deploy.sh
#
# The frontend is not built here: shared hosting has no Node.
# The built files (public/build) are in the repository and arrive with the pull.
#
set -e
cd "$(dirname "$0")"

echo "==> Fetching the new code"
git pull --ff-only

echo "==> Updating packages"
# --no-scripts because proc_open is disabled on shared hosting; that is why we
# run the step Composer would normally run itself by hand afterwards.
composer2 install --no-dev --optimize-autoloader --no-scripts
php artisan package:discover --quiet

echo "==> Updating the database"
php artisan migrate --force

# No storage:link: the public disk writes straight to public/storage
# (see config/filesystems.php). symlink() and exec() are disabled on this
# hosting, so storage:link would fail and, because of `set -e`, stop the rest
# of this script.

echo "==> Refreshing caches"
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
php artisan filament:optimize
# Note: no route:cache. routes/web.php uses closures (language and currency
# switch), and Laravel cannot cache those.

echo "==> Done. $(git log -1 --format='%h %s')"
