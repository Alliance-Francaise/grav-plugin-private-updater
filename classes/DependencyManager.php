<?php

namespace Grav\Plugin\PrivateUpdater;

use Grav\Common\Cache;
use Grav\Common\Yaml;
use RuntimeException;

/**
 * Installs and updates the git packages listed in `user/.dependencies`.
 *
 * `.dependencies` is Grav's own format (the one `bin/grav install` reads). Each
 * entry under `git:` is a package pinned to a release tag:
 *
 *   git:
 *     my-theme:
 *       url: git@github.com:me/my-theme.git
 *       path: user/themes/my-theme
 *       branch: v1.2.0
 *
 * Grav clones these once and never updates them; GPM only knows getgrav.org.
 * This class fills the gap: it reports what is installed versus pinned versus
 * released, checks out another tag in place, and writes the new pin back.
 *
 * Safety rules, in order of importance:
 *   - Only URLs and paths from `.dependencies` are ever run. The only outside
 *     input is a tag, and it must match a semver pattern AND be one the remote
 *     actually lists.
 *   - A package with modified tracked files is never touched (someone edited
 *     code on the server: that has to be looked at, not overwritten).
 *   - A symlinked package (a developer's working clone) is never touched.
 *   - One update at a time (file lock).
 */
class DependencyManager
{
    public const TAG_PATTERN = '/^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/';
    private const PATH_PATTERN = '#^user/(plugins|themes)/[A-Za-z0-9_-]+$#';

    /** @var string */
    private $root;
    /** @var string */
    private $file;
    /** @var string */
    private $sshKey;
    /** @var string */
    private $logFile;

    public function __construct(string $root, string $sshKey = '')
    {
        $this->root = rtrim($root, '/');
        $this->file = $this->root . '/user/.dependencies';
        $this->sshKey = $sshKey;
        $this->logFile = $this->root . '/logs/private-updater.log';
    }

    /**
     * The `git:` entries of `.dependencies`, validated.
     *
     * @return array<string, array{url: string, path: string, branch: string}>
     */
    public function packages(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $data = Yaml::parse((string) file_get_contents($this->file));
        $packages = [];
        foreach ((array) ($data['git'] ?? []) as $name => $entry) {
            $url = (string) ($entry['url'] ?? '');
            $path = trim((string) ($entry['path'] ?? ''), '/');
            $branch = (string) ($entry['branch'] ?? '');
            if ($url === '' || !preg_match(self::PATH_PATTERN, $path) || $branch === '') {
                throw new RuntimeException("Invalid entry '$name' in user/.dependencies (needs url, path under user/plugins|themes, branch).");
            }
            $packages[(string) $name] = ['url' => $url, 'path' => $path, 'branch' => $branch];
        }

        return $packages;
    }

    /**
     * State of every package. With $remote, also asks each remote for its tags
     * (one `git ls-remote` per package, so it needs network and credentials).
     *
     * state: missing | linked | dirty | drift (installed ≠ pinned) | outdated | ok | unknown
     */
    public function status(bool $remote = true): array
    {
        $list = [];
        foreach ($this->packages() as $name => $pkg) {
            $list[] = $this->packageStatus($name, $pkg, $remote);
        }

        return $list;
    }

    public function packageStatus(string $name, array $pkg, bool $remote = true): array
    {
        $dir = $this->root . '/' . $pkg['path'];
        $row = [
            'name' => $name,
            'path' => $pkg['path'],
            'url' => $pkg['url'],
            'pinned' => $pkg['branch'],
            'installed' => null,
            'dirty' => false,
            'untracked' => 0,
            'tags' => [],
            'latest' => null,
            'error' => null,
            'state' => 'unknown',
        ];

        if (is_link($dir)) {
            $row['state'] = 'linked';
        } elseif (!is_dir($dir . '/.git')) {
            $row['state'] = is_dir($dir) ? 'unknown' : 'missing';
            if (is_dir($dir)) {
                $row['error'] = 'Folder exists but is not a git clone.';
            }
        } else {
            $row['installed'] = $this->installedTag($dir);
            [$tracked, $untracked] = $this->changes($dir);
            $row['dirty'] = $tracked > 0;
            $row['untracked'] = $untracked;
        }

        if ($remote) {
            try {
                $row['tags'] = $this->remoteTags($pkg['url']);
                $row['latest'] = $row['tags'][0] ?? null;
            } catch (RuntimeException $e) {
                $row['error'] = $e->getMessage();
            }
        }

        $isClone = !is_link($dir) && is_dir($dir . '/.git');
        if ($isClone) {
            if ($row['dirty']) {
                $row['state'] = 'dirty';
            } elseif ($row['installed'] !== $row['pinned']) {
                $row['state'] = 'drift';
            } elseif ($row['latest'] && version_compare(ltrim($row['latest'], 'v'), ltrim((string) $row['installed'], 'v'), '>')) {
                $row['state'] = 'outdated';
            } else {
                $row['state'] = 'ok';
            }
        }

        return $row;
    }

