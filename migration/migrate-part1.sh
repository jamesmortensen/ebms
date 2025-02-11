#!/bin/bash

start_time=${SECONDS}
date
if grep --quiet drupal /etc/passwd;
then
    echo Running on CBIIT hosting.
    if [ $(whoami) != "drupal" ]
    then
        echo This script must be run using the drupal account on CBIIT hosting.
        echo Aborting script.
        exit
    fi
    SUDO=
else
    echo Running on non-CBIIT hosting.
    SUDO=$(which sudo)
fi
REPO_BASE=$(pwd)
while getopts r: flag
do
    case "${flag}" in
        r) REPO_BASE=${OPTARG};;
    esac
done
export EBMS_MIGRATION_LOAD=1
export REPO_BASE
DRUSH=${REPO_BASE}/vendor/bin/drush
MIGRATION=${REPO_BASE}/migration
SITE=${REPO_BASE}/web/sites/default
UNVERSIONED=${REPO_BASE}/unversioned
DBURL=$(cat ${UNVERSIONED}/dburl)
ADMINPW=$(cat ${UNVERSIONED}/adminpw)
SITEHOST=$(cat ${UNVERSIONED}/sitehost)
echo options: > ${REPO_BASE}/drush/drush.yml
echo "  uri: https://$SITEHOST" >> ${REPO_BASE}/drush/drush.yml
$SUDO chmod a+w ${SITE}
[ -d ${SITE}/files ] && $SUDO chmod -R a+w ${SITE}/files
$SUDO rm -rf ${SITE}/files
$SUDO mkdir ${SITE}/files
$SUDO chmod 777 ${SITE}/files
$SUDO rm -rf ${REPO_BASE}/logs
$SUDO mkdir ${REPO_BASE}/logs
$SUDO chmod 777 ${REPO_BASE}/logs
[ -f ${SITE}/settings.php ] && $SUDO chmod +w ${SITE}/settings.php
cp -f ${SITE}/default.settings.php ${SITE}/settings.php
$SUDO chmod +w ${SITE}/settings.php
echo "\$settings['trusted_host_patterns'] = ['^$SITEHOST\$'];" >> ${SITE}/settings.php
$DRUSH si -y --site-name EBMS --account-pass=${ADMINPW} --db-url=${DBURL} \
       --site-mail=ebms@cancer.gov
$SUDO chmod -w ${SITE}/settings.php
pushd ${SITE} >/dev/null
$SUDO tar -xf ${UNVERSIONED}/files.tar
$SUDO rsync -a ${UNVERSIONED}/inline-images ${SITE}/files/
$SUDO chmod -R 777 ${SITE}/files
popd >/dev/null
$DRUSH state:set system.maintenance_mode 1
$DRUSH pmu contact
$DRUSH then uswds_base
$DRUSH then ebms
$DRUSH en datetime_range
$DRUSH en devel
$DRUSH en ckeditor
if grep --quiet drupal /etc/passwd;
then
    $DRUSH en externalauth
fi
$DRUSH en linkit
$DRUSH en role_delegation
$DRUSH en editor_advanced_link
$DRUSH en ebms_core
$DRUSH en ebms_board
$DRUSH en ebms_journal
$DRUSH en ebms_group
$DRUSH en ebms_message
$DRUSH en ebms_topic
$DRUSH en ebms_meeting
$DRUSH en ebms_state
$DRUSH en ebms_article
$DRUSH en ebms_import
$DRUSH en ebms_user
$DRUSH en ebms_doc
$DRUSH en ebms_review
$DRUSH en ebms_summary
$DRUSH en ebms_travel
$DRUSH en ebms_home
$DRUSH en ebms_menu
$DRUSH en ebms_report
$DRUSH en ebms_breadcrumb
$DRUSH en ebms_help
$DRUSH cset -y -q system.theme default ebms
$DRUSH cset -y -q system.site page.front /home
$DRUSH cset -y -q system.date country.default US
$DRUSH cset -y -q system.date timezone.default America/New_York
$DRUSH cset -y -q system.date timezone.user.configurable false
$DRUSH scr --script-path=$MIGRATION vocabularies
$DRUSH scr --script-path=$MIGRATION files
$DRUSH scr --script-path=$MIGRATION groups
$DRUSH scr --script-path=$MIGRATION users
$DRUSH scr --script-path=$MIGRATION boards
$DRUSH scr --script-path=$MIGRATION journals
$DRUSH scr --script-path=$MIGRATION topics
$DRUSH scr --script-path=$MIGRATION meetings
$DRUSH scr --script-path=$MIGRATION docs
$DRUSH scr --script-path=$MIGRATION summaries
$DRUSH scr --script-path=$MIGRATION states
$DRUSH scr --script-path=$MIGRATION article-tags
$DRUSH scr --script-path=$MIGRATION article-topics
date
elapsed=$(( SECONDS - start_time ))
eval "echo Elapsed time: $(date -ud "@$elapsed" +'$((%s/3600/24)) days %H hr %M min %S sec')"
