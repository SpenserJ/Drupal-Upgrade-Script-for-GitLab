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

$tickOffset = 0;
function execCommand($command, $params = array(), $outputCommand = true) {
  global $tickOffset;

  foreach ($params as $key => $value) {
    $command = str_replace($key, escapeshellarg($value), $command);
  }
  if ($outputCommand === true) { consoleLog($command, 'command'); }
  $output = array();
  $returnVal = 0;
  $overloadedConsoleWidth = 'COLUMNS=' . (getConsoleWidth() - 10) . ' ';
  $tmpOutput = tempnam(sys_get_temp_dir(), 'command-output');
  $finalCommand = $overloadedConsoleWidth . $command . ' > ' . $tmpOutput . ' 2>&1';
  $commandStartTime = microtime(true);
  $spinnerSymbol = '/-\\|';
  $spinnerSymbol = substr($spinnerSymbol, $tickOffset) . substr($spinnerSymbol, 0, $tickOffset);
  $spinnerCommand = <<<spinner
spinner()
{
  local pid=\$1
  local delay=0.75
  local spinstr='$spinnerSymbol'
  while [ "\$(ps a | awk '{print \$1}' | grep -w \$pid)" ]; do
    local temp=\${spinstr#?}
    printf "[%c]" "\$spinstr"
    local spinstr=\$temp\${spinstr%"\$temp"}
    sleep \$delay
    printf "\b\b\b"
  done
  printf "\b\b\b"
  wait \$!
}

spinner;
  $finalCommand .= ' & spinner $!';
  $spinningCommand = $spinnerCommand . $finalCommand;
  passthru($spinningCommand, $returnVal);
  $output = trim(file_get_contents($tmpOutput));
  // Clean up the output file
  unlink($tmpOutput);

  // If there was output, render it to the screen
  if (empty($output) === false && $outputCommand !== false) {
    $type = ($returnVal == 0) ? 'results' : 'resultsFailed';
    consoleLog($output, $type);
  }
  $commandEndTime = microtime(true);
  $duration = ($commandEndTime - $commandStartTime) * 1000;
  $tickOffset = ($tickOffset + round($duration / 750)) % 4;

  return array('stdout' => $output, 'return' => $returnVal);
}

function cd($path) {
  if (is_dir($path) === false) {
    consoleLog("Could not cd into $path", 'error');
    exit;
  }
  chdir($path);
}

function displayDivider() {
  echo "   \n", str_pad('', getConsoleWidth(), '-'), "\n\n";
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
  // Remove the "potentially dangerous file name" from the patch
  $diff = str_replace(sys_get_temp_dir() . '/', '', $diff);
  if (empty($diff) === false) { $diff .= "\n"; }

  $tempDiff = tempnam(sys_get_temp_dir(), 'diff-' . $file);
  $fpDiff = fopen($tempDiff, 'w');
  fwrite($fpDiff, $diff);
  fclose($fpDiff);

  return $tempDiff;
}

function applyPatch($patch) {
  $result = execCommand('patch -p0 < @diff', array('@diff' => $patch));

  $patchComplete = true;
  if ($result['return'] !== 0) {
    consoleLog('Patching failed! Please apply the patch (' . $patch . ') ' .
      ' manually before continuing.', 'error');
    $patchComplete = false;
  }

  $badFiles = scanFilesMask('{^.*\.(?:orig|rej)$}');

  while ($patchComplete === false || count($badFiles) > 0) {
    // Set this to true, since we can't confirm if the patch was applied cleanly
    $patchComplete = true;
    if (count($badFiles) > 0) {
      $message = 'Found .rej or .orig files from a failed patch. Please ' .
        "delete these before continuing:\n" . implode("\n", $badFiles);
      consoleLog($message, 'error');
    }
    readline('Press enter when the patches have been properly applied.');
    $badFiles = scanFilesMask('{^.*\.(?:orig|rej)$}');
  }

  // Delete the patch file after we've applied it
  unlink($patch);
}

function isCommitDBChange() {
  $diff = execCommand('git diff', array(), false);
  $diff = $diff['stdout'];
  preg_match_all('/function\s+.+_update_\d{4}\s*\(/', $diff, $updates);
  $totalUpdates = count($updates[0]);
  return ($totalUpdates === 0) ? false : $totalUpdates;
}

function gitCommitAll($message) {
  execCommand('git add .', array(), false);
  execCommand('git commit -m @message', array('@message' => $message), false);
}

function scanFilesMask($mask, $dir = '.') {
  $result = array();

  foreach (scandir($dir) as $f) {
    // Ignore . and ..
    if ($f === '.' || $f === '..') { continue; }

    if (is_dir("$dir/$f") === true) {
      $result = array_merge($result, scanFilesMask($mask, "$dir/$f"));
    } else {
      if (preg_match($mask, $f) === 1) { $result[] = $dir . '/' . $f; }
    }
  }

  return $result;
}
