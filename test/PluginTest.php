<?php

namespace WebimpressTest\ComposerExtraDependency;

use Composer\Autoload\AutoloadGenerator;
use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Pool;
use Composer\Downloader\DownloadManager;
use Composer\Installer;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableRepositoryInterface;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Webimpress\ComposerExtraDependency\Plugin;

class PluginTest extends TestCase
{
    /** @var Plugin */
    private $plugin;

    /** @var Composer|ObjectProphecy */
    private $composer;

    /** @var IOInterface|ObjectProphecy */
    private $io;

    /** @var WritableRepositoryInterface */
    private $localRepository;

    protected function setUp()
    {
        parent::setUp();

        $this->localRepository = $this->prophesize(WritableRepositoryInterface::class);
        $this->localRepository->getPackages()->willReturn([]);

        $repositoryManager = $this->prophesize(RepositoryManager::class);
        $repositoryManager->getLocalRepository()->willReturn($this->localRepository->reveal());

        $this->composer = $this->prophesize(Composer::class);
        $this->composer->getRepositoryManager()
            ->willReturn($repositoryManager->reveal())
            ->shouldBeCalled();

        $this->io = $this->prophesize(IOInterface::class);

        $this->plugin = new Plugin();
        $this->plugin->activate($this->composer->reveal(), $this->io->reveal());
    }

    protected function setUpComposerInstaller(array $expectedPackages, $expectedReturn = 0)
    {
        $installer = $this->prophesize(Installer::class);
        $installer->setRunScripts(false)->shouldBeCalled();
        $installer->disablePlugins()->shouldBeCalled();
        $installer->setUpdate()->shouldBeCalled();
        $installer->setUpdateWhitelist($expectedPackages)->shouldBeCalled();
        $installer->run()->willReturn($expectedReturn);

        $r = new ReflectionProperty($this->plugin, 'installerFactory');
        $r->setAccessible(true);
        $r->setValue($this->plugin, function () use ($installer) {
            return $installer->reveal();
        });
    }

    protected function setUpVersionSelector(VersionSelector $versionSelector)
    {
        $r = new ReflectionProperty($this->plugin, 'versionSelectorFactory');
        $r->setAccessible(true);
        $r->setValue($this->plugin, function () use ($versionSelector) {
            return $versionSelector;
        });
    }

    protected function setUpPool()
    {
        $pool = $this->prophesize(Pool::class);

        $r = new ReflectionProperty($this->plugin, 'pool');
        $r->setAccessible(true);
        $r->setValue($this->plugin, $pool->reveal());
    }

    protected function setUpComposerJson($data = null)
    {
        $project = vfsStream::setup('project');
        vfsStream::newFile('composer.json')
            ->at($project)
            ->setContent($this->createComposerJson($data));

        $r = new ReflectionProperty($this->plugin, 'composerFileFactory');
        $r->setAccessible(true);
        $r->setValue($this->plugin, function () {
            return vfsStream::url('project/composer.json');
        });
    }

    protected function createComposerJson($data)
    {
        $data = $data ?: $this->getDefaultComposerData();
        return json_encode($data);
    }

    protected function getDefaultComposerData()
    {
        return [
            'name' => 'test/project',
            'type' => 'project',
            'description' => 'This is a test project',
            'require' => [
                'webimpress/my-package' => '^1.0.0-dev@dev',
            ],
        ];
    }

    public function testActivateSetsComposerAndIoProperties()
    {
        $plugin = new Plugin();
        $plugin->activate($this->composer->reveal(), $this->io->reveal());

        $this->assertAttributeSame($this->composer->reveal(), 'composer', $plugin);
        $this->assertAttributeSame($this->io->reveal(), 'io', $plugin);
    }

    public function testSubscribesToExpectedEvents()
    {
        $subscribers = Plugin::getSubscribedEvents();
        $this->assertArrayHasKey('post-package-install', $subscribers);
        $this->assertArrayHasKey('post-package-update', $subscribers);

        $this->assertEquals('onPostPackage', $subscribers['post-package-install']);
        $this->assertEquals('onPostPackage', $subscribers['post-package-update']);
    }

