<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Commits, historique, staging, diff :
// log, fileLog, stageFiles, unstageFiles, commit, commitAndPush,
// revertCommit, amendLastCommit, getCommitMessage, showCommit, commitFileDiff, diff

// Chemin d'un renommage tel qu'affiché par --numstat -> [ancien, nouveau] :
// "a.txt => b.txt" ou "dir/{ancien => nouveau}/f.js" ; un chemin simple renvoie [chemin, chemin]
function splitRenamePath(string $path): array {
    if (preg_match('/^(.*)\{(.*) => (.*)\}(.*)$/', $path, $m)) {
        $clean = fn(string $p) => preg_replace('#/{2,}#', '/', ltrim($p, '/'));
        return [$clean($m[1] . $m[2] . $m[4]), $clean($m[1] . $m[3] . $m[4])];
    }
    if (str_contains($path, ' => ')) {
        return explode(' => ', $path, 2);
    }
    return [$path, $path];
}

switch ($action) {

    case 'log':
        $logResult = execGit('git log --oneline -20 --format="%h|%s|%an|%ad" --date=short');
        $commits = [];

        foreach (explode("\n", $logResult['output']) as $line) {
            if (empty(trim($line))) continue;
            $parts = explode('|', $line, 4);
            if (count($parts) >= 4) {
                $commits[] = [
                    'hash'    => $parts[0],
                    'message' => $parts[1],
                    'author'  => $parts[2],
                    'date'    => $parts[3]
                ];
            }
        }

        echo json_encode(['success' => true, 'data' => $commits]);
        break;

    case 'fileLog':
        $file = $input['file'] ?? '';

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']); break;
        }

        $fileEscaped = escapeArg($file);
        $logResult = execGit("git log --oneline -10 --format=\"%h|%s|%ad\" --date=short -- {$fileEscaped}");
        $commits = [];

        foreach (explode("\n", $logResult['output']) as $line) {
            if (empty(trim($line))) continue;
            $parts = explode('|', $line, 3);
            if (count($parts) >= 3) {
                $commits[] = ['hash' => $parts[0], 'message' => $parts[1], 'date' => $parts[2]];
            }
        }

        echo json_encode(['success' => true, 'data' => $commits]);
        break;

    case 'stageFiles':
        $files = $input['files'] ?? [];

        if (empty($files)) {
            echo json_encode(['success' => false, 'error' => 'Aucun fichier sélectionné']); break;
        }

        validateAndAddFiles($files);

        echo json_encode([
            'success' => true,
            'output' => count($files) . " fichier(s) stagé(s) pour le prochain commit"
        ]);
        break;

    case 'unstageFiles':
        $files = $input['files'] ?? [];

        if (empty($files)) {
            echo json_encode(['success' => false, 'error' => 'Aucun fichier sélectionné']); break;
        }

        validateFiles($files);

        $filesEscaped = implode(' ', array_map('escapeArg', $files));
        $resetResult = execGit('git reset HEAD -- ' . $filesEscaped);

        if ($resetResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Erreur git reset: ' . $resetResult['output']]); break;
        }

        echo json_encode(['success' => true, 'output' => count($files) . " fichier(s) retiré(s) du staging"]);
        break;

    case 'commit':
        $files    = $input['files'] ?? [];
        $message  = $input['message'] ?? '';
        $hasStaged = $input['hasStaged'] ?? false;

        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Message de commit requis']); break;
        }
        if (empty($files) && !$hasStaged) {
            echo json_encode(['success' => false, 'error' => 'Fichiers requis']); break;
        }

        validateAndAddFiles($files);

        $tmpFile = tempnam(sys_get_temp_dir(), 'git-commit-msg-');
        file_put_contents($tmpFile, $message);
        $commitResult = execGit('git commit -F ' . escapeArg($tmpFile));
        unlink($tmpFile);

        if ($commitResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Erreur git commit: ' . $commitResult['output']]); break;
        }

        echo json_encode(['success' => true, 'output' => $commitResult['output']]);
        break;

    case 'commitAndPush':
        $files    = $input['files'] ?? [];
        $message  = $input['message'] ?? '';
        $hasStaged = $input['hasStaged'] ?? false;

        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Message de commit requis']); break;
        }
        if (empty($files) && !$hasStaged) {
            echo json_encode(['success' => false, 'error' => 'Fichiers requis']); break;
        }

        validateAndAddFiles($files);

        $tmpFile = tempnam(sys_get_temp_dir(), 'git-commit-msg-');
        file_put_contents($tmpFile, $message);
        $commitResult = execGit('git commit -F ' . escapeArg($tmpFile));
        unlink($tmpFile);

        if ($commitResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Erreur git commit: ' . $commitResult['output']]); break;
        }

        $currentBranch = trim(execGit('git branch --show-current')['output']);
        $upstream = trim(execGit("git config --get branch.{$currentBranch}.remote")['output']);
        $pushResult = empty($upstream)
            ? execGit("git push -u origin {$currentBranch}")
            : execGit('git push origin');

        if ($pushResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Commit OK, mais erreur push: ' . $pushResult['output']]); break;
        }

        echo json_encode([
            'success' => true,
            'output' => "Commit:\n" . $commitResult['output'] . "\n\nPush:\n" . $pushResult['output']
        ]);
        break;

    case 'revertCommit':
        $hash = $input['hash'] ?? '';

        if (empty($hash) || !preg_match('/^[a-f0-9]{4,40}$/i', $hash)) {
            echo json_encode(['success' => false, 'error' => 'Hash invalide']); break;
        }

        $result = execGit('git revert ' . escapeArg($hash) . ' --no-edit');

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]); break;
        }

        echo json_encode(['success' => true, 'output' => $result['output']]);
        break;

    case 'amendLastCommit':
        $files     = $input['files'] ?? [];
        $hasStaged = $input['hasStaged'] ?? false;
        $message   = trim($input['message'] ?? '');

        $alreadyStaged = trim(execGit('git diff --cached --name-only')['output']) !== '';

        if (empty($files) && !$hasStaged && $message === '' && !$alreadyStaged) {
            echo json_encode(['success' => false, 'error' => 'Fichiers ou message requis']); break;
        }

        validateAndAddFiles($files);

        if ($message !== '') {
            $tmpFile = tempnam(sys_get_temp_dir(), 'git-commit-msg-');
            file_put_contents($tmpFile, $message);
            $amendResult = execGit('git commit --amend -F ' . escapeArg($tmpFile));
            unlink($tmpFile);
        } else {
            $amendResult = execGit('git commit --amend --no-edit');
        }

        if ($amendResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Erreur git commit --amend: ' . $amendResult['output']]); break;
        }

        echo json_encode(['success' => true, 'output' => $amendResult['output']]);
        break;

    case 'getCommitMessage':
        $hash = $input['hash'] ?? 'HEAD';

        if (!preg_match('/^[a-f0-9]{7,40}$/', $hash) && $hash !== 'HEAD') {
            echo json_encode(['success' => false, 'error' => 'Hash de commit invalide']); break;
        }

        // shell_exec() retourne la sortie complète sans découpage ligne par ligne (préserve le corps sur Windows)
        $raw = shell_exec("git -C \"{$safeRepoPath}\" log -1 --format=%B " . escapeArg($hash));
        $message = $raw !== null ? trim(str_replace("\r\n", "\n", $raw)) : '';
        echo json_encode(['success' => true, 'message' => $message]);
        break;

    case 'showCommit':
        $hash = $input['hash'] ?? 'HEAD';

        if (!preg_match('/^[a-f0-9]{7,40}$/', $hash) && $hash !== 'HEAD') {
            echo json_encode(['success' => false, 'error' => 'Hash de commit invalide']); break;
        }

        // Métadonnées séparées par \x1f ; shell_exec garde le corps du message intact (voir getCommitMessage)
        $raw   = shell_exec("git -C \"{$safeRepoPath}\" show -s --format=%H%x1f%an%x1f%ae%x1f%aI%x1f%P%x1f%B " . escapeArg($hash) . ' 2>&1');
        $parts = explode("\x1f", str_replace("\r\n", "\n", (string) $raw), 6);
        if (count($parts) < 6) {
            echo json_encode(['success' => false, 'error' => trim((string) $raw) ?: 'Commit introuvable']); break;
        }
        [$fullHash, $author, $email, $date, $parents, $body] = $parts;
        $isMerge = count(array_filter(explode(' ', trim($parents)))) > 1;

        // Fichiers modifiés (+/-) ; pour un merge, comparaison avec le premier parent
        $numstat = execGit('git show --numstat --format= -M --diff-merges=first-parent ' . escapeArg($hash));
        $files = [];
        $totalAdded = 0;
        $totalDeleted = 0;
        foreach (explode("\n", $numstat['output']) as $line) {
            $cols = explode("\t", rtrim($line, "\r"), 3);
            if (count($cols) !== 3) continue; // lignes d'avertissement éventuelles
            $binary = ($cols[0] === '-');
            [$oldPath, $newPath] = splitRenamePath($cols[2]);
            $files[] = [
                'path'    => $cols[2],   // affichage ("ancien => nouveau" pour un renommage)
                'newPath' => $newPath,
                'oldPath' => $oldPath,
                'added'   => $binary ? null : (int) $cols[0],
                'deleted' => $binary ? null : (int) $cols[1],
            ];
            $totalAdded   += $binary ? 0 : (int) $cols[0];
            $totalDeleted += $binary ? 0 : (int) $cols[1];
        }

        echo json_encode(['success' => true, 'data' => [
            'hash'    => trim($fullHash),
            'author'  => $author,
            'email'   => $email,
            'date'    => $date,
            'isMerge' => $isMerge,
            'message' => trim($body),
            'files'   => $files,
            'added'   => $totalAdded,
            'deleted' => $totalDeleted,
        ]]);
        break;

    case 'commitFileDiff':
        // Diff d'un fichier tel que modifié par un commit donné (fenêtre de la loupe)
        $hash    = $input['hash'] ?? '';
        $file    = $input['file'] ?? '';
        $oldFile = $input['oldFile'] ?? '';

        if (!preg_match('/^[a-f0-9]{7,40}$/', $hash)) {
            echo json_encode(['success' => false, 'error' => 'Hash de commit invalide']); break;
        }
        if (empty($file) || !isValidFile($file) || ($oldFile !== '' && !isValidFile($oldFile))) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']); break;
        }

        // Renommage : les deux chemins sont nécessaires pour que git montre la modification, pas un ajout
        $paths = escapeArg($file) . ($oldFile !== '' && $oldFile !== $file ? ' ' . escapeArg($oldFile) : '');
        $result = execGit('git show --format= -M --diff-merges=first-parent ' . escapeArg($hash) . ' -- ' . $paths);

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]); break;
        }
        echo json_encode(['success' => true, 'data' => ['diff' => $result['output']]]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
