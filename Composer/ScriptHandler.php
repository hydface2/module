<?php

namespace Modera\Module\Composer;

use Composer\Composer;
use Composer\Script\Event;
use Composer\Script\CommandEvent;
use Composer\Script\PackageEvent;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\PhpExecutableFinder;
use Modera\Module\Service\ComposerService;

/**
 * @copyright 2013 Modera Foundation
 * @author Sergei Vizel <sergei.vizel@modera.org>
 */
class ScriptHandler
{
    /**
     * @param Event $event
     */
    public static function eventDispatcher(Event $event)
    {
        echo '>>> '. $event->getName() . PHP_EOL;

        if ($event instanceof PackageEvent) {
            $operation = $event->getOperation();
            if ($operation instanceof UpdateOperation) {
                $package = $operation->getTargetPackage();
            } else {
                $package = $operation->getPackage();
            }

            $extra = $package->getExtra();

            if (is_array($extra) && isset($extra['modera-module'])) {
                if (isset($extra['modera-module']['scripts'])) {
                    if (isset($extra['modera-module']['scripts'][$event->getName()])) {

                        $scripts = $extra['modera-module']['scripts'][$event->getName()];
                        foreach ($scripts as $script) {

                            echo '>>> '. $script . PHP_EOL;

                            if (is_callable($script)) {
                                $className = substr($script, 0, strpos($script, '::'));
                                $methodName = substr($script, strpos($script, '::') + 2);
                                $className::$methodName($event);
                            }
                        }

                    }
                }
            }
        }
    }

    /**
     * @param CommandEvent $event
     */
    public static function registerBundles(CommandEvent $event)
    {
        static::createRegisterBundlesFile($event->getComposer());
    }

    /**
     * @param Composer $composer
     */
    protected static function createRegisterBundlesFile(Composer $composer)
    {
        $options = ComposerService::getOptions($composer);

        $file = $options['file'];
        $bundles = $options['register-bundles'];

        $data = array('<?php return array(');
        foreach ($bundles as $bundleClassName) {
            $data[] = '    new ' . $bundleClassName . '(),';
        }
        $data[] = ');';

        if (file_exists($file)) {
            file_put_contents($file, implode("\n", $data) . "\n");
        }
    }

    /**
     * Clears the Symfony cache.
     *
     * @param $event CommandEvent A instance
     */
    public static function clearCache(CommandEvent $event)
    {
        $options = self::getOptions($event);
        $appDir = $options['symfony-app-dir'];

        if (!is_dir($appDir)) {
            echo 'The symfony-app-dir ('.$appDir.') specified in composer.json was not found in '.getcwd().', can not clear the cache.'.PHP_EOL;

            return;
        }

        static::executeCommand($event, $appDir, 'cache:clear --env=prod --no-warmup', $options['process-timeout']);
    }

    /**
     * @param CommandEvent $event
     * @param $appDir
     * @param $cmd
     * @param int $timeout
     * @throws \RuntimeException
     */
    protected static function executeCommand(CommandEvent $event, $appDir, $cmd, $timeout = 300)
    {
        $php = escapeshellarg(self::getPhp());
        $console = escapeshellarg($appDir . '/console');
        if ($event->getIO()->isDecorated()) {
            $console .= ' --ansi';
        }

        $process = new Process($php.' '.$console.' '.$cmd, null, null, null, $timeout);
        $process->run(function ($type, $buffer) { echo $buffer; });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('An error occurred when executing the "%s" command.', escapeshellarg($cmd)));
        }
    }

    /**
     * @param CommandEvent $event
     * @return array
     */
    protected static function getOptions(CommandEvent $event)
    {
        $options = array_merge(array(
            'symfony-app-dir' => 'app',
        ), $event->getComposer()->getPackage()->getExtra());

        $options['process-timeout'] = $event->getComposer()->getConfig()->get('process-timeout');

        return $options;
    }

    /**
     * @return false|string
     * @throws \RuntimeException
     */
    protected static function getPhp()
    {
        $phpFinder = new PhpExecutableFinder;
        if (!$phpPath = $phpFinder->find()) {
            throw new \RuntimeException('The php executable could not be found, add it to your PATH environment variable and try again');
        }

        return $phpPath;
    }
}
