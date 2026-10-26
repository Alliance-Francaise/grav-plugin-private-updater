<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PrivateUpdater\DependencyManager;

require_once __DIR__ . '/../classes/DependencyManager.php';

/**
 * `bin/plugin private-updater sync`
 *
 * The deploy step after `git pull` of the site repo: clones the packages that
 * are missing and checks out the pinned tag where another one is installed.
 * Packages with local modifications are skipped and reported.
 */
class SyncCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('sync')
            ->setDescription('Install missing packages and check out the tags pinned in user/.dependencies.');
    }

    protected function serve()
    {
        $grav = Grav::instance();
        $manager = new DependencyManager(GRAV_ROOT, (string) $grav['config']->get('plugins.private-updater.ssh_key', ''));

        $skipped = false;
        foreach ($manager->sync() as $line) {
            $this->output->writeln($line);
            $skipped = $skipped || strpos($line, 'SKIPPED') !== false;
        }

        return $skipped ? 1 : 0;
    }
}
