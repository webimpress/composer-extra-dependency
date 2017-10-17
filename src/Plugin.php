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

    public static function getSubscribedEvents()
    {
        return [
            'post-package-install' => 'onPostPackage',
            'post-package-update'  => 'onPostPackage',
        ];
    }

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;

        $installedPackages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        foreach ($installedPackages as $package) {
            $this->installedPackages[$package->getName()] = $package->getPrettyVersion();
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
        $extra = $this->getExtraMetadata($package->getExtra());
        if (empty($extra)) {
            // Package does not define anything of interest; do nothing.
            return;
        }

        $packages = array_flip($extra);

        foreach ($packages as $package => &$constraint) {
            if ($this->hasPackage($package)) {
                unset($packages[$package]);
                continue;
            }

            $constraint = $this->promptForPackageVersion($package);
        }

        if ($packages) {
            $this->updateComposerJson($packages);

            $rootPackage = $this->updateRootPackage($this->composer->getPackage(), $packages);
            $this->runInstaller($rootPackage, array_keys($packages));
        }
    }

    private function getExtraMetadata(array $extra)
    {
        return isset($extra['dependency']) && is_array($extra['dependency'])
            ? $extra['dependency']
            : [];
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

    private function promptForPackageVersion($name)
    {
        // Package is currently installed. Add it to root composer.json
        if (isset($this->installedPackages[$name])) {
            $this->io->write(sprintf(
                'Added package <info>%s</info> to composer.json with constraint <info>%s</info>;'
                    . ' to upgrade, run <info>composer require %s:VERSION</info>',
                $name,
                '^' . $this->installedPackages[$name],
                $name
            ));

            return '^' . $this->installedPackages[$name];
        }

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
