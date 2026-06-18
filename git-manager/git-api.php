<?php
/**
 * Git API - Backend pour la gestion Git
 * Permet d'exécuter des commandes Git depuis l'interface web
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// ── Configuration ─────────────────────────────────────────────────────────────

$repoPath = dirname(__DIR__);

$configFile = __DIR__ . '/git-config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
    $gitUserName  = $config['userName'] ?? '';
    $gitUserEmail = $config['userEmail'] ?? '';
} else {
    $config = [];
    $gitUserName  = '';
    $gitUserEmail = '';
}

$isGitRepoCheck = is_dir($repoPath . '/.git');

chdir($repoPath);

$safeRepoPath = str_replace('\\', '/', $repoPath);
putenv("GIT_DIR={$repoPath}/.git");
putenv("GIT_WORK_TREE={$repoPath}");

exec("git config --global --add safe.directory \"{$safeRepoPath}\" 2>&1");

$sshKeyPath = $config['sshKeyPath'] ?? '';
if (!empty($sshKeyPath) && file_exists($sshKeyPath)) {
    $sshKeyPath = str_replace('\\', '/', $sshKeyPath);
    $homeDir = dirname(dirname($sshKeyPath));
    putenv("HOME={$homeDir}");
    putenv("GIT_SSH_COMMAND=ssh -i \"{$sshKeyPath}\" -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=\"{$homeDir}/.ssh/known_hosts\"");
}

if (!empty($gitUserName))  exec("git -C \"{$safeRepoPath}\" config user.name \"{$gitUserName}\" 2>&1");
if (!empty($gitUserEmail)) exec("git -C \"{$safeRepoPath}\" config user.email \"{$gitUserEmail}\" 2>&1");

// ── Fonctions système (utilisées par setup.php et les actions) ────────────────

function recurseCopy(string $src, string $dst): bool {
    if (!is_dir($src)) return false;
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    $dir = opendir($src);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') continue;
        $srcPath = $src . DIRECTORY_SEPARATOR . $file;
        $dstPath = $dst . DIRECTORY_SEPARATOR . $file;
        is_dir($srcPath) ? recurseCopy($srcPath, $dstPath) : copy($srcPath, $dstPath);
    }
    closedir($dir);
    return true;
}

function recurseDelete(string $dir): bool {
    if (!is_dir($dir)) return false;
    foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir($path) ? recurseDelete($path) : unlink($path);
    }
    return rmdir($dir);
}

function cleanEmptyDirs(string $dir, array $excludeDirs = ['.git', 'git-manager']): void {
    if (!is_dir($dir)) return;
    $systemFiles = ['Thumbs.db', 'desktop.ini', '.DS_Store', '.localized', '.gitkeep'];
    $items = array_diff(scandir($dir), ['.', '..']);

    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (!is_dir($path) || in_array($item, $excludeDirs)) continue;
        cleanEmptyDirs($path, $excludeDirs);
        $subItems = @scandir($path);
        if ($subItems !== false) {
            foreach ($subItems as $subItem) {
                if (in_array($subItem, $systemFiles)) @unlink($path . DIRECTORY_SEPARATOR . $subItem);
            }
        }
        $remaining = @scandir($path);
        if ($remaining !== false && empty(array_diff($remaining, ['.', '..']))) @rmdir($path);
    }

    // Deuxième passe
    foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (!is_dir($path) || in_array($item, $excludeDirs)) continue;
        $remaining = @scandir($path);
        if ($remaining !== false && empty(array_diff($remaining, ['.', '..']))) @rmdir($path);
    }
}

// ── Dispatcher ────────────────────────────────────────────────────────────────

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Actions pré-dépôt (pas besoin d'un dépôt Git existant)
$preRepoActions = ['checkRepo', 'init', 'clone', 'listFolders', 'listRemoteBranches', 'resetRemote'];
if (in_array($action, $preRepoActions)) {
    require __DIR__ . '/api/setup.php';
    exit;
}

// Vérifier qu'on est dans un dépôt Git
if (!$isGitRepoCheck) {
    echo json_encode(['success' => false, 'error' => 'Ce dossier n\'est pas un dépôt Git', 'needsInit' => true]);
    exit;
}

// ── Fonctions utilitaires Git ─────────────────────────────────────────────────

function execGit(string $command): array {
    global $repoPath;
    $safeRepoPath = str_replace('\\', '/', $repoPath);
    $fullCommand  = (strpos($command, 'git ') === 0)
        ? "git -C \"{$safeRepoPath}\" " . substr($command, 4)
        : $command;
    $output = [];
    $returnCode = 0;
    exec($fullCommand . ' 2>&1', $output, $returnCode);
    return ['output' => implode("\n", $output), 'code' => $returnCode];
}

function isValidFile(string $file): bool {
    $file = str_replace(['..', '\\'], ['', '/'], $file);
    return strpos($file, '..') === false;
}

function escapeArg(string $arg): string {
    return escapeshellarg($arg);
}

function filterGitWarnings(array $lines): array {
    return array_filter($lines, function($line) {
        $line = trim($line);
        if (empty($line)) return false;
        if (stripos($line, 'warning:') === 0) return false;
        if (stripos($line, 'error:') === 0) return false;
        if (stripos($line, 'fatal:') === 0) return false;
        if (stripos($line, 'The file will have') !== false) return false;
        if (stripos($line, 'LF will be replaced') !== false) return false;
        if (stripos($line, 'CRLF will be replaced') !== false) return false;
        return true;
    });
}

function backupGitManager(string $repoPath): ?string {
    $gitManagerPath = $repoPath . DIRECTORY_SEPARATOR . 'git-manager';
    if (!is_dir($gitManagerPath)) return null;
    $backupPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git-manager-backup-' . uniqid();
    recurseCopy($gitManagerPath, $backupPath);
    return $backupPath;
}

function restoreGitManager(string $repoPath, ?string $backupPath): void {
    if ($backupPath === null) return;
    $gitManagerPath = $repoPath . DIRECTORY_SEPARATOR . 'git-manager';
    $gitApiFile     = $gitManagerPath . DIRECTORY_SEPARATOR . 'git-api.php';
    if (!is_dir($gitManagerPath) || !file_exists($gitApiFile)) {
        recurseCopy($backupPath, $gitManagerPath);
    }
    if (is_dir($backupPath)) recurseDelete($backupPath);
}

function validateFiles(array $files): void {
    foreach ($files as $file) {
        if (!isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Nom de fichier invalide']);
            exit;
        }
    }
}

function validateAndAddFiles(array $files): void {
    validateFiles($files);
    if (empty($files)) return;
    $filesEscaped = implode(' ', array_map('escapeArg', $files));
    $addResult = execGit('git add ' . $filesEscaped);
    if ($addResult['code'] !== 0) {
        echo json_encode(['success' => false, 'error' => 'Erreur git add: ' . $addResult['output']]);
        exit;
    }
}

function getCurrentBranch(): string {
    $branch = trim(execGit('git branch --show-current')['output']);
    if (empty($branch)) $branch = trim(execGit('git rev-parse --abbrev-ref HEAD')['output']);
    return $branch;
}

function isFileOverwriteError(string $output): bool {
    return strpos($output, 'overwritten') !== false
        || strpos($output, 'would be overwritten') !== false
        || strpos($output, 'écrasé') !== false;
}

function validateStashId(string $stashId): bool {
    return preg_match('/^stash@\{[0-9]+\}$/', $stashId) === 1;
}

// ── Routing ───────────────────────────────────────────────────────────────────

$routes = [
    // Statut & remote
    'status'              => 'status.php',
    'repoInfo'            => 'status.php',
    'addRemote'           => 'status.php',
    'removeRemote'        => 'status.php',
    // Commits & staging
    'log'                 => 'commits.php',
    'fileLog'             => 'commits.php',
    'stageFiles'          => 'commits.php',
    'unstageFiles'        => 'commits.php',
    'commit'              => 'commits.php',
    'commitAndPush'       => 'commits.php',
    'revertCommit'        => 'commits.php',
    'amendLastCommit'     => 'commits.php',
    'getCommitMessage'    => 'commits.php',
    'showCommit'          => 'commits.php',
    'diff'                => 'commits.php',
    // Synchronisation
    'push'                => 'sync.php',
    'pull'                => 'sync.php',
    'fetch'               => 'sync.php',
    // Fichiers
    'checkout'            => 'files.php',
    'discardAll'          => 'files.php',
    'checkoutCommit'      => 'files.php',
    'removeFromRepo'      => 'files.php',
    'untrackFile'         => 'files.php',
    'fileDiff'            => 'files.php',
    // Branches
    'branches'            => 'branches.php',
    'switchBranch'        => 'branches.php',
    'createBranch'        => 'branches.php',
    'renameBranch'        => 'branches.php',
    'deleteBranch'        => 'branches.php',
    'mergeBranch'         => 'branches.php',
    'pushBranch'          => 'branches.php',
    'deleteRemoteBranch'  => 'branches.php',
    // Tags
    'listTags'            => 'tags.php',
    'createTag'           => 'tags.php',
    'deleteTag'           => 'tags.php',
    'pushTag'             => 'tags.php',
    'deleteRemoteTag'     => 'tags.php',
    // Stash
    'stashList'           => 'stash.php',
    'stashSave'           => 'stash.php',
    'stashApply'          => 'stash.php',
    'stashDrop'           => 'stash.php',
    // .gitignore
    'getGitignore'        => 'gitignore.php',
    'addToGitignore'      => 'gitignore.php',
    'removeFromGitignore' => 'gitignore.php',
];

if (isset($routes[$action])) {
    require __DIR__ . '/api/' . $routes[$action];
} else {
    echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
}
