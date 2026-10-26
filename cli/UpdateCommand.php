<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\PrivateUpdater\DependencyManager;
use Symfony\Component\Console\Input\InputArgument;

require_once __DIR__ . '/../classes/DependencyManager.php';

/**
 * `bin/plugin private-updater update <name> [<tag>]`
 *
 * Checks out another release tag of one package and pins it in
 * user/.dependencies. Without a tag, takes the latest release.
 */
class UpdateCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('update')
            ->setDescription('Check out another release tag of a package and pin it.')
            ->addArgument('name', InputArgument::REQUIRED, 'Package name, as in user/.dependencies')
            ->addArgument('tag', InputArgument::OPTIONAL, 'Release tag (default: the latest)');
    }

    protected function serve()
    {
        $grav = Grav::instance();
        $manager = new DependencyManager(GRAV_ROOT, (string) $grav['config']->get('plugins.private-updater.ssh_key', ''));
        $name = (string) $this->input->getArgument('name');
        $tag = (string) $this->input->getArgument('tag');

        try {
            if ($tag === '') {
                $packages = $manager->packages();
                if (!isset($packages[$name])) {
                    throw new \RuntimeException("Unknown package '$name'.");
                }
                $tag = $manager->remoteTags($packages[$name]['url'])[0] ?? '';
                if ($tag === '') {
                    throw new \RuntimeException("'$name' has no release tag on its remote.");
                }
            }
            $row = $manager->update($name, $tag);
        } catch (\RuntimeException $e) {
            $this->output->writeln('<red>' . $e->getMessage() . '</red>');

            return 1;
        }

        $this->output->writeln("<green>$name is now at {$row['installed']}</green> (pinned in user/.dependencies)");

        return 0;
    }
}
