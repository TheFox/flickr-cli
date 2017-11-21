<?php

namespace TheFox\FlickrCli\Command;

use Psr\Log\NullLogger;
use RuntimeException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * This is the common parent class of all Flickr CLI commands. It handles configuration, logging, and the filesystem.
 */
abstract class FlickrCliCommand extends Command
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var string
     */
    private $configFilePath;

    /**
     * @var bool
     */
    private $isConfigFileRequired;

    /**
     * @var string[][]
     */
    private $config;

    /**
     * FlickrCliCommand constructor.
     * @param null|string $name
     */
    public function __construct($name = null)
    {
        parent::__construct($name);

        $this->logger = new NullLogger();
        $this->output = new NullOutput();
        $this->configFilePath = 'config.yml';
        $this->isConfigFileRequired = true;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @return InputInterface
     */
    public function getInput(): InputInterface
    {
        return $this->input;
    }

    /**
     * @return OutputInterface
     */
    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    /**
     * @return string
     */
    public function getConfigFilePath(): string
    {
        return $this->configFilePath;
    }

    /**
     * @return bool
     */
    public function isConfigFileRequired(): bool
    {
        return $this->isConfigFileRequired;
    }

    /**
     * @param bool $isConfigFileRequired
     */
    public function setIsConfigFileRequired(bool $isConfigFileRequired)
    {
        $this->isConfigFileRequired = $isConfigFileRequired;
    }

    /**
     * @return \string[][]
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param \string[][] $config
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * Configure the command.
     * This adds the standard 'config' and 'log' options that are common to all Flickr CLI commands.
     */
    protected function configure()
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file. Default: ./config.yml')
            ->addOption('log', 'l', InputOption::VALUE_OPTIONAL, 'Path to log directory. Default: ./log')
        ;
    }

    protected function setup(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->setupLogger();
        $this->setupConfig();
    }

    private function setupLogger()
    {
        $this->logger = new Logger($this->getName());
    }

    private function setupConfig()
    {
        $input = $this->getInput();

        if ($input->hasOption('config') && $input->getOption('config')) {
            $configFilePath = $input->getOption('config');
        } elseif ($envConfigFile = getenv('FLICKRCLI_CONFIG')) {
            $configFilePath = $envConfigFile;
        }

        if (!isset($configFilePath) || !$configFilePath) {
            throw new RuntimeException('No config file path found.');
        }
        $this->configFilePath = $configFilePath;

        $filesystem = new Filesystem();
        if (!$filesystem->exists($this->configFilePath) && $this->isConfigFileRequired()) {
            throw new RuntimeException(sprintf('Config file not found: %s', $this->configFilePath));
        }
    }

    /**
     * @return \string[][]
     */
    public function loadConfig(): array
    {
        $configFilePath = $this->getConfigFilePath();
        if (!$configFilePath) {
            throw new RuntimeException('Config File Path is not set.');
        }

        $this->getLogger()->debug('Load configuration');

        /** @var string[][] $config */
        $config = Yaml::parse($configFilePath);

        if (!isset($config)
            || !isset($config['flickr'])
            || !isset($config['flickr']['consumer_key'])
            || !isset($config['flickr']['consumer_secret'])
        ) {
            throw new RuntimeException('Invalid configuration file.');
        }

        $this->config = $config;
        return $this->config;
    }

    public function saveConfig(array $config = null)
    {
        if ($config) {
            $this->setConfig($config);
        } else {
            $config = $this->getConfig();
        }

        $configContent = Yaml::dump($config);

        $configFilePath = $this->getConfigFilePath();

        $filesystem = new Filesystem();
        $filesystem->touch($configFilePath);
        $filesystem->chmod($configFilePath, 0600);
        $filesystem->dumpFile($configFilePath, $configContent);
    }

    /**
     * Load and check the configuration file and retrieve its contents.
     *
     * @return string[][]
     * @throws RuntimeException If there is a problem with the specified config file.
     */
    //protected function getConfig1(InputInterface $input)
    //{
    //    $configFile = $this->getConfigFilepath($input);
    //
    //    $logger = $this->getLogger($input);
    //    $logger->debug(sprintf('Config file in use: %s', $configFile));
    //
    //

    //
    //    return $config;
    //}

    /**
     * Get a new logger object, identified by the name of this command.
     *
     * @param InputInterface $input
     * @param string $label The label to identify which logger this is.
     * @return Logger
     */
    //protected function getLogger1(InputInterface $input, $label = '')
    //{
    //    $labelFormat = (!empty($label)) ? $label . ' ' : '';
    //    $logFormatter = new LineFormatter("[$labelFormat%datetime%] %level_name%: %message%\n");
    //
    //    // @TODO This should be set by a new CLI debug parameter.
    //    $logLevel = Logger::DEBUG;
    //
    //    // Standard out.
    //    $logHandlerStderr = new StreamHandler('php://stdout', $logLevel);
    //    $logHandlerStderr->setFormatter($logFormatter);
    //    $logger->pushHandler($logHandlerStderr);
    //
    //    // Log directory.
    //    $logDir = 'log';
    //    if ($input->hasOption('log') && $input->getOption('log')) {
    //        $logDir = $input->getOption('log');
    //    }
    //
    //    // Log file.
    //    $labelPart = (!empty($label)) ? $label . '_' : '';
    //    $logFile = $logDir . '/' . $this->getName() . '_' . $labelPart . date('Y-m-d') . '.log';
    //    $logHandlerFile = new StreamHandler($logFile, $logLevel);
    //    $logHandlerFile->setFormatter($logFormatter);
    //    $logger->pushHandler($logHandlerFile);
    //
    //    return $logger;
    //}

}
