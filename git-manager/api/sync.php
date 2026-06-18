<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Synchronisation : push, pull, fetch

switch ($action) {

    case 'push':
        $currentBranch = trim(execGit('git branch --show-current')['output']);

        if (empty($currentBranch)) {
            echo json_encode(['success' => false, 'error' => 'HEAD détaché - impossible de push']); break;
        }

        $hasCommits = execGit('git rev-parse HEAD');
        if ($hasCommits['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Aucun commit sur la branche — faites un premier commit avant de push']); break;
        }

        $upstream = trim(execGit("git config --get branch.{$currentBranch}.remote")['output']);

        if (empty($upstream)) {
            $result = execGit("git push -u origin {$currentBranch}");
            $message = "Branche '{$currentBranch}' publiée et push effectué";
        } else {
            $result = execGit('git push origin');
            $message = 'Push effectué';
        }

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output'] ?: $message]);
        }
        break;

    case 'pull':
        $rebase = !empty($input['rebase']);
        $cmd = $rebase ? 'git pull --rebase origin' : 'git pull origin';
        $result = execGit($cmd);

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output']]);
        }
        break;

    case 'fetch':
        $result = execGit('git fetch origin --prune');

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output'] ?: 'Fetch effectué']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
