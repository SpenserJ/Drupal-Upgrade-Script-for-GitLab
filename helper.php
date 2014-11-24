<?php

function getConsoleWidth() {
  return exec('tput cols');
}

function consoleLog($message, $type = 'info') {
  $prefixTypes = array(
    'error' => "\033[31m[Error]\033[0m ",
    'warning' => "\033[33m[Warning]\033[0m ",
    'success' => "\033[32m[Success]\033[0m ",
    'info' => "\33[34m[Info]\33[0m ",
    'command' => "\33[35m[Command]\33[0m ",
    'results' => "\33[35m[Results]\33[0m ",
  );
  $prefix = isset($prefixTypes[$type]) ? $prefixTypes[$type] : '';

  $width = getConsoleWidth();
  $prefixWidth = strlen($type) + 3;
  $prefixIndent = str_pad('', $prefixWidth, ' ');
  $lineWidth = ($width - $prefixWidth);

  $splitLines = explode("\n", $message);
  foreach ($splitLines as $i => &$line) {
    preg_match_all('/(.{1,' . $lineWidth . '})(?:\s+|$)/', $line, $breakLine);
    $line = $prefixIndent . implode("\n" . $prefixIndent, $breakLine[1]);
  }

  echo $prefix . substr(implode("\n", $splitLines), $prefixWidth) . "\n";
}

function execCommand($command, $params = array()) {
  foreach ($params as $key => $value) {
    $command = str_replace($key, escapeshellarg($value), $command);
  }
  consoleLog($command, 'command');
  $output = array();
  $returnVar = 0;
  $overloadedConsoleWidth = 'COLUMNS=' . (getConsoleWidth() - 10) . ' ';
  $finalCommand = $overloadedConsoleWidth . $command . ' 2>&1';
  exec($finalCommand, $output, $returnVar);

  // If there was output, render it to the screen
  if (empty($output) === false) {
    consoleLog(implode("\n", $output), 'results');
  }

  return array('stdout' => $output, 'return' => $returnVar);
}

function cd($path) {
  if (is_dir($path) === false) {
    consoleLog("Could not cd into $path", 'error');
    exit;
  }
  consoleLog("cd $path", 'command');
  chdir($path);
}

function getCoreVersion() {
  $bootstrap = file_get_contents('includes/bootstrap.inc');
  preg_match("/define\('VERSION', '([\d\.]+(?:-\w+)?)'\);/", $bootstrap, $coreVersion);
  return $coreVersion[1];
}

function getInfoFiles() {
  $infoFiles = array();
  $paths = array('sites/all/modules', 'sites/all/themes');
  foreach ($paths as $path) {
    if (is_dir($path) === false) { continue; }

    $content = scandir($path);
    foreach ($content as $moduleName) {
      $modulePath = $path . '/' . $moduleName;
      if (is_dir($modulePath) === true &&
          is_file($modulePath . '/' . $moduleName . '.info') === true) {
        $infoData = file_get_contents($modulePath . '/' . $moduleName . '.info');

        // Ensure this module came from Drupal.org and is not custom
        if (stripos($infoData, 'Information added by Drupal.org') === false) {
          continue;
        }

        $info = drupal_parse_info_format($infoData);
        if (preg_match('/^(\d+\.x-)?(\d+)\.(\d+)(?:-(\w+))?.*$/', $info['version'], $matches)) {
          $info['version_major'] = $matches[2];
          $info['version_minor'] = $matches[3];
          if (empty($matches[4]) === false) {
            $info['version_extra'] = $matches[4];
          }
        }
        $infoFiles[$moduleName] = $info;
      }
    }
  }

  return $infoFiles;
}
