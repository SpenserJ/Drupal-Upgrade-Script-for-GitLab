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

// Get the common Drupal functions
require_once $clonePath . '/_drupal_7/includes/common.inc';

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

  $infoFiles = getInfoFiles();
  foreach ($infoFiles as $moduleName => $moduleInfo) {
    consoleLog('Checking for security updates for ' . $moduleName);
    $currentVersion = $moduleInfo['version'];
    $currentDatestamp = $moduleInfo['datestamp'];

    if (strpos($currentVersion, '-dev') !== false) {
      consoleLog($moduleName . ' is currently using a dev release. Upgrade this to a stable release immediately!', 'error');
      continue;
    }

    $securityRelease = false;
    try {
      $releaseXml = simplexml_load_file('http://updates.drupal.org/release-history/' . $moduleName . '/7.x');
    } catch (Exception $e) {
      $releaseXml = false;
    }

    if (isset($releaseXml->releases) === false) {
      consoleLog('Could not find Drupal.org project for ' . $moduleName, 'error');
      continue;
    }

    foreach ($releaseXml->releases->children() as $release) {
      // If this release is older than the current version, ignore it
      if ($release->date <= $currentDatestamp) { continue; }
      // If this release is a dev release, ignore it
      if (isset($release->version_extra) === true && $release->version_extra == 'dev') {
        continue;
      }
      // If this release is a different major version, ignore it
      if ($release->version_major != $moduleInfo['version_major']) { continue; }

      // Skip releases that don't have tags
      if (empty($release->terms) === true) { continue; }

      // Check if this release is a security release
      foreach ($release->terms->children() as $releaseTerm) {
        if ($releaseTerm->name != 'Release type') { continue; }
        if ($releaseTerm->value != 'Security update') { continue; }
        $securityRelease = $release;
        break;
      }

      // If we've found a security release, we don't need to process any more
      if ($securityRelease !== false) { break; }
    }

    if ($securityRelease !== false) {
      $version = $securityRelease->version;
      consoleLog($moduleName . ' (' . $currentVersion . ') can be updated to ' . $version, 'warning');
      execCommand('drush dl @moduleVersion -y', array('@moduleVersion' => $moduleName . '-' . $version));
      execCommand('git add .');
      execCommand('git commit -m @message', array('@message' => 'Security Update for ' . $moduleName . ' (' . $currentVersion . ') to ' . $version));
    }
  }
}
