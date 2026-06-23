<?php
$file = __DIR__ . '/vote_cast.php';
$src  = file_get_contents($file);

$find = '        <p style="font-size:.82rem;color:#999;margin-bottom:24px;">
          You have voted on all motions currently open in this meeting.
          Thank you for participating.<br>
          You will be logged out in 10 seconds.
        </p>
        <a href="logout_vote.php" class="btn btn-primary btn-block">
          OK — Logout
        </a>
        <script>
        setTimeout(function() {
            window.location.href = \'logout_vote.php\';
        }, 10000);
        </script>';

$replace = '        <p style="font-size:.82rem;color:#555;margin-bottom:24px;">
          You have voted on all motions currently open so far.<br>
          <strong>Please keep this screen open</strong> — additional motions
          may be opened during the meeting. This page will continue to work
          for the full duration of the meeting.<br><br>
          When the meeting is complete, use the button below to log out.
        </p>
        <a href="vote_cast.php?action=list" class="btn btn-primary btn-block" style="margin-bottom:10px;">
          ↩ Back to Motions List
        </a>
        <a href="logout_vote.php" class="btn btn-navy btn-block">
          Done — Logout
        </a>';

$count = substr_count($src, $find);
echo "Occurrences found: $count\n";
if ($count === 1) {
    file_put_contents($file, str_replace($find, $replace, $src));
    echo "Patch 3 applied OK.\n";
} else {
    echo "PATCH NOT APPLIED — check count above.\n";
}