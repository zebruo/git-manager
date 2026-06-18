<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Stash : stashList, stashSave, stashApply, stashDrop

switch ($action) {

    case 'stashList':
        $result = execGit('git stash list --format="%gd|%gs|%ci"');
        $stashes = [];

        if ($result['code'] === 0 && !empty(trim($result['output']))) {
            foreach (explode("\n", $result['output']) as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $parts = explode('|', $line, 3);
                if (count($parts) >= 2) {
                    $stashes[] = [
                        'id'      => $parts[0],
                        'message' => $parts[1],
                        'date'    => $parts[2] ?? ''
                    ];
                }
            }
        }

        echo json_encode(['success' => true, 'data' => ['stashes' => $stashes, 'count' => count($stashes)]]);
        break;

    case 'stashSave':
        $message          = $input['message'] ?? '';
        $includeUntracked = $input['includeUntracked'] ?? true;

        $cmd = 'git stash push';
        if ($includeUntracked) $cmd .= ' --include-untracked';
        if (!empty($message)) $cmd .= ' -m ' . escapeArg($message);

        $result = execGit($cmd);

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } elseif (strpos($result['output'], 'No local changes') !== false) {
            echo json_encode(['success' => false, 'error' => 'Aucune modification à mettre de côté']);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output'] ?: 'Modifications mises de côté']);
        }
        break;

    case 'stashApply':
        $stashId = $input['stashId'] ?? 'stash@{0}';
        $drop    = $input['drop'] ?? true;

        if (!validateStashId($stashId)) {
            echo json_encode(['success' => false, 'error' => 'Identifiant de stash invalide']); break;
        }

        $stashIdEscaped = escapeArg($stashId);
        $result = $drop
            ? execGit("git stash pop {$stashIdEscaped}")
            : execGit("git stash apply {$stashIdEscaped}");

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            $label = $drop ? 'restaurées et supprimées du stash' : 'restaurées (stash conservé)';
            echo json_encode(['success' => true, 'output' => $result['output'] ?: "Modifications {$label}"]);
        }
        break;

    case 'stashDrop':
        $stashId = $input['stashId'] ?? '';

        if (empty($stashId) || !validateStashId($stashId)) {
            echo json_encode(['success' => false, 'error' => 'Identifiant de stash invalide']); break;
        }

        $result = execGit("git stash drop " . escapeArg($stashId));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output'] ?: 'Stash supprimé']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
