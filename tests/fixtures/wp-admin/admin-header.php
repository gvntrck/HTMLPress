<?php
// Simulate another plugin probing replace_editor during header generation.
$GLOBALS['header_count']++;
if ($GLOBALS['header_count'] > 1) throw new RuntimeException('Recursive admin header');
HTMLPress_Admin::replace_editor(false, $GLOBALS['test_post']);