    /**
     * Release tags of a remote, newest first.
     *
     * @return string[]
     */
    public function remoteTags(string $url): array
    {
        $out = $this->git(['ls-remote', '--tags', '--refs', $url]);
        $tags = [];
        foreach (preg_split('/\R/', trim($out)) as $line) {
            if (preg_match('#\trefs/tags/(.+)$#', $line, $m) && preg_match(self::TAG_PATTERN, $m[1])) {
                $tags[] = $m[1];
            }
        }
        usort($tags, static function ($a, $b) {
            return version_compare(ltrim($b, 'v'), ltrim($a, 'v'));
        });

        return $tags;
    }

    /**
     * Bring the server in line with `.dependencies`: clone what is missing and
     * check out the pinned tag where another one is installed. Returns one line
     * per package for the caller to print.
     *
     * @return string[]
     */
    public function sync(): array
    {
        $report = [];
        foreach ($this->packages() as $name => $pkg) {
            $row = $this->packageStatus($name, $pkg, false);
            switch ($row['state']) {
                case 'missing':
                    $this->withLock(function () use ($pkg) {
                        $this->cloneAt($pkg, $pkg['branch']);
                    });
                    $this->log("installed $name {$pkg['branch']}");
                    $report[] = "$name: installed {$pkg['branch']}";
                    break;
                case 'drift':
                    $this->withLock(function () use ($pkg) {
                        $this->checkout($pkg, $pkg['branch']);
                    });
                    $from = $row['installed'] ?? 'untagged';
                    $this->log("synced $name $from -> {$pkg['branch']}");
                    $report[] = "$name: $from -> {$pkg['branch']}";
                    break;
                case 'dirty':
                    $report[] = "$name: SKIPPED, files were modified on this server (git -C {$pkg['path']} status)";
                    break;
                case 'linked':
                    $report[] = "$name: symlink (development copy), left alone";
                    break;
                case 'unknown':
                    $report[] = "$name: SKIPPED, " . ($row['error'] ?? 'unknown state');
                    break;
                default:
                    $report[] = "$name: already at {$pkg['branch']}";
            }
        }
        if ($report) {
            Cache::clearCache('standard');
        }

        return $report;
    }

    /**
     * Move one package to $tag and pin it in `.dependencies`.
     *
     * @return array the package status after the update
     */
    public function update(string $name, string $tag, string $who = 'cli'): array
    {
        $packages = $this->packages();
        if (!isset($packages[$name])) {
            throw new RuntimeException("Unknown package '$name'.");
        }
        if (!preg_match(self::TAG_PATTERN, $tag)) {
            throw new RuntimeException("'$tag' is not a release tag (expected something like v1.2.3).");
        }
        $pkg = $packages[$name];
        if (!in_array($tag, $this->remoteTags($pkg['url']), true)) {
            throw new RuntimeException("Tag '$tag' does not exist on the remote of '$name'.");
        }

        $before = $this->packageStatus($name, $pkg, false);
        if ($before['state'] === 'linked') {
            throw new RuntimeException("'$name' is a symlink (development copy). Update it with git in the clone.");
        }
        if ($before['state'] === 'dirty') {
            throw new RuntimeException("'$name' has files modified on this server. Check `git -C {$pkg['path']} status` first.");
        }
        if ($before['state'] === 'unknown') {
            throw new RuntimeException("'$name': " . ($before['error'] ?? 'unknown state'));
        }

        $this->withLock(function () use ($pkg, $before, $tag, $name) {
            if ($before['state'] === 'missing') {
                $this->cloneAt($pkg, $tag);
            } else {
                $this->checkout($pkg, $tag);
            }
            $this->writePin($name, $tag);
        });
        Cache::clearCache('standard');
        $this->log(sprintf('%s updated %s %s -> %s', $who, $name, $before['installed'] ?? '(none)', $tag));

        $packages[$name]['branch'] = $tag;

        return $this->packageStatus($name, $packages[$name], false);
    }

