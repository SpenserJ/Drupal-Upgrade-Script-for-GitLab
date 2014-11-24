#!/usr/bin/env php
<?php
require_once 'helper.php';

if (is_file('config.php') === false) {
  consoleLog('config.php does not exist. Please copy and configure config.php.example', 'error');
  exit;
}

require_once 'config.php';

if (empty($clonePath) === true || is_dir($clonePath) === false) {
  if (mkdir($clonePath) === false) {
    consoleLog('Directory for $clonePath (' . $clonePath . ') does not exist, and could not be automatically created', 'error');
    exit;
  }
  consoleLog('Created directory for $clonePath (' . $clonePath . ')');
}

if (empty($sites) === true) {
  consoleLog('No sites to upgrade in config.php', 'error');
  exit;
}

// Update base drupal installs
cd($clonePath . '/_drupal_7');
consoleLog('Updating Base Drupal 7 site');
execCommand('drush up --security-only');
$latestCore = getCoreVersion();

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

  execCommand('git branch -D upgrade_security_release');
  execCommand('git checkout -b upgrade_security_release');

  // Copy over the settings file, so that we can run drush up commands
  copy($clonePath . '/_drupal_7/sites/default/settings.php', 'sites/default/settings.php');

  // We're now in the $siteName repository
  consoleLog('Repository is ready for updates');
  $coreVersion = getCoreVersion();
  $coreOutdated = version_compare($coreVersion, $latestCore, '<');
  if ($coreOutdated === true) {
    $dbUp = '';
    execCommand('drush upc drupal --security-only -y');
    consoleLog('Check for any database updates!', 'error');
    execCommand('git add .');
    consoleLog('Don\'t forget to fix .htaccess and .gitignore!', 'error');
    execCommand('git commit -m @message', array(
      '@message' => 'Updating Drupal Core to ' . $latestCore . $dbUp,
    ));
  }

  // Execute a drush 
}
