<?php

$root = dirname(__DIR__);
$pluginFile = $root . '/wc-stock-sync-dwenn/wc-stock-sync-dwenn.php';
$changelogFile = $root . '/CHANGELOG.md';

$plugin = file_get_contents($pluginFile);
if ($plugin === false) {
  fwrite(STDERR, "Unable to read plugin file.\n");
  exit(1);
}

if (!preg_match('/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/m', $plugin, $pluginMatch)) {
  fwrite(STDERR, "Plugin header version not found.\n");
  exit(1);
}
$pluginVersion = $pluginMatch[1];

$changelog = file_get_contents($changelogFile);
if ($changelog === false) {
  fwrite(STDERR, "Unable to read CHANGELOG.md.\n");
  exit(1);
}

if (!preg_match('/^## \[([0-9]+\.[0-9]+\.[0-9]+)\] - /m', $changelog, $changelogMatch)) {
  fwrite(STDERR, "Latest changelog version not found.\n");
  exit(1);
}
$changelogVersion = $changelogMatch[1];

if ($pluginVersion !== $changelogVersion) {
  fwrite(STDERR, "Version mismatch: plugin={$pluginVersion}, changelog={$changelogVersion}.\n");
  exit(1);
}

$refType = getenv('GITHUB_REF_TYPE') ?: '';
$refName = getenv('GITHUB_REF_NAME') ?: '';
$ref = getenv('GITHUB_REF') ?: '';

if ($refType === 'tag' || strpos($ref, 'refs/tags/') === 0) {
  $tag = $refName ?: substr($ref, strlen('refs/tags/'));
  $expectedTag = 'v' . $pluginVersion;

  if ($tag !== $expectedTag) {
    fwrite(STDERR, "Tag mismatch: tag={$tag}, expected={$expectedTag}.\n");
    exit(1);
  }

  echo "Version check passed: tag {$tag}, plugin {$pluginVersion}, changelog {$changelogVersion}.\n";
  exit(0);
}

echo "Version check passed: plugin {$pluginVersion}, changelog {$changelogVersion}. Tag check skipped outside tag builds.\n";
