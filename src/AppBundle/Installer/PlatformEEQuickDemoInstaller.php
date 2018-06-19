<?php
/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
namespace AppBundle\Installer;

use EzSystems\PlatformInstallerBundle\Installer\CleanInstaller;
use Symfony\Component\Filesystem\Filesystem;

class PlatformEEQuickDemoInstaller extends CleanInstaller
{
    use InstallerCommandExecuteTrait;

    public function importSchema()
    {
    }

    public function importData()
    {
        $this->runQueriesFromFile(
            'zip://' . __DIR__ . '/../Resources/sql/demo_data.zip#demo_data.sql'
        );

        $migrationCommands = [
            'cache:clear --no-warmup',
            'kaliop:migration:migrate --path=src/AppBundle/MigrationVersions/demo_dev.yml -n',
        ];

        foreach ($migrationCommands as $cmd) {
            $this->output->writeln(sprintf('executing migration: %s', $cmd));
            $this->executeCommand($this->output, $cmd, 0);
        }
    }

    public function importBinaries()
    {
        $this->output->writeln('Copying storage directory contents...');
        $fs = new Filesystem();
        $fs->mkdir('web/var/site');
    }
}
