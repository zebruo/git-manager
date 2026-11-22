<?php
// Fork : branches du dépôt original (remote "upstream") et choix de la branche cible.
// Partagé par status.php (retard sur l'original) et sync.php (mise à jour depuis l'original).

/**
 * Noms des branches de l'original connues localement (d'après le dernier fetch), sans le préfixe "upstream/".
 * Exemple : ['main', 'optim', 'test-postpro'].
 */
function getUpstreamBranches(): array {
    // Format entre guillemets : cmd.exe (Windows) interprète les « % » non protégés
    $result = execGit('git for-each-ref "--format=%(refname)" refs/remotes/upstream/');
    if ($result['code'] !== 0) return [];
    $prefix = 'refs/remotes/upstream/';
    $branches = [];
    foreach (preg_split('/\R/', trim($result['output'])) as $ref) {
        $ref = trim($ref);
        if ($ref === '' || strpos($ref, $prefix) !== 0) continue;
        $name = substr($ref, strlen($prefix));
        if ($name === 'HEAD') continue; // pointeur symbolique, pas une vraie branche
        $branches[] = $name;
    }
    return $branches;
}

/**
 * Branche de l'original proposée par défaut pour la branche courante :
 *   1. la branche du même nom, si elle existe chez l'original ;
 *   2. sinon la branche par défaut de l'original (upstream/HEAD), ou "main" / "master" ;
 *   3. sinon la première branche connue.
 * Retourne '' si l'original n'a aucune branche connue.
 */
function getUpstreamDefaultTarget(string $currentBranch, array $branches): string {
    if (!$branches) return '';
    if ($currentBranch !== '' && in_array($currentBranch, $branches, true)) return $currentBranch;

    $head = execGit('git symbolic-ref --short refs/remotes/upstream/HEAD');
    if ($head['code'] === 0) {
        $name = preg_replace('#^upstream/#', '', trim($head['output']));
        if (in_array($name, $branches, true)) return $name;
    }
    foreach (['main', 'master'] as $candidate) {
        if (in_array($candidate, $branches, true)) return $candidate;
    }
    return $branches[0];
}

/** Nombre de commits de upstream/<cible> absents de la branche courante, null si la référence n'existe pas. */
function countUpstreamBehind(string $target): ?int {
    $ref = escapeArg('refs/remotes/upstream/' . $target);
    if (execGit("git rev-parse --verify --quiet {$ref}")['code'] !== 0) return null;
    return (int) trim(execGit("git rev-list --count HEAD..{$ref}")['output']);
}
