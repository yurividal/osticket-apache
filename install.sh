#!/bin/sh

set -e

# Automate installation
echo Running installation
search_string="require('../bootstrap.php');"
replace_string="require('/var/www/html/bootstrap.php');"
sed -i "s|${search_string}|${replace_string}|g" /var/www/html/setup/setup.inc.php
php /var/www/html/setup/install.php
echo Applying configuration file sucurity
chmod 644 /var/www/html/include/ost-config.php
echo Removing Setup files
rm -Rf /var/www/html/setup/
