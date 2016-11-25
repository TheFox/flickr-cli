#!/usr/bin/env php
<?php

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;

use TheFox\FlickrCli\FlickrCli;
use TheFox\FlickrCli\Command\AuthCommand;
use TheFox\FlickrCli\Command\DownloadCommand;
use TheFox\FlickrCli\Command\UploadCommand;

$application = new Application(FlickrCli::NAME, FlickrCli::VERSION);
$application->add(new AuthCommand());
$application->add(new DownloadCommand());
$application->add(new UploadCommand());
$application->run();
