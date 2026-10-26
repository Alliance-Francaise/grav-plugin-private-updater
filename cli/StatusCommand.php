<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PrivateUpdater\DependencyManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;

require_once __DIR__ . '/../classes/DependencyManager.php';

/**
 * `bin/plugin private-updater status [--offline]`
 *
 * One line per package of user/.dependencies: pinned, installed and latest
 * release tag. Exits 1 when a package needs attention (missing, drift, dirty).
 */
class StatusCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('status')
            ->setDescription('Show pinned, installed and latest versions of the packages in user/.dependencies.')
            ->addOption('offline', 'o', InputOption::VALUE_NONE, 'Do not ask the remotes for their tags.');
    }

    protected function serve()
    {
        $grav = Grav::instance();
        $manager = new DependencyManager(GRAV_ROOT, (string) $grav['config']->get('plugins.private-updater.ssh_key', ''));
        $rows = $manager->status(!$this->input->getOption('offline'));

        $table = new Table($this->output);
        $table->setHeaders(['Package', 'Pinned', 'Installed', 'Latest', 'State', 'Note']);
        $problem = false;
        foreach ($rows as $row) {
            $note = $row['error'] ?? ($row['untracked'] ? $row['untracked'] . ' untracked file(s)' : '');
            $table->addRow([$row['name'], $row['pinned'], $row['installed'] ?? '-', $row['latest'] ?? '-', $row['state'], $note]);
            $problem = $problem || in_array($row['state'], ['missing', 'drift', 'dirty', 'unknown'], true);
        }
        $table->render();

        return $problem ? 1 : 0;
    }
}
