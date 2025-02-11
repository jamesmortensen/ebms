<?php

require(__DIR__ . '/console-log.php');

// Find out where the data is.
$repo_base = getenv('REPO_BASE') ?: '/var/www/ebms';

// Load the maps generated by vocabularies.php -- we'll add to them.
$start = microtime(TRUE);
$json = file_get_contents("$repo_base/unversioned/maps.json");
$maps = json_decode($json, TRUE);

// Create the group entities.
$n = 0;
$keys = ['subgroups', 'ad_hoc_groups'];
foreach ($keys as $key) {
  $fp = fopen("$repo_base/unversioned/exported/$key.json", 'r');
  while (($line = fgets($fp)) !== FALSE) {
    $values = json_decode($line, TRUE);
    $original_id = $values['id'];
    unset($values['id']);
    $group = \Drupal\ebms_group\Entity\Group::create($values);
    $group->save();
    $maps[$key][$original_id] = $group->id();
    $n++;
  }
}

// Save the augmented maps.
$fp = fopen("$repo_base/unversioned/maps.json", 'w');
fwrite($fp, json_encode($maps, JSON_PRETTY_PRINT));
fclose($fp);
$elapsed = round(microtime(TRUE) - $start);
log_success("Successfully loaded: $n groups", $elapsed);
