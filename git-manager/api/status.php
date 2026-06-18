<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Statut du dépôt, infos remote : status, repoInfo, addRemote, removeRemote

switch ($action) {

    case 'status':
        $branch = trim(execGit('git branch --show-current')['output']);

        $isDetached = empty($branch);
        $detachedAt = '';
        if ($isDetached) {
            $detachedAt = trim(execGit('git rev-parse --short HEAD')['output']);
        }

        $modified  = filterGitWarnings(explode("\n", execGit('git diff --name-only')['output']));
        $staged    = filterGitWarnings(explode("\n", execGit('git diff --cached --name-only --diff-filter=AM')['output']));
        $stagedDeleted = filterGitWarnings(explode("\n", execGit('git diff --cached --name-only --diff-filter=D')['output']));
        $untracked = filterGitWarnings(explode("\n", execGit('git ls-files --others --exclude-standard')['output']));

        $ahead = 0;
        $behind = 0;
        if (!$isDetached) {
            $aheadBehind = execGit('git rev-list --left-right --count origin/' . $branch . '...' . $branch);
            $counts = preg_split('/\s+/', trim($aheadBehind['output']));
            $behind = isset($counts[0]) ? intval($counts[0]) : 0;
            $ahead  = isset($counts[1]) ? intval($counts[1]) : 0;
        }

        $allFiles = filterGitWarnings(explode("\n", execGit('git ls-files')['output']));

        $stashCountResult = execGit('git stash list');
        $stashCount = ($stashCountResult['code'] === 0 && !empty(trim($stashCountResult['output'])))
            ? count(array_filter(explode("\n", $stashCountResult['output'])))
            : 0;

        echo json_encode([
            'success' => true,
            'data' => [
                'branch'       => $branch,
                'isDetached'   => $isDetached,
                'detachedAt'   => $detachedAt,
                'modified'     => array_values($modified),
                'staged'       => array_values($staged),
                'stagedDeleted'=> array_values($stagedDeleted),
                'untracked'    => array_values($untracked),
                'ahead'        => $ahead,
                'behind'       => $behind,
                'allFiles'     => array_values($allFiles),
                'stashCount'   => $stashCount
            ]
        ]);
        break;

    case 'repoInfo':
        $remoteUrl = trim(execGit('git config --get remote.origin.url')['output']);
        $remoteUrl = preg_replace('/^(https?:\/\/)[^@]+@/', '$1', $remoteUrl);

        $repoName = '';
        if (preg_match('/github\.com[\/:]([^\/]+\/[^\/\.]+)/', $remoteUrl, $matches)) {
            $repoName = $matches[1];
        } elseif (preg_match('/([^\/]+\/[^\/\.]+)(\.git)?$/', $remoteUrl, $matches)) {
            $repoName = $matches[1];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'url'       => $remoteUrl,
                'name'      => $repoName,
                'path'      => $repoPath,
                'hasRemote' => !empty($remoteUrl)
            ]
        ]);
        break;

    case 'addRemote':
        $url = $input['url'] ?? '';

        if (empty($url)) {
            echo json_encode(['success' => false, 'error' => 'URL du dépôt requise']); break;
        }

        $existingRemote = trim(execGit('git config --get remote.origin.url')['output']);
        if (!empty($existingRemote)) {
            $result = execGit("git remote set-url origin \"{$url}\"");
            $message = 'Remote origin mis à jour';
        } else {
            $result = execGit("git remote add origin \"{$url}\"");
            $message = 'Remote origin ajouté';
        }

        if ($result['code'] === 0) {
            $fetchResult = execGit("git fetch origin 2>&1");
            $response = ['success' => true, 'output' => $message . ' : ' . $url];
            if ($fetchResult['code'] !== 0) {
                $response['fetchError'] = $fetchResult['output'];
                $response['warning'] = 'Remote ajouté mais impossible de récupérer les branches (vérifiez l\'authentification SSH ou HTTPS)';
            }
            echo json_encode($response);
        } else {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        }
        break;

    case 'removeRemote':
        $result = execGit('git remote remove origin');

        if ($result['code'] === 0) {
            echo json_encode(['success' => true, 'output' => 'Remote origin supprimé']);
        } else {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
