<?php
/** @var string $action */
/** @var array  $config */
/** @var string $repoParam */
/** @var string $repoPath */

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
            if (!is_dir($path) || !is_dir($path . DIRECTORY_SEPARATOR . '.git')) continue;

            // Ignorer les liens symboliques dont la cible réelle sort de reposRoot :
            // git-api.php refuserait de toute façon de les ouvrir (confinement via realpath),
            // autant ne pas les lister pour éviter un dépôt visible mais inutilisable.
            $resolvedItem = realpath($path);
            if (!$resolvedItem || !str_starts_with($resolvedItem, $resolvedRoot . DIRECTORY_SEPARATOR)) continue;

            $repos[] = $item;
        }

        sort($repos);

        // Identifier le dépôt courant uniquement si un ?repo= est explicitement dans l'URL
        $currentRepo = null;
        if (!empty($repoParam)) {
            $resolvedCurrent = realpath($repoPath) ?: '';
            if ($resolvedCurrent && $resolvedCurrent !== $resolvedRoot
                && str_starts_with($resolvedCurrent, $resolvedRoot . DIRECTORY_SEPARATOR)) {
                $currentRepo = basename($resolvedCurrent);
            }
        }

        echo json_encode(['success' => true, 'data' => $repos, 'root' => $resolvedRoot, 'currentRepo' => $currentRepo]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
}
