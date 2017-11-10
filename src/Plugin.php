<?php

namespace Webimpress\ComposerExtraDependency;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Pool;
use Composer\EventDispatcher\EventDispatcher;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Link;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Plugin\PluginInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Script\Event;
use RuntimeException;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /** @var callable */
    private $composerFileFactory = [Factory::class, 'getComposerFile'];

    /** @var callable */
    private $installerFactory = [self::class, 'createInstaller'];

    /** @var callable */
    private $versionSelectorFactory = [self::class, 'createVersionSelector'];

    /** @var Composer */
    private $composer;

    /** @var string[] */
    private $installedPackages;

    /** @var IOInterface */
    private $io;

    /** @var Pool */
    private $pool;

    /** @var CompositeRepository */
    private $repos;

    /** @var string[] */
    private $packagesToInstall = [];

    public static function getSubscribedEvents()
    {
        return [
            'post-package-install' => 'onPostPackage',
            'post-package-update'  => 'onPostPackage',
            'post-install-cmd' => 'onPostCommand',
            'post-update-cmd'  => 'onPostCommand',
        ];
    }

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;

        $installedPackages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        foreach ($installedPackages as $package) {
            $this->installedPackages[strtolower($package->getName())] = $package->getPrettyVersion();
        }
    }

    public function onPostCommand(Event $event)
    {
        if (! $event->isDevMode()) {
            // Do nothing in production mode.
            return;
        }

        if (! $this->io->isInteractive()) {
            // Do nothing in no-interactive mode
            return;
        }

        if ($this->packagesToInstall) {
            $this->updateComposerJson($this->packagesToInstall);

            $rootPackage = $this->updateRootPackage($this->composer->getPackage(), $this->packagesToInstall);
            $this->runInstaller($rootPackage, array_keys($this->packagesToInstall));
        }
    }

    public function onPostPackage(PackageEvent $event)
    {
        if (! $event->isDevMode()) {
            // Do nothing in production mode.
            return;
        }

        if (! $this->io->isInteractive()) {
            // Do nothing in no-interactive mode
            return;
        }

        $operation = $event->getOperation();
        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
        } else {
            $package = $operation->getTargetPackage();
        }

        $extra = $package->getExtra();

        $this->packagesToInstall += $this->andDependencies($extra);
        $this->packagesToInstall += $this->orDependencies($extra);
    }

    private function andDependencies(array $extra)
    {
        $deps = isset($extra['dependency']) && is_array($extra['dependency'])
            ? $extra['dependency']
            : [];

        if (! $deps) {
            // No defined any packages to install
            return [];
        }

        $packages = array_flip($deps);

        foreach ($packages as $package => &$constraint) {
            if ($this->hasPackage($package)) {
                unset($packages[$package]);
                continue;
            }

            // Check if package is currently installed and use installed version.
            if ($constraint = $this->getInstalledPackageConstraint($package)) {
                continue;
            }

            // Package is not installed, then prompt user for the version.
            $constraint = $this->promptForPackageVersion($package);
        }

        return $packages;
    }

    private function orDependencies(array $extra)
    {
        $deps = isset($extra['dependency-or']) && is_array($extra['dependency-or'])
            ? $extra['dependency-or']
            : [];

        if (! $deps) {
            // No any dependencies to choose defined in the package.
            return [];
        }

        $packages = [];
        foreach ($deps as $question => $options) {
            if (! is_array($options) || count($options) < 2) {
                throw new RuntimeException('You must provide at least two optional dependencies.');
            }

            foreach ($options as $package) {
                if ($this->hasPackage($package)) {
                    // Package from this group has been found in root composer, skipping.
                    continue 2;
                }

                // Check if package is currently installed, if so, use installed constraint and skip question.
                if ($constraint = $this->getInstalledPackageConstraint($package)) {
                    $packages[$package] = $constraint;
                    continue 2;
                }
            }

            $package = $this->promptForPackageSelection($question, $options);
            $packages[$package] = $this->promptForPackageVersion($package);
        }

        return $packages;
    }

    private function updateRootPackage(RootPackageInterface $rootPackage, array $packages)
    {
        $this->io->write('<info>Updating root package</info>');

        $versionParser = new VersionParser();
        $requires = $rootPackage->getRequires();
        foreach ($packages as $name => $version) {
            $requires[$name] = new Link(
                '__root__',
                $name,
                $versionParser->parseConstraints($version),
                'requires',
                $version
            );
        }
        $rootPackage->setRequires($requires);

        return $rootPackage;
    }

    private function runInstaller(RootPackageInterface $rootPackage, array $packages)
    {
        $this->io->write('<info>    Running an update to install dependent packages</info>');

        /** @var Installer $installer */
        $installer = call_user_func(
            $this->installerFactory,
            $this->composer,
            $this->io,
            $rootPackage
        );

        $installer->setRunScripts(false);
        $installer->disablePlugins();
        $installer->setUpdate();
        $installer->setUpdateWhitelist($packages);

        return $installer->run();
    }

    private function getInstalledPackageConstraint($package)
    {
        $lower = strtolower($package);

        // Package is currently installed. Add it to root composer.json
        if (! isset($this->installedPackages[$lower])) {
            return null;
        }

        $constraint = '^' . $this->installedPackages[$lower];
        $this->io->write(sprintf(
            'Added package <info>%s</info> to composer.json with constraint <info>%s</info>;'
            . ' to upgrade, run <info>composer require %s:VERSION</info>',
            $package,
            $constraint,
            $package
        ));

        return $constraint;
    }

    private function promptForPackageSelection($question, array $packages)
    {
        $ask = [sprintf('<question>%s</question>' . "\n", $question)];
        foreach ($packages as $i => $name) {
            $ask[] = sprintf('  [<comment>%d</comment>] %s' . "\n", $i + 1, $name);
        }
        $ask[] = '  Make your selection: ';

        do {
            $package = $this->io->askAndValidate(
                $ask,
                function ($input) use ($packages) {
                    $input = is_numeric($input) ? (int) trim($input) : 0;

                    if (isset($packages[$input - 1])) {
                        return $packages[$input - 1];
                    }

                    return null;
                }
            );
        } while (! $package);

        return $package;
    }

    private function promptForPackageVersion($name)
    {
        $constraint = $this->io->askAndValidate(
            sprintf(
                'Enter the version of <info>%s</info> to require (or leave blank to use the latest version): ',
                $name
            ),
            function ($input) {
                $input = trim($input);
                return $input ?: false;
            }
        );

        if ($constraint === false) {
            $constraint = $this->findBestVersionForPackage($name);
            $this->io->write(sprintf(
                'Using version <info>%s</info> for <info>%s</info>',
                $constraint,
                $name
            ));
        }

        return $constraint;
    }

    private function createInstaller(Composer $composer, IOInterface $io, RootPackageInterface $package)
    {
        $eventDispatcher = new EventDispatcher($composer, $io);

        return new Installer(
            $io,
            $composer->getConfig(),
            $package,
            $composer->getDownloadManager(),
            $composer->getRepositoryManager(),
            $composer->getLocker(),
            $composer->getInstallationManager(),
            $eventDispatcher,
            $composer->getAutoloadGenerator()
        );
    }

    private function hasPackage($package)
    {
        $rootPackage = $this->composer->getPackage();
        $requires = $rootPackage->getRequires() + $rootPackage->getDevRequires();
        foreach ($requires as $name => $link) {
            if (strtolower($name) === strtolower($package)) {
                return true;
            }
        }

        return false;
    }

    private function updateComposerJson(array $packages)
    {
        $this->io->write('<info>    Updating composer.json</info>');

        $json = $this->getComposerJson();
        $manipulator = new JsonManipulator(file_get_contents($json->getPath()));
        foreach ($packages as $name => $version) {
            $manipulator->addLink('require', $name, $version, $this->getSortPackages());
        }
        file_put_contents($json->getPath(), $manipulator->getContents());
    }

    private function getComposerJson()
    {
        $composerFile = call_user_func($this->composerFileFactory);
        return new JsonFile($composerFile);
    }

    private function getSortPackages()
    {
        return $this->composer->getConfig()->get('sort-packages') ?: false;
    }

    private function getMinimumStability()
    {
        return $this->composer->getPackage()->getMinimumStability() ?: 'stable';
    }

    private function getPool()
    {
        if (! $this->pool) {
            $this->pool = new Pool($this->getMinimumStability());
            $this->pool->addRepository($this->getRepos());
        }

        return $this->pool;
    }

    private function getRepos()
    {
        if (! $this->repos) {
            $this->repos = new CompositeRepository(array_merge(
                [new PlatformRepository()],
                RepositoryFactory::defaultRepos($this->io)
            ));
        }

        return $this->repos;
    }

    private function findBestVersionForPackage($name)
    {
        // find the latest version allowed in this pool
        $versionSelector = call_user_func($this->versionSelectorFactory, $this->getPool());
        $package = $versionSelector->findBestCandidate($name, null, null, 'stable');

        if (! $package) {
            throw new \InvalidArgumentException(sprintf(
                'Could not find package %s at any version for your minimum-stability (%s).'
                    . ' Check the package spelling or your minimum-stability',
                $name,
                $this->getMinimumStability()
            ));
        }

        return $versionSelector->findRecommendedRequireVersion($package);
    }

    private function createVersionSelector(Pool $pool)
    {
        return new VersionSelector($pool);
    }
}
