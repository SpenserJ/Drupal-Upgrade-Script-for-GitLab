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
    'resultsFailed' => "\33[31m[Results]\33[0m ",
  );
  $prefix = isset($prefixTypes[$type]) ? $prefixTypes[$type] : '';
  if ($type === 'resultsFailed') { $type = 'results'; }

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

function execCommand($command, $params = array(), $outputResults = true) {
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
  if (empty($output) === false && $outputResults !== false) {
    $type = ($returnVar == 0) ? 'results' : 'resultsFailed';
    consoleLog(implode("\n", $output), $type);
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

function diffAgainstDrupal($version, $file) {
  $tempFilename = tempnam(sys_get_temp_dir(), $file);
  $fpTemp = fopen($tempFilename, 'w');
  fwrite($fpTemp, file_get_contents('https://raw.githubusercontent.com/drupal/drupal/' . $version . '/' . $file));
  fclose($fpTemp);
  $diff = execCommand('diff -U 3 @original @filename', array('@filename' => $file, '@original' => $tempFilename), false);
  $diff = $diff['stdout'];
  $diff = implode("\n", $diff);
  if (empty($diff) === false) { $diff .= "\n"; }
  $tempDiff = tempnam(sys_get_temp_dir(), 'diff-' . $file);
  $fpDiff = fopen($tempDiff, 'w');
  fwrite($fpDiff, $diff);
  fclose($fpDiff);

  return $tempDiff;
}

function isCommitDBChange() {
  $diff = execCommand('git diff', array(), false);
  $diff = implode("\n", $diff['stdout']);
  preg_match_all('/function\s+.+_update_\d{4}\s*\(/', $diff, $updates);
  $totalUpdates = count($updates[0]);
  return ($totalUpdates === 0) ? false : $totalUpdates;
}
