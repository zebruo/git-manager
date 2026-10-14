<?php
/** @var string $action */
/** @var array  $config */
/** @var string $repoParam */
/** @var string $repoPath */
/** @var array  $reposRoots */
/** @var array  $reposRootsRaw */
/** @var bool   $hasReposRoots */

switch ($action) {

    case 'listRepos':
        if (empty($reposRootsRaw)) {
            echo json_encode(['success' => true, 'data' => [], 'roots' => []]);
            break;
        }

        if (!$hasReposRoots) {
            echo json_encode(['success' => false, 'error' => 'reposRoot introuvable : ' . implode(', ', $reposRootsRaw)]);
            break;
        }

        $repos = [];
        foreach ($reposRoots as $rootIndex => $root) {
            if (!$root) continue;
            $rootRepos = [];
            foreach (scandir($root) as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $root . DIRECTORY_SEPARATOR . $item;
                if (!is_dir($path) || !is_dir($path . DIRECTORY_SEPARATOR . '.git')) continue;

                // Ignorer les liens symboliques dont la cible réelle sort de la racine :
                // git-api.php refuserait de toute façon de les ouvrir (confinement via realpath),
                // autant ne pas les lister pour éviter un dépôt visible mais inutilisable.
                $resolvedItem = realpath($path);
                if (!$resolvedItem || $resolvedItem === $root || !isInsideRoot($resolvedItem, $root)) continue;

                $rootRepos[] = ['id' => makeRepoId($rootIndex, $item), 'name' => $item, 'root' => $rootIndex];
            }
            usort($rootRepos, fn($a, $b) => strcmp($a['name'], $b['name']));
            $repos = array_merge($repos, $rootRepos);
        }

        // Identifier le dépôt courant uniquement si un ?repo= est explicitement dans l'URL
        $currentRepo = null;
        if (!empty($repoParam)) {
            [$rootIndex] = parseRepoId($repoParam);
            $root = $reposRoots[$rootIndex] ?? null;
            $resolvedCurrent = realpath($repoPath) ?: '';
            if ($root && $resolvedCurrent && $resolvedCurrent !== $root && isInsideRoot($resolvedCurrent, $root)) {
                $name = basename($resolvedCurrent);
                $currentRepo = ['id' => makeRepoId($rootIndex, $name), 'name' => $name, 'root' => $rootIndex];
            }
        }

        echo json_encode([
            'success'     => true,
            'data'        => $repos,
            'roots'       => array_map(fn($root, $raw) => $root ?? $raw, $reposRoots, $reposRootsRaw),
            'currentRepo' => $currentRepo,
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
}
