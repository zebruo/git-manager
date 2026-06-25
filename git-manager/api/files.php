<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Fichiers : checkout, discardAll, checkoutCommit, removeFromRepo, untrackFile

switch ($action) {

    case 'checkout':
        $file   = $input['file'] ?? '';
        $source = $input['source'] ?? 'HEAD';

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']); break;
        }

        $allowedSources = ['HEAD', 'origin/main', 'origin/master'];
        $isValidSource = in_array($source, $allowedSources)
            || preg_match('/^HEAD~[0-9]+$/', $source)
            || preg_match('/^[a-f0-9]{7,40}$/', $source);

        if (!$isValidSource) $source = 'HEAD';

        $result = execGit('git checkout ' . escapeArg($source) . ' -- ' . escapeArg($file));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            $diffCheck = execGit('git diff --staged -- ' . escapeArg($file));
            $noChange  = empty(trim($diffCheck['output']));
            echo json_encode([
                'success'  => true,
                'noChange' => $noChange,
                'output'   => $noChange ? 'Aucune modification — le fichier à ce commit est identique à la version actuelle' : ($result['output'] ?: 'Fichier récupéré'),
            ]);
        }
        break;

    case 'discardAll':
        $result = execGit('git checkout -- .');

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => 'Modifications annulées']);
        }
        break;

    case 'checkoutCommit':
        $hash = $input['hash'] ?? '';

        if (empty($hash) || !preg_match('/^[a-f0-9]{7,40}$/', $hash)) {
            echo json_encode(['success' => false, 'error' => 'Hash de commit invalide']); break;
        }

        $backupPath = backupGitManager($repoPath);
        $result = execGit("git checkout " . escapeArg($hash) . " 2>&1");
        restoreGitManager($repoPath, $backupPath);

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => "HEAD détaché sur le commit {$hash}"]);
        }
        break;

    case 'removeFromRepo':
        $file  = $input['file'] ?? '';
        $isDir = $input['isDir'] ?? false;

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']); break;
        }

        $flag = $isDir ? '-r ' : '';
        $result = execGit("git rm {$flag}" . escapeArg($file));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            $label = $isDir ? "Dossier '{$file}'" : "Fichier '{$file}'";
            echo json_encode(['success' => true, 'output' => $result['output'] ?: "{$label} supprimé du dépôt et du disque"]);
        }
        break;

    case 'untrackFile':
        $file  = $input['file'] ?? '';
        $isDir = $input['isDir'] ?? false;

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']); break;
        }

        $flag = $isDir ? '-r ' : '';
        $result = execGit("git rm --cached {$flag}" . escapeArg($file));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            $label = $isDir ? "Dossier '{$file}'" : "Fichier '{$file}'";
            echo json_encode(['success' => true, 'output' => $result['output'] ?: "{$label} retiré du suivi Git (conservé sur le disque)"]);
        }
        break;

    case 'fileDiff':
        $file       = $input['file'] ?? '';
        $staged     = $input['staged'] ?? false;
        $conflicted = $input['conflicted'] ?? false;

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']); break;
        }

        // shell_exec() obligatoire : exec() perd les lignes vides sur Windows,
        // ce qui fausse les numéros de lignes dans le diff unifié.
        $arg = escapeArg($file);
        if ($conflicted) {
            // Fichier en conflit : stage 0 absent, git diff retournerait vide.
            // git diff HEAD montre HEAD vs working tree (marqueurs de conflit inclus).
            $cmd = "git -C " . escapeshellarg($safeRepoPath) . " diff HEAD -- {$arg}";
        } else {
            $flag = $staged ? '--cached ' : '';
            $cmd  = "git -C " . escapeshellarg($safeRepoPath) . " diff {$flag}-- {$arg}";
        }
        $output = shell_exec($cmd);

        echo json_encode(['success' => true, 'data' => ['diff' => $output ?? '']]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
