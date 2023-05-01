#!/bin/sh

git config --global --add safe.directory /var/www
sh install.sh 
mkdir unversioned
cp -npr templates/dburl.example unversioned/dburl
cp templates/adminpw.example unversioned/adminpw
cp templates/userpw.example unversioned/userpw
cp templates/sitehost.example unversioned/sitehost
git config --global --add safe.directory /var/www/vendor/drupal/coder