    private function cloneAt(array $pkg, string $tag): void
    {
        $this->git(['clone', '-b', $tag, '--depth', '1', '--', $pkg['url'], $this->root . '/' . $pkg['path']]);
    }

    private function checkout(array $pkg, string $tag): void
    {
        $dir = $this->root . '/' . $pkg['path'];
        $fetch = ['-C', $dir, 'fetch', '--no-tags'];
        if (trim($this->git(['-C', $dir, 'rev-parse', '--is-shallow-repository'])) === 'true') {
            $fetch[] = '--depth=1';
        }
        $fetch[] = $pkg['url'];
        $fetch[] = "refs/tags/$tag:refs/tags/$tag";
        $this->git($fetch);
        $this->git(['-C', $dir, 'checkout', '--quiet', "refs/tags/$tag"]);
    }

    private function installedTag(string $dir): ?string
    {
        try {
            $out = trim($this->git(['-C', $dir, 'tag', '--points-at', 'HEAD']));
        } catch (RuntimeException $e) {
            return null;
        }
        $tags = array_values(array_filter(preg_split('/\R/', $out), static function ($t) {
            return (bool) preg_match(self::TAG_PATTERN, $t);
        }));
        usort($tags, static function ($a, $b) {
            return version_compare(ltrim($b, 'v'), ltrim($a, 'v'));
        });

        return $tags[0] ?? null;
    }

    /** @return array{0: int, 1: int} [modified tracked files, untracked files] */
    private function changes(string $dir): array
    {
        $tracked = $untracked = 0;
        foreach (preg_split('/\R/', trim($this->git(['-C', $dir, 'status', '--porcelain']))) as $line) {
            if ($line === '') {
                continue;
            }
            if (strpos($line, '??') === 0) {
                $untracked++;
            } else {
                $tracked++;
            }
        }

        return [$tracked, $untracked];
    }

    /**
     * Rewrite only the `branch:` line of one entry, so the comments and layout
     * of `.dependencies` survive.
     */
    private function writePin(string $name, string $tag): void
    {
        $lines = file($this->file);
        $section = null;
        $inEntry = false;
        $done = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^(\S[^:]*):\s*$/', $line, $m)) {
                $section = $m[1];
                $inEntry = false;
                continue;
            }
            if ($section !== 'git') {
                continue;
            }
            if (preg_match('/^  (\S[^:]*):\s*$/', $line, $m)) {
                $inEntry = trim($m[1], '\'"') === $name;
                continue;
            }
            if ($inEntry && preg_match('/^(\s{4,}branch:\s*).*$/', $line, $m)) {
                $lines[$i] = $m[1] . $tag . "\n";
                $done = true;
                break;
            }
        }
        if (!$done) {
            throw new RuntimeException("Checked out $tag but could not find the 'branch:' line of '$name' in user/.dependencies. Pin it by hand.");
        }
        file_put_contents($this->file, implode('', $lines), LOCK_EX);
    }

    private function withLock(callable $fn): void
    {
        $dir = $this->root . '/user/data/private-updater';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create $dir.");
        }
        $handle = fopen($dir . '/update.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another update is running. Try again in a minute.');
        }
        try {
            $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function log(string $message): void
    {
        @file_put_contents($this->logFile, date('c') . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Run git without a shell. Web PHP often has no HOME (so ssh finds neither
     * ~/.ssh/config nor the keys) and must never wait on a password prompt.
     */
    private function git(array $args): string
    {
        $env = getenv();
        if (empty($env['HOME']) && function_exists('posix_getpwuid')) {
            $env['HOME'] = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
        }
        $env['GIT_TERMINAL_PROMPT'] = '0';
        $ssh = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new';
        if ($this->sshKey !== '') {
            $ssh .= ' -i ' . escapeshellarg($this->sshKey) . ' -o IdentitiesOnly=yes';
        }
        $env['GIT_SSH_COMMAND'] = $ssh;

        $proc = proc_open(array_merge(['git'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('Cannot run git (is proc_open allowed?).');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0) {
            $what = $args[0] === '-C' ? ($args[2] ?? 'git') : $args[0];
            throw new RuntimeException("git $what failed: " . trim($err ?: $out));
        }

        return $out;
    }
}
