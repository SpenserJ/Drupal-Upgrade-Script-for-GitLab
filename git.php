<?php

require_once 'vendor/autoload.php';

function createMergeRequest($project, $sourceBranch, $targetBranch, $title, $description) {
  global $gitlabToken;

  // Sanitize the description
  $description = preg_replace('{\033\[\d+m}', '', $description);

  $gitlab = new \Gitlab\Client('https://gitlab.com/api/v3/');
  $gitlab->authenticate($gitlabToken, \Gitlab\Client::AUTH_URL_TOKEN);
  $existingMergeRequests = $gitlab->api('merge_requests')->opened($project);

  $exists = false;
  foreach ($existingMergeRequests as $mr) {
    if ($mr['source_branch'] === $sourceBranch &&
        $mr['target_branch'] === $targetBranch) {
      $exists = $mr;
      break;
    }
  }

  if ($exists === false) {
    $mr = $gitlab->api('merge_requests')->create(
      $project,
      $sourceBranch,
      $targetBranch,
      $title,
      null,
      null,
      $description
    );
  } else {
    $mr = $gitlab->api('merge_requests')->update(
      $project,
      $exists['id'],
      array(
        'title' => $title,
        'description' => $description,
      )
    );
  }
}
