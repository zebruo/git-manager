<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Statut du dépôt, infos remote : status, repoInfo, addRemote, removeRemote, setUpstream, removeUpstream

switch ($action) {

    case 'status':
        // git status --porcelain=v2 --branch remplace 6 appels git séparés
        $statusResult = execGit('git status --porcelain=v2 --branch');
        $lines = explode("\n", $statusResult['output']);

        $branch = '';
        $isDetached = false;
        $detachedAt = '';
        $ahead = 0;
        $behind = 0;
        $hasUpstream = false; // sans branche suivie, Git ne fournit pas ahead/behind
        $modified = [];
        $staged = [];
        $stagedDeleted = [];
        $deletedUnstaged = [];
        $untracked = [];
        $conflicted = [];

        foreach ($lines as $line) {
            if ($line === '') continue;
            if (str_starts_with($line, '# branch.head ')) {
                $head = substr($line, 14);
                $isDetached = ($head === '(detached)');
                if (!$isDetached) $branch = $head;
            } elseif (str_starts_with($line, '# branch.oid ')) {
                $detachedAt = substr($line, 13, 7); // toujours capturé, utilisé seulement si isDetached
            } elseif (str_starts_with($line, '# branch.upstream ')) {
                $hasUpstream = true;
            } elseif (str_starts_with($line, '# branch.ab ')) {
                if (preg_match('/\+(\d+) -(\d+)/', $line, $m)) {
                    $ahead  = intval($m[1]);
                    $behind = intval($m[2]);
                }
            } elseif ($line[0] === '1') {
                // Fichier modifié ordinaire : "1 XY <sub> <mH> <mI> <mW> <hH> <hI> <path>"
                $parts = explode(' ', $line);
                $xy   = $parts[1];
                $file = end($parts);
                if ($xy[1] === 'M') $modified[] = $file;
                if ($xy[1] === 'D') $deletedUnstaged[] = $file;
                if (in_array($xy[0], ['M', 'A'])) $staged[] = $file;
                if ($xy[0] === 'D') $stagedDeleted[] = $file;
            } elseif ($line[0] === '2') {
                // Renommage/copie stagé : "2 XY <sub> <mH> <mI> <mW> <hH> <hI> <score> <path>\t<origPath>"
                $parts = explode(' ', $line);
                $xy   = $parts[1];
                $file = explode("\t", end($parts), 2)[0];
                if ($xy[1] === 'M') $modified[] = $file;
                if (in_array($xy[0], ['R', 'C'])) $staged[] = $file;
            } elseif ($line[0] === 'u') {
                // Fichier en conflit : "u XY <sub> <m1> <m2> <m3> <mW> <h1> <h2> <h3> <path>"
                $parts = explode(' ', $line);
                $conflicted[] = end($parts);
            } elseif ($line[0] === '?') {
                $untracked[] = substr($line, 2);
            }
        }

        $allFiles = filterGitWarnings(explode("\n", execGit('git ls-files')['output']));

        $isRebasing = (is_dir($repoPath . '/.git/rebase-merge') && file_exists($repoPath . '/.git/rebase-merge/head-name'))
                   || (is_dir($repoPath . '/.git/rebase-apply') && file_exists($repoPath . '/.git/rebase-apply/head-name'));

        $stashCountResult = execGit('git stash list');
        $stashCount = ($stashCountResult['code'] === 0 && !empty(trim($stashCountResult['output'])))
            ? count(array_filter(explode("\n", $stashCountResult['output'])))
            : 0;

        // Fork : commits de l'original (upstream/<branche>) absents de la branche courante.
        // Calcul local, d'après l'état récupéré au dernier Fetch ; null si pas de fork ou branche absente de l'original.
        $upstreamBehind = null;
        if (!$isDetached && $branch !== '' && getRemoteUrl('upstream') !== '') {
            $upstreamRef = escapeArg('refs/remotes/upstream/' . $branch);
            if (execGit("git rev-parse --verify --quiet {$upstreamRef}")['code'] === 0) {
                $upstreamBehind = (int) trim(execGit("git rev-list --count HEAD..{$upstreamRef}")['output']);
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'branch'        => $branch,
                'isDetached'    => $isDetached,
                'detachedAt'    => $detachedAt,
                'isRebasing'    => $isRebasing,
                'modified'      => $modified,
                'staged'        => $staged,
                'stagedDeleted' => $stagedDeleted,
                'deletedUnstaged' => $deletedUnstaged,
                'conflicted'    => $conflicted,
                'untracked'     => $untracked,
                'ahead'         => $ahead,
                'behind'        => $behind,
                'hasUpstream'   => $hasUpstream,
                'upstreamBehind' => $upstreamBehind,
                'allFiles'      => array_values($allFiles),
                'stashCount'    => $stashCount
            ]
        ]);
        break;

    case 'repoInfo':
        // Identifiants éventuels (https://token@...) masqués avant envoi au navigateur
        $remoteUrl   = preg_replace('/^(https?:\/\/)[^@]+@/', '$1', getRemoteUrl('origin'));
        $upstreamUrl = preg_replace('/^(https?:\/\/)[^@]+@/', '$1', getRemoteUrl('upstream'));

        // "propriétaire/dépôt" : après github.com (HTTPS ou SSH), sinon les deux derniers segments de l'URL
        $repoName = '';
        if (preg_match('/github\.com[\/:]([^\/]+\/[^\/\.]+)/', $remoteUrl, $matches)
            || preg_match('/([^\/]+\/[^\/\.]+)(\.git)?$/', $remoteUrl, $matches)) {
            $repoName = $matches[1];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'url'          => $remoteUrl,
                'name'         => $repoName,
                'path'         => $repoPath,
                'hasRemote'    => !empty($remoteUrl),
                'upstreamUrl'  => $upstreamUrl,   // dépôt original d'un fork (remote "upstream"), vide sinon
                'configExists' => file_exists(dirname(__DIR__) . '/git-config.php'),
            ]
        ]);
        break;

    case 'addRemote':
        $url = trim($input['url'] ?? '');

        if (empty($url)) {
            echo json_encode(['success' => false, 'error' => 'URL du dépôt requise']); break;
        }
        if (!isValidRemoteUrl($url)) {
            echo json_encode(['success' => false, 'error' => 'URL invalide. Utilisez une URL HTTPS ou SSH.']); break;
        }

        $existingRemote = getRemoteUrl('origin');
        if (!empty($existingRemote)) {
            $result = execGit('git remote set-url origin ' . escapeArg($url));
            $message = 'Remote origin mis à jour';
        } else {
            $result = execGit('git remote add origin ' . escapeArg($url));
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

    case 'setUpstream':
        // Dépôt original d'un fork : remote "upstream", à côté d'origin (le fork)
        $url = trim($input['url'] ?? '');
        if (!isValidRemoteUrl($url)) {
            echo json_encode(['success' => false, 'error' => 'URL du dépôt original invalide. Utilisez une URL HTTPS ou SSH.']); break;
        }

        $existing = getRemoteUrl('upstream');
        $result = execGit('git remote ' . ($existing !== '' ? 'set-url' : 'add') . ' upstream ' . escapeArg($url));
        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]); break;
        }

        $response = ['success' => true, 'output' => 'Dépôt original (upstream) ' . ($existing !== '' ? 'mis à jour' : 'ajouté') . ' : ' . $url];
        $fetchResult = execGit('git fetch upstream --prune');
        if ($fetchResult['code'] !== 0) {
            $response['warning'] = 'Dépôt original enregistré, mais impossible de le joindre (vérifiez l\'URL et l\'authentification)';
            $response['fetchError'] = $fetchResult['output'];
        }
        echo json_encode($response);
        break;

    case 'removeUpstream':
        $result = execGit('git remote remove upstream');
        echo json_encode($result['code'] === 0
            ? ['success' => true, 'output' => 'Dépôt original (upstream) retiré']
            : ['success' => false, 'error' => $result['output']]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
