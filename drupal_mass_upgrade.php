#!/usr/bin/env php
<?php
require_once 'helper.php';

if (is_file('config.php') === false) {
  consoleLog('config.php does not exist. Please copy and configure config.php.example', 'error');
  exit;
}

require_once 'config.php';
require_once 'git.php';
require_once 'vendor/autoload.php';

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
execCommand('drush up --security-only', array(), false);
$latestCore = getCoreVersion();

foreach ($sites as $siteName => $git) {
  // Display a divider to make each site easier to distinguish
  displayDivider();

  // Change back to our main clone path
  cd($clonePath);

  $totalDBUpdates = 0;
  $coreCommit = false;
  $moduleCommits = array();
  $devModules = array();

  consoleLog("Updating $siteName at $git");

  // If the repository has already been cloned, reset it to head, and pull
  // latest commits on master
  if (is_dir($siteName) === true) {
    cd($siteName);
    // If there is no git repository here, throw an error
    if (is_dir('.git') === false) {
      consoleLog("No git repository found in $siteName. Please delete this folder and try again.", 'error');
      continue;
    }
    execCommand('git reset --hard HEAD', array(), false);
    execCommand('git checkout master', array(), false);
    execCommand('git clean -fd', array(), false);
    execCommand('git pull origin master', array(), false);
    execCommand('git branch -D upgrade_security_release', array(), false);
  } else {
    // If the repository doesn't exist, clone it now
    $result = execCommand('git clone @git @siteName', array(
      '@git' => $git,
      '@siteName' => $siteName,
    ), array(), false);
    cd($siteName);
  }

  // We only support Drupal 7 at the moment
  $coreVersion = getCoreVersion();
  if ($coreVersion === false || $coreVersion['major'] != '7') {
    consoleLog('Cannot update ' . $siteName . ' - Upgrade script only supports Drupal 7 sites', 'error');
    continue;
  }

  execCommand('git checkout -b upgrade_security_release', array(), false);

  // Copy over the settings file, so that we can run drush up commands
  copy($clonePath . '/_drupal_7/sites/default/settings.php', 'sites/default/settings.php');

  // Check if we need to do a core update
  $coreOutdated = version_compare($coreVersion['full'], $latestCore['full'], '<');
  if ($coreOutdated === true) {
    $diffGitignore = diffAgainstDrupal($coreVersion['full'], '.gitignore');
    $diffHtaccess = diffAgainstDrupal($coreVersion['full'], '.htaccess');

    execCommand('drush upc drupal --security-only -y', array(), false);

    $dbUp = isCommitDBChange();
    if ($dbUp === false) { $dbUp = ''; }
    else {
      $totalDBUpdates += $dbUp;
      $dbUp = ' [' . $dbUp . ' DB Updates]';
    }

    $coreCommit = $message = 'Security update for Drupal ' .
      $coreVersion['full'] . ' to ' .  $latestCore['full'] . $dbUp;
    gitCommitAll($message);

    consoleLog('Reapplying custom changes to .gitignore and .htaccess');
    applyPatch($diffGitignore);
    applyPatch($diffHtaccess);
    gitCommitAll('Reapplying changes to .gitignore and .htaccess');
  }

  $infoFiles = getInfoFiles();
  foreach ($infoFiles as $moduleName => $moduleInfo) {
    $currentVersion = $moduleInfo['version'];
    $currentDatestamp = $moduleInfo['datestamp'];

    if (strpos($currentVersion, '-dev') !== false) {
      $devModules[] = $moduleInfo['name'] . ' ' . $currentVersion;
      consoleLog($moduleInfo['name'] . ' ' . $currentVersion . ' is currently ' .
        'using a dev release. Upgrade this to a stable release immediately!', 'error');
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
      if ($release->version_major != $moduleInfo['version_parsed']['major']) { continue; }

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
      consoleLog($moduleInfo['name'] . ' ' . $currentVersion . ' can be updated to ' . $version, 'info');
      execCommand('drush dl @moduleVersion -y', array('@moduleVersion' => $moduleName . '-' . $version), false);
      $dbUp = isCommitDBChange();
      if ($dbUp === false) { $dbUp = ''; }
      else {
        $totalDBUpdates += $dbUp;
        $dbUp = ' [' . $dbUp . ' DB Updates]';
      }
      $moduleCommits[] = $message = 'Security update for ' .
        $moduleInfo['name'] . ' ' . $currentVersion . ' to ' . $version . $dbUp;
      gitCommitAll($message);
    }
  }

  // If nothing has been done, skip the push and merge request
  if ($coreCommit === false && empty($moduleCommits) === true) {
    continue;
  }

  execCommand('git push -f origin upgrade_security_release', array(), false);

  $mergeBody = array();

  if ($coreCommit !== false) {
    $mergeBody[] = "### Core Update\n\n* " . $coreCommit;
  }

  if (empty($moduleCommits) === false) {
    $mergeBody[] = "### Module Updates\n\n* " . implode("\n* ", $moduleCommits);
  }

  if (empty($devModules) === false) {
    $mergeBody[] = '### :-1: Modules running development releases - ' .
      "Update these to stable immediately! :-1:\n\n* " .
      implode("\n* ", $devModules);
  }

  $mergeBody[] = "### Output\n\n    " . implode("\n    ", str_replace("\n", "\n    ", getSiteOutput()));

  $dbUpdates = ($totalDBUpdates === 0) ? '' : ' [' . $totalDBUpdates . ' DB Updates]';

  preg_match('{:(.+)\.git$}', $git, $siteProjectName);
  $siteProjectName = $siteProjectName[1];
  createMergeRequest(
    $siteProjectName,
    'upgrade_security_release',
    'master',
    'Upgrade Security Release' . $dbUpdates,
    implode("\n\n", $mergeBody)
  );
}
