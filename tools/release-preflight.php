<?php

// Read-only checklist gate. This never tags, publishes or mutates a database.
$root = dirname(__DIR__);
$checklist = file_get_contents($root . '/IMPLEMENTATION_TODO.md');
$blockers = [];
foreach (preg_split('/\R/', $checklist) as $line) {
    if (preg_match('/^- \[ \] /', $line)
        && preg_match('/\*\*(?:P0\b|AC(?:\d|-)|ACG-|.*GATE-|BLOCK-)/', $line)) {
        $blockers[] = $line;
    }
}
if ($blockers !== []) {
    fwrite(STDERR, "RELEASE BLOCKED: unresolved checklist requirements\n" . implode("\n", $blockers) . "\n");
    exit(1);
}
fwrite(STDOUT, "Checklist clear. This is not proof of runtime compatibility: require clean-install, database, external-bridge and security evidence before tagging.\n");