    public function testDoNothingIfItIsNotInDevMode()
    {
        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(false);

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));
    }

    public function testDoNothingInNoInteractionMode()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency' => [
                'extra-dependency-foo',
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn([]);
        $rootPackage->getDevRequires()->willReturn([]);

        $this->composer->getPackage()->willReturn($rootPackage);

        $this->io->isInteractive()->willReturn(false);

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));
    }

    public function testDoNothingWhenThereIsNoExtraDependencies()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));
    }

    public function testDependencyAlreadyIsInRequiredSection()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency' => [
                'extra-dependency-foo',
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $link = $this->prophesize(Link::class);
        $link->getTarget()->willReturn('extra-dependency-foo');

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getDevRequires()->willReturn(['extra-dependency-foo' => $link->reveal()]);

        $this->composer->getPackage()->willReturn($rootPackage);

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));
    }

    public function testDependencyAlreadyIsInRequiredDevSection()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency' => [
                'extra-dependency-foo',
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $this->io->isInteractive()->willReturn(true)->shouldBeCalled();
        $this->io->askAndValidate(Argument::any())->shouldNotBeCalled();

        $link = $this->prophesize(Link::class);
        $link->getTarget()->willReturn('extra-dependency-foo');

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn([]);
        $rootPackage->getDevRequires()->willReturn(['extra-dependency-foo' => $link->reveal()]);

        $this->composer->getPackage()->willReturn($rootPackage);

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));
    }

    public function testInstallSingleDependencyOnPackageUpdate()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency' => [
                'extra-dependency-foo',
            ],
        ]);

        $operation = $this->prophesize(UpdateOperation::class);
        $operation->getTargetPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $this->io->isInteractive()->willReturn(true)->shouldBeCalled();
        $this->io->askAndValidate(Argument::any())->shouldNotBeCalled();

        $config = $this->prophesize(Config::class);
        $config->get('sort-packages')->willReturn(true);
        $config->get(Argument::any())->willReturn(null);

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn([]);
        $rootPackage->getDevRequires()->willReturn([]);
        $rootPackage->setRequires(Argument::that(function (array $arguments) {
            if (count($arguments) !== 1) {
                return false;
            }

            if (! $this->assertSetRequiresArgument('extra-dependency-foo', '17.0.1-dev', $arguments)) {
                return false;
            }

            return true;
        }))->shouldBeCalled();

        $this->composer->getPackage()->willReturn($rootPackage);
        $this->composer->getConfig()->willReturn($config->reveal());

        $this->io->isInteractive()->willReturn(true);
        $this->io->askAndValidate(
            'Enter the version of <info>extra-dependency-foo</info> to require'
                . ' (or leave blank to use the latest version): ',
            Argument::that(function ($arg) {
                if (! is_callable($arg)) {
                    return false;
                }

                Assert::assertFalse($arg(0));
                Assert::assertFalse($arg('0'));
                Assert::assertFalse($arg(''));
                Assert::assertFalse($arg(' '));
                Assert::assertSame('1', $arg('  1 '));
                Assert::assertSame('1', $arg('1'));
                Assert::assertSame('0.*', $arg('0.*'));

                return true;
            })
        )->willReturn('17.0.1-dev');

        $this->io->write('<info>    Updating composer.json</info>')->shouldBeCalled();
        $this->io->write('<info>Updating root package</info>')->shouldBeCalled();
        $this->io->write('<info>    Running an update to install dependent packages</info>')->shouldBeCalled();

        $this->setUpComposerInstaller(['extra-dependency-foo']);
        $this->setUpComposerJson();

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));
    }

    public function testInstallSingleDependencyOnPackageInstall()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency' => [
                'extra-dependency-foo',
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $config = $this->prophesize(Config::class);
        $config->get('sort-packages')->willReturn(true);
        $config->get(Argument::any())->willReturn(null);

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn([]);
        $rootPackage->getDevRequires()->willReturn([]);
        $rootPackage->setRequires(Argument::that(function (array $arguments) {
            if (count($arguments) !== 1) {
                return false;
            }

            if (! $this->assertSetRequiresArgument('extra-dependency-foo', '17.0.1-dev', $arguments)) {
                return false;
            }

            return true;
        }))->shouldBeCalled();

        $this->composer->getPackage()->willReturn($rootPackage);
        $this->composer->getConfig()->willReturn($config->reveal());

        $this->io->isInteractive()->willReturn(true);
        $this->io->askAndValidate(
            'Enter the version of <info>extra-dependency-foo</info> to require'
                . ' (or leave blank to use the latest version): ',
            Argument::type('callable')
        )->willReturn('17.0.1-dev');

        $this->io->write('<info>    Updating composer.json</info>')->shouldBeCalled();
        $this->io->write('<info>Updating root package</info>')->shouldBeCalled();
        $this->io->write('<info>    Running an update to install dependent packages</info>')->shouldBeCalled();

        $this->setUpComposerInstaller(['extra-dependency-foo']);
        $this->setUpComposerJson();

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));
    }

    public function testInstallOneDependenciesWhenOneIsAlreadyInstalled()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency' => [
                'extra-dependency-foo',
                'extra-dependency-bar',
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $config = $this->prophesize(Config::class);
        $config->get('sort-packages')->willReturn(true);
        $config->get(Argument::any())->willReturn(null);

        $link = $this->prophesize(Link::class);
        $link->getTarget()->willReturn('extra-dependency-bar');

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn(['extra-dependency-bar' => $link->reveal()]);
        $rootPackage->getDevRequires()->willReturn([]);
        $rootPackage->setRequires(Argument::that(function (array $arguments) {
            if (count($arguments) !== 2) {
                return false;
            }

            if (! $this->assertSetRequiresArgument('extra-dependency-foo', '17.0.1-dev', $arguments)) {
                return false;
            }

            if (! isset($arguments['extra-dependency-bar'])) {
                return false;
            }

            return true;
        }))->shouldBeCalled();

        $this->composer->getPackage()->willReturn($rootPackage);
        $this->composer->getConfig()->willReturn($config->reveal());

        $this->io->isInteractive()->willReturn(true);
        $this->io->askAndValidate(
            'Enter the version of <info>extra-dependency-foo</info> to require'
                . ' (or leave blank to use the latest version): ',
            Argument::type('callable')
        )->willReturn('17.0.1-dev');

        $this->io->write('<info>    Updating composer.json</info>')->shouldBeCalled();
        $this->io->write('<info>Updating root package</info>')->shouldBeCalled();
        $this->io->write('<info>    Running an update to install dependent packages</info>')->shouldBeCalled();

        $this->setUpComposerInstaller(['extra-dependency-foo']);
        $this->setUpComposerJson();

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));

        $json = file_get_contents(vfsStream::url('project/composer.json'));
        $composer = json_decode($json, true);
        $this->assertTrue(isset($composer['require']['extra-dependency-foo']));
        $this->assertSame('17.0.1-dev', $composer['require']['extra-dependency-foo']);
    }

    public function testInstallSingleDependencyAndAutomaticallyChooseLatestVersion()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency' => [
                'extra-dependency-foo',
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $config = $this->prophesize(Config::class);
        $config->get('sort-packages')->willReturn(true);
        $config->get(Argument::any())->willReturn(null);

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn([]);
        $rootPackage->getDevRequires()->willReturn([]);
        $rootPackage->setRequires(Argument::that(function (array $arguments) {
            if (count($arguments) !== 1) {
                return false;
            }

            if (! $this->assertSetRequiresArgument('extra-dependency-foo', '13.4.2', $arguments)) {
                return false;
            }

            return true;
        }))->shouldBeCalled();
        $rootPackage->getMinimumStability()->willReturn('stable');

        $this->composer->getPackage()->willReturn($rootPackage);
        $this->composer->getConfig()->willReturn($config->reveal());

        $this->io->isInteractive()->willReturn(true);
        $this->io->askAndValidate(
            'Enter the version of <info>extra-dependency-foo</info> to require'
                . ' (or leave blank to use the latest version): ',
            Argument::type('callable')
        )->willReturn(false);

        $this->io->write('<info>    Updating composer.json</info>')->shouldBeCalled();
        $this->io->write('<info>Updating root package</info>')->shouldBeCalled();
        $this->io->write('<info>    Running an update to install dependent packages</info>')->shouldBeCalled();
        $this->io->write('Using version <info>13.4.2</info> for <info>extra-dependency-foo</info>')->shouldBeCalled();

        $this->setUpComposerInstaller(['extra-dependency-foo']);
        $this->setUpComposerJson();

        $package = $this->prophesize(PackageInterface::class);

        $versionSelector = $this->prophesize(VersionSelector::class);
        $versionSelector->findBestCandidate('extra-dependency-foo', null, null, 'stable')
            ->willReturn($package->reveal())
            ->shouldBeCalled();
        $versionSelector->findRecommendedRequireVersion($package->reveal())
            ->willReturn('13.4.2')
            ->shouldBeCalled();

        $this->setUpVersionSelector($versionSelector->reveal());
        $this->setUpPool();

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));

        $json = file_get_contents(vfsStream::url('project/composer.json'));
        $composer = json_decode($json, true);
        $this->assertTrue(isset($composer['require']['extra-dependency-foo']));
        $this->assertSame('13.4.2', $composer['require']['extra-dependency-foo']);
    }

    public function testInstallSingleDependencyAndAutomaticallyChooseLatestVersionNotFoundMatchingPackage()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency' => [
                'extra-dependency-foo',
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $config = $this->prophesize(Config::class);
        $config->get('sort-packages')->willReturn(true);
        $config->get(Argument::any())->willReturn(null);

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn([]);
        $rootPackage->getDevRequires()->willReturn([]);
        $rootPackage->getMinimumStability()->willReturn('stable');

        $this->composer->getPackage()->willReturn($rootPackage);
        $this->composer->getConfig()->willReturn($config->reveal());

        $this->io->isInteractive()->willReturn(true);
        $this->io->askAndValidate(
            'Enter the version of <info>extra-dependency-foo</info> to require'
                . ' (or leave blank to use the latest version): ',
            Argument::type('callable')
        )->willReturn(false);

        $this->setUpComposerJson();

        $versionSelector = $this->prophesize(VersionSelector::class);
        $versionSelector->findBestCandidate('extra-dependency-foo', null, null, 'stable')->willReturn(null);

        $this->setUpVersionSelector($versionSelector->reveal());
        $this->setUpPool();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Could not find package extra-dependency-foo at any version for your minimum-stability'
        );
        $this->plugin->onPostPackage($event->reveal());
    }

    public function testUpdateComposerWithCurrentlyInstalledVersion()
    {
        $installedPackage = $this->prophesize(PackageInterface::class);
        $installedPackage->getName()->willReturn('extra-dependency-foo');
        $installedPackage->getPrettyVersion()->willReturn('0.5.1');

        $this->localRepository->getPackages()->willReturn([$installedPackage->reveal()]);
        $this->plugin->activate($this->composer->reveal(), $this->io->reveal());

        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency' => [
                'extra-dependency-foo',
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $config = $this->prophesize(Config::class);
        $config->get('sort-packages')->willReturn(true);
        $config->get(Argument::any())->willReturn(null);

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn([]);
        $rootPackage->getDevRequires()->willReturn([]);
        $rootPackage->setRequires(Argument::that(function (array $arguments) {
            if (count($arguments) !== 1) {
                return false;
            }

            if (! $this->assertSetRequiresArgument('extra-dependency-foo', '^0.5.1', $arguments)) {
                return false;
            }

            return true;
        }))->shouldBeCalled();
        $rootPackage->getMinimumStability()->willReturn('stable');

        $this->composer->getPackage()->willReturn($rootPackage);
        $this->composer->getConfig()->willReturn($config->reveal());

        $this->io->isInteractive()->willReturn(true);
        $this->io
            ->write(
                'Added package <info>extra-dependency-foo</info> to composer.json with constraint'
                    . ' <info>^0.5.1</info>; to upgrade, run <info>composer require extra-dependency-foo:VERSION</info>'
            )
            ->shouldBeCalled();
        $this->io->write('<info>    Updating composer.json</info>')->shouldBeCalled();
        $this->io->write('<info>Updating root package</info>')->shouldBeCalled();
        $this->io->write('<info>    Running an update to install dependent packages</info>')->shouldBeCalled();

        $this->setUpComposerInstaller(['extra-dependency-foo']);
        $this->setUpComposerJson();

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));

        $json = file_get_contents(vfsStream::url('project/composer.json'));
        $composer = json_decode($json, true);
        $this->assertTrue(isset($composer['require']['extra-dependency-foo']));
        $this->assertSame('^0.5.1', $composer['require']['extra-dependency-foo']);
    }

    public function testDependencyOrChoosePackageToInstall()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency-or' => [
                'My question foo bar baz' => [
                    'extra-dependency-foo',
                    'extra-dependency-bar',
                ],
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $config = $this->prophesize(Config::class);
        $config->get('sort-packages')->willReturn(true);
        $config->get(Argument::any())->willReturn(null);

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn([]);
        $rootPackage->getDevRequires()->willReturn([]);
        $rootPackage->setRequires(Argument::that(function (array $arguments) {
            if (count($arguments) !== 1) {
                return false;
            }

            if (! $this->assertSetRequiresArgument('extra-dependency-bar', '13.4.2', $arguments)) {
                return false;
            }

            return true;
        }))->shouldBeCalled();
        $rootPackage->getMinimumStability()->willReturn('stable');

        $this->composer->getPackage()->willReturn($rootPackage);
        $this->composer->getConfig()->willReturn($config->reveal());

        $this->io->isInteractive()->willReturn(true);
        $this->io
            ->askAndValidate(
                Argument::that(function ($arg) {
                    if (! is_array($arg)) {
                        return false;
                    }

                    Assert::assertCount(4, $arg);
                    Assert::assertSame('<question>My question foo bar baz</question>' . "\n", $arg[0]);
                    Assert::assertSame('  [<comment>1</comment>] extra-dependency-foo' . "\n", $arg[1]);
                    Assert::assertSame('  [<comment>2</comment>] extra-dependency-bar' . "\n", $arg[2]);
                    Assert::assertSame('  Make your selection: ', $arg[3]);

                    return true;
                }),
                Argument::that(function ($arg) {
                    if (! is_callable($arg)) {
                        return false;
                    }

                    Assert::assertSame('extra-dependency-foo', $arg(1));
                    Assert::assertSame('extra-dependency-foo', $arg('1'));
                    Assert::assertSame('extra-dependency-foo', $arg(' 1'));
                    Assert::assertSame('extra-dependency-foo', $arg('1.0'));
                    Assert::assertSame('extra-dependency-bar', $arg(2));
                    Assert::assertSame('extra-dependency-bar', $arg('2'));
                    Assert::assertSame('extra-dependency-bar', $arg(' 2'));
                    Assert::assertSame('extra-dependency-bar', $arg('2.2'));
                    Assert::assertNull($arg(''));
                    Assert::assertNull($arg(' '));
                    Assert::assertNull($arg('a'));
                    Assert::assertNull($arg('1a'));
                    Assert::assertNull($arg(' a'));
                    Assert::assertNull($arg(0));
                    Assert::assertNull($arg(3));

                    return true;
                })
            )
            ->willReturn('', 'extra-dependency-bar')
            ->shouldBeCalledTimes(2);
        $this->io
            ->askAndValidate(
                'Enter the version of <info>extra-dependency-bar</info> to require'
                    . ' (or leave blank to use the latest version): ',
                Argument::type('callable')
            )
            ->willReturn(false)
            ->shouldBeCalledTimes(1);

        $this->io->write('<info>    Updating composer.json</info>')->shouldBeCalled();
        $this->io->write('<info>Updating root package</info>')->shouldBeCalled();
        $this->io->write('<info>    Running an update to install dependent packages</info>')->shouldBeCalled();
        $this->io->write('Using version <info>13.4.2</info> for <info>extra-dependency-bar</info>')->shouldBeCalled();

        $this->setUpComposerInstaller(['extra-dependency-bar']);
        $this->setUpComposerJson();

        $package = $this->prophesize(PackageInterface::class);

        $versionSelector = $this->prophesize(VersionSelector::class);
        $versionSelector->findBestCandidate('extra-dependency-bar', null, null, 'stable')
            ->willReturn($package->reveal())
            ->shouldBeCalled();
        $versionSelector->findRecommendedRequireVersion($package->reveal())
            ->willReturn('13.4.2')
            ->shouldBeCalled();

        $this->setUpVersionSelector($versionSelector->reveal());
        $this->setUpPool();

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));

        $json = file_get_contents(vfsStream::url('project/composer.json'));
        $composer = json_decode($json, true);
        $this->assertTrue(isset($composer['require']['extra-dependency-bar']));
        $this->assertSame('13.4.2', $composer['require']['extra-dependency-bar']);
    }

    public function testDependencyOrOnePackageIsAlreadyInstalledAndShouldBeAddedIntoRootComposer()
    {
        $installedPackage = $this->prophesize(PackageInterface::class);
        $installedPackage->getName()->willReturn('extra-dependency-baz');
        $installedPackage->getPrettyVersion()->willReturn('3.7.1');

        $this->localRepository->getPackages()->willReturn([$installedPackage->reveal()]);
        $this->plugin->activate($this->composer->reveal(), $this->io->reveal());

        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency-or' => [
                'Choose something' => [
                    'extra-dependency-bar',
                    'extra-dependency-baz',
                ],
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $config = $this->prophesize(Config::class);
        $config->get('sort-packages')->willReturn(true);
        $config->get(Argument::any())->willReturn(null);

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn([]);
        $rootPackage->getDevRequires()->willReturn([]);
        $rootPackage->setRequires(Argument::that(function (array $arguments) {
            if (count($arguments) !== 1) {
                return false;
            }

            if (! $this->assertSetRequiresArgument('extra-dependency-baz', '^3.7.1', $arguments)) {
                return false;
            }

            return true;
        }))->shouldBeCalled();
        $rootPackage->getMinimumStability()->willReturn('stable');

        $this->composer->getPackage()->willReturn($rootPackage);
        $this->composer->getConfig()->willReturn($config->reveal());

        $this->io->isInteractive()->willReturn(true);
        $this->io
            ->write(
                'Added package <info>extra-dependency-baz</info> to composer.json with constraint'
                    . ' <info>^3.7.1</info>; to upgrade, run <info>composer require extra-dependency-baz:VERSION</info>'
            )
            ->shouldBeCalled();
        $this->io
            ->askAndValidate(Argument::any(), Argument::any())
            ->shouldNotBeCalled();

        $this->io->write('<info>    Updating composer.json</info>')->shouldBeCalled();
        $this->io->write('<info>Updating root package</info>')->shouldBeCalled();
        $this->io->write('<info>    Running an update to install dependent packages</info>')->shouldBeCalled();

        $this->setUpComposerInstaller(['extra-dependency-baz']);
        $this->setUpComposerJson();
        $this->setUpPool();

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));

        $json = file_get_contents(vfsStream::url('project/composer.json'));
        $composer = json_decode($json, true);
        $this->assertTrue(isset($composer['require']['extra-dependency-baz']));
        $this->assertSame('^3.7.1', $composer['require']['extra-dependency-baz']);
    }

    public function testDependencyOrOnePackageIsAlreadyInRootComposer()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency-or' => [
                'Choose something' => [
                    'extra-dependency-foo',
                    'extra-dependency-baz',
                ],
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $config = $this->prophesize(Config::class);
        $config->get('sort-packages')->willReturn(true);
        $config->get(Argument::any())->willReturn(null);

        $link = $this->prophesize(Link::class);
        $link->getTarget()->willReturn('extra-dependency-bar');

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn(['extra-dependency-foo' => $link]);
        $rootPackage->getDevRequires()->willReturn([]);
        $rootPackage->setRequires(Argument::any())->shouldNotBeCalled();
        $rootPackage->getMinimumStability()->willReturn('stable');

        $this->composer->getPackage()->willReturn($rootPackage);
        $this->composer->getConfig()->willReturn($config->reveal());

        $this->io->isInteractive()->willReturn(true)->shouldBeCalledTimes(1);
        $this->io
            ->askAndValidate(Argument::any(), Argument::any())
            ->shouldNotBeCalled();

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));
    }

    public function testDependencyOrWrongDefinitionThrowsException()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency-or' => [
                'extra-dependency-foo',
                'extra-dependency-baz',
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $config = $this->prophesize(Config::class);
        $config->get('sort-packages')->willReturn(true);
        $config->get(Argument::any())->willReturn(null);

        $link = $this->prophesize(Link::class);
        $link->getTarget()->willReturn('extra-dependency-bar');

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn(['extra-dependency-foo' => $link]);
        $rootPackage->getDevRequires()->willReturn([]);
        $rootPackage->setRequires(Argument::any())->shouldNotBeCalled();
        $rootPackage->getMinimumStability()->willReturn('stable');

        $this->composer->getPackage()->willReturn($rootPackage);
        $this->composer->getConfig()->willReturn($config->reveal());

        $this->io->isInteractive()->willReturn(true)->shouldBeCalledTimes(1);
        $this->io
            ->askAndValidate(Argument::any(), Argument::any())
            ->shouldNotBeCalled();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You must provide at least two optional dependencies.');
        $this->assertNull($this->plugin->onPostPackage($event->reveal()));
    }

    public function testIntegrationHandleDependencyAndDependencyOr()
    {
        /** @var PackageInterface|ObjectProphecy $package */
        $package = $this->prophesize(PackageInterface::class);
        $package->getName()->willReturn('some/component');
        $package->getExtra()->willReturn([
            'dependency' => [
                'extra-package-required',
            ],
            'dependency-or' => [
                'Choose something' => [
                    'extra-choose-one',
                    'extra-choose-two',
                    'extra-choose-three',
                ],
            ],
        ]);

        $operation = $this->prophesize(InstallOperation::class);
        $operation->getPackage()->willReturn($package->reveal());

        $event = $this->prophesize(PackageEvent::class);
        $event->isDevMode()->willReturn(true);
        $event->getOperation()->willReturn($operation->reveal());

        $config = $this->prophesize(Config::class);
        $config->get('sort-packages')->willReturn(true);
        $config->get(Argument::any())->willReturn(null);

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getRequires()->willReturn([]);
        $rootPackage->getDevRequires()->willReturn([]);
        $rootPackage->setRequires(Argument::that(function (array $arguments) {
            if (count($arguments) !== 2) {
                return false;
            }

            if (! $this->assertSetRequiresArgument('extra-package-required', '3.9.1', $arguments)) {
                return false;
            }

            if (! $this->assertSetRequiresArgument('extra-choose-two', '2.1.5', $arguments)) {
                return false;
            }

            return true;
        }))->shouldBeCalled();
        $rootPackage->getMinimumStability()->willReturn('stable');

        $this->composer->getPackage()->willReturn($rootPackage);
        $this->composer->getConfig()->willReturn($config->reveal());

        $this->io->isInteractive()->willReturn(true);
        $this->io
            ->askAndValidate(
                Argument::that(function ($arg) {
                    if (! is_array($arg)) {
                        return false;
                    }

                    Assert::assertCount(5, $arg);
                    Assert::assertSame('<question>Choose something</question>' . "\n", $arg[0]);
                    Assert::assertSame('  [<comment>1</comment>] extra-choose-one' . "\n", $arg[1]);
                    Assert::assertSame('  [<comment>2</comment>] extra-choose-two' . "\n", $arg[2]);
                    Assert::assertSame('  [<comment>3</comment>] extra-choose-three' . "\n", $arg[3]);
                    Assert::assertSame('  Make your selection: ', $arg[4]);

                    return true;
                }),
                Argument::type('callable')
            )
            ->willReturn('extra-choose-two')
            ->shouldBeCalledTimes(1);
        $this->io
            ->askAndValidate(
                'Enter the version of <info>extra-package-required</info> to require'
                    . ' (or leave blank to use the latest version): ',
                Argument::type('callable')
            )
            ->willReturn(false)
            ->shouldBeCalledTimes(1);
        $this->io
            ->askAndValidate(
                'Enter the version of <info>extra-choose-two</info> to require'
                    . ' (or leave blank to use the latest version): ',
                Argument::type('callable')
            )
            ->willReturn(false)
            ->shouldBeCalledTimes(1);

        $this->io->write('<info>    Updating composer.json</info>')->shouldBeCalled();
        $this->io->write('<info>Updating root package</info>')->shouldBeCalled();
        $this->io->write('<info>    Running an update to install dependent packages</info>')->shouldBeCalled();
        $this->io->write('Using version <info>3.9.1</info> for <info>extra-package-required</info>')->shouldBeCalled();
        $this->io->write('Using version <info>2.1.5</info> for <info>extra-choose-two</info>')->shouldBeCalled();

        $this->setUpComposerInstaller(['extra-package-required', 'extra-choose-two']);
        $this->setUpComposerJson();

        $versionSelector = $this->prophesize(VersionSelector::class);
        $versionSelector->findBestCandidate('extra-package-required', null, null, 'stable')
            ->willReturn($package->reveal())
            ->shouldBeCalledTimes(1);
        $versionSelector->findBestCandidate('extra-choose-two', null, null, 'stable')
            ->willReturn($package->reveal())
            ->shouldBeCalledTimes(1);
        $versionSelector->findRecommendedRequireVersion($package->reveal())
            ->willReturn('3.9.1', '2.1.5')
            ->shouldBeCalledTimes(2);

        $this->setUpVersionSelector($versionSelector->reveal());
        $this->setUpPool();

        $this->assertNull($this->plugin->onPostPackage($event->reveal()));

        $json = file_get_contents(vfsStream::url('project/composer.json'));
        $composer = json_decode($json, true);
        $this->assertTrue(isset($composer['require']['extra-package-required']));
        $this->assertSame('3.9.1', $composer['require']['extra-package-required']);
        $this->assertTrue(isset($composer['require']['extra-choose-two']));
        $this->assertSame('2.1.5', $composer['require']['extra-choose-two']);
    }

    private function assertSetRequiresArgument($name, $version, array $arguments)
    {
        if (! isset($arguments[$name])) {
            return false;
        }

        $argument = $arguments[$name];

        if (! $argument instanceof Link) {
            return false;
        }

        if ($argument->getTarget() !== $name) {
            return false;
        }

        if ($argument->getConstraint()->getPrettyString() !== $version) {
            return false;
        }

        if ($argument->getDescription() !== 'requires') {
            return false;
        }

        return true;
    }

    public function testComposerInstallerFactory()
    {
        $r = new ReflectionProperty($this->plugin, 'installerFactory');
        $r->setAccessible(true);
        $factory = $r->getValue($this->plugin);

        $this->composer->getConfig()
            ->willReturn($this->prophesize(Config::class)->reveal())
            ->shouldBeCalled();
        $this->composer->getDownloadManager()
            ->willReturn($this->prophesize(DownloadManager::class))
            ->shouldBeCalled();
        $this->composer->getLocker()
            ->willReturn($this->prophesize(Locker::class))
            ->shouldBeCalled();
        $this->composer->getInstallationManager()
            ->willReturn($this->prophesize(Installer\InstallationManager::class))
            ->shouldBeCalled();
        $this->composer->getAutoloadGenerator()
            ->willReturn($this->prophesize(AutoloadGenerator::class))
            ->shouldBeCalled();

        $rootPackage = $this->prophesize(RootPackageInterface::class);

        $rf = new ReflectionMethod($factory[0], $factory[1]);
        $rf->setAccessible(true);
        $instance = $rf->invoke(
            $this->plugin,
            $this->composer->reveal(),
            $this->io->reveal(),
            $rootPackage->reveal()
        );

        $this->assertInstanceOf(Installer::class, $instance);
    }

    public function testVersionSelectorFactory()
    {
        $r = new ReflectionProperty($this->plugin, 'versionSelectorFactory');
        $r->setAccessible(true);
        $factory = $r->getValue($this->plugin);

        $pool = $this->prophesize(Pool::class);

        $rf = new ReflectionMethod($factory[0], $factory[1]);
        $rf->setAccessible(true);
        $instance = $rf->invoke(
            $this->plugin,
            $pool->reveal()
        );

        $this->assertInstanceOf(VersionSelector::class, $instance);
    }

    public function testGetPool()
    {
        $r = new ReflectionMethod($this->plugin, 'getPool');
        $r->setAccessible(true);

        $rootPackage = $this->prophesize(RootPackageInterface::class);
        $rootPackage->getMinimumStability()->willReturn('dev')->shouldBeCalled();
        $this->composer->getPackage()->willReturn($rootPackage);

        $pool = $r->invoke($this->plugin);

        $this->assertInstanceOf(Pool::class, $pool);
    }
}
