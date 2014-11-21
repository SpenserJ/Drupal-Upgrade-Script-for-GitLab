#!/usr/bin/env php
<?php
require_once 'helper.php';

if (is_file('config.php') === false) {
  consoleLog('config.php does not exist. Please copy and configure config.php.example', 'error');
  exit;
}

require_once 'config.php';

if (empty($clonePath) === true || is_dir($clonePath) === false) {
  consoleLog('Directory for $clonePath (' . $clonePath . ') does not exist', 'error');
  exit;
}

if (empty($sites) === true) {
  consoleLog('No sites to upgrade in config.php', 'error');
  exit;
}

foreach ($sites as $siteName => $git) {
  // Change back to our main clone path
  cd($clonePath);

  consoleLog("Updating $siteName at $git");

  // If the repository has already been cloned, reset it to head, and pull 
  // latest commits on master
  if (is_dir($siteName) === true) {
    cd($siteName);
    // If there is no git repository here, throw an error
    if (is_dir('.git') === false) {
      consoleLog("No git repository found in $sitename. Please delete this folder and try again.", 'error');
      continue;
    }
    consoleLog('Resetting and pulling repository to latest master');
    $result = execCommand('git reset --hard HEAD');
    $result = execCommand('git checkout master');
    $result = execCommand('git clean -fd');
    $result = execCommand('git pull origin master');
  } else {
    // If the repository doesn't exist, clone it now
    consoleLog('Cloning repository from remote');
    $result = execCommand('git clone @git @siteName', array(
      '@git' => $git,
      '@siteName' => $siteName,
    ));
    cd($siteName);
  }

  // We're now in the $siteName repository
  consoleLog('Repository is ready for updates');

  // Execute a drush 
}
