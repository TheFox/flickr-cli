<?php

namespace TheFox\FlickrCli\Command;

use Exception;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * This is the common parent class of all Flickr CLI commands. It handles configuration, logging, and the filesystem.
 */
abstract class FlickrCliCommand extends Command
{

    /** @var Filesystem */
    protected $fs;

    /**
     * @param string|null $name The name of the command; passing null means it must be set in configure()
     */
    public function __construct($name = null)
    {
        parent::__construct($name);
        $this->fs = new Filesystem();
    }

    /**
     * Configure the command.
     * This adds the standard 'config' and 'log' options that are common to all Flickr CLI commands.
     */
    protected function configure()
    {
        parent::configure();
        $this->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file. Default: ./config.yml');
        $this->addOption('log', 'l', InputOption::VALUE_OPTIONAL, 'Path to log directory. Default: ./log');
    }

    /**
     * Get a new logger object, identified by the name of this command.
     * @param InputInterface $input
     * @param string $label The label to identify which logger this is.
     * @return Logger
     */
    protected function getLogger(InputInterface $input, $label = '')
    {
        $logger = new Logger($this->getName());
        $labelFormat = (!empty($label)) ? $label . ' ' : '';
        $logFormatter = new LineFormatter("[$labelFormat%datetime%] %level_name%: %message%\n");

        // @TODO This should be set by a new CLI debug parameter.
        $logLevel = Logger::DEBUG;

        // Standard out.
        $logHandlerStderr = new StreamHandler('php://stdout', $logLevel);
        $logHandlerStderr->setFormatter($logFormatter);
        $logger->pushHandler($logHandlerStderr);

        // Log directory.
        $logDir = 'log';
        if ($input->hasOption('log') && $input->getOption('log')) {
            $logDir = $input->getOption('log');
        }

        // Log file.
        $labelPart = (!empty($label)) ? $label . '_' : '';
        $logFile = $logDir . '/' . $this->getName() . '_' . $labelPart . date('Y-m-d') . '.log';
        $logHandlerFile = new StreamHandler($logFile, $logLevel);
        $logHandlerFile->setFormatter($logFormatter);
        $logger->pushHandler($logHandlerFile);

        return $logger;
    }

    /**
     * Load and check the configuration file and retrieve its contents.
     * @return string[]
     * @throws Exception If there is a problem with the specified config file.
     */
    protected function getConfig(InputInterface $input)
    {
        $configFile = 'config.yml';
        if ($input->hasOption('config') && $input->getOption('config')) {
            $configFile = $input->getOption('config');
        }
        $logger = $this->getLogger($input);
        if (!$this->fs->exists($configFile)) {
            throw new Exception('Config file not found: ' . $configFile);
        }
        $logger->info('Config file in use: ' . $configFile);
        $config = Yaml::parse($configFile);
        if (!isset($config)
            || !isset($config['flickr'])
            || !isset($config['flickr']['consumer_key'])
            || !isset($config['flickr']['consumer_secret'])
        ) {
            throw new Exception('Config file must contain consumer key and secret.');
        }
        return $config;
    }
}
