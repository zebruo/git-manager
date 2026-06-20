<?php
/** @var array $config */

switch ($action) {

    case 'listRepos':
        $reposRoot = isset($config['reposRoot']) ? trim($config['reposRoot']) : '';

        if (empty($reposRoot)) {
            echo json_encode(['success' => true, 'data' => []]);
            break;
        }

        $resolvedRoot = realpath($reposRoot);
        if (!$resolvedRoot || !is_dir($resolvedRoot)) {
            echo json_encode(['success' => false, 'error' => "reposRoot introuvable : {$reposRoot}"]);
            break;
        }

        $repos = [];
        foreach (scandir($resolvedRoot) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $resolvedRoot . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && is_dir($path . DIRECTORY_SEPARATOR . '.git')) {
                $repos[] = $item;
            }
        }

        sort($repos);

        // Identifier le dépôt courant (peut ne pas être initialisé encore)
        $currentRepo = null;
        $resolvedCurrent = realpath($repoPath) ?: '';
        if ($resolvedCurrent && $resolvedCurrent !== $resolvedRoot
            && str_starts_with($resolvedCurrent, $resolvedRoot . DIRECTORY_SEPARATOR)) {
            $currentRepo = basename($resolvedCurrent);
        }

        echo json_encode(['success' => true, 'data' => $repos, 'root' => $resolvedRoot, 'currentRepo' => $currentRepo]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
}
