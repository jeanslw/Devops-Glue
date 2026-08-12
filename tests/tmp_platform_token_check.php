<?php
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../config');
$dotenv->load();

function show($name, $value) {
    echo sprintf("%-15s: %s\n", $name, $value === null ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : $value));
}

show('GITEE_TOKEN', $_ENV['GITEE_TOKEN'] ?? getenv('GITEE_TOKEN'));
show('GITLAB_TOKEN', $_ENV['GITLAB_TOKEN'] ?? getenv('GITLAB_TOKEN'));
show('GITHUB_TOKEN', $_ENV['GITHUB_TOKEN'] ?? getenv('GITHUB_TOKEN'));
show('GITEA_TOKEN', $_ENV['GITEA_TOKEN'] ?? getenv('GITEA_TOKEN'));
show('JENKINS_TOKEN', $_ENV['JENKINS_TOKEN'] ?? getenv('JENKINS_TOKEN'));
show('HARBOR_PASSWORD', $_ENV['HARBOR_PASSWORD'] ? 'set' : 'unset');
show('HARBOR_BASE_URL', $_ENV['HARBOR_BASE_URL'] ?? getenv('HARBOR_BASE_URL'));
show('GITLAB_BASE_URL', $_ENV['GITLAB_BASE_URL'] ?? getenv('GITLAB_BASE_URL'));
show('GITHUB_BASE_URL', $_ENV['GITHUB_BASE_URL'] ?? getenv('GITHUB_BASE_URL'));
show('GITEE_BASE_URL', $_ENV['GITEE_BASE_URL'] ?? getenv('GITEE_BASE_URL'));
show('GITEA_BASE_URL', $_ENV['GITEA_BASE_URL'] ?? getenv('GITEA_BASE_URL'));
show('JENKINS_BASE_URL', $_ENV['JENKINS_BASE_URL'] ?? getenv('JENKINS_BASE_URL'));

$config = require __DIR__ . '/../config/settings.php';
foreach (['gitlab', 'github', 'gitee', 'gitea'] as $platform) {
    $cfg = $config['git'][$platform] ?? [];
    show(strtoupper($platform) . '_cfg', json_encode($cfg, JSON_UNESCAPED_SLASHES));
}
show('harbor_cfg', json_encode($config['harbor'] ?? [], JSON_UNESCAPED_SLASHES));

// Simple path encoding check for GitLab
$repo = 'namespace/project/name';
show('gitlab_path', urlencode($repo));
