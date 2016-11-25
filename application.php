#!/usr/bin/env php
<?php

require_once __DIR__.'/vendor/autoload.php';

declare(ticks = 1);

use Symfony\Component\Console\Application;

use TheFox\FlickrUploader\FlickrUploader;
use TheFox\FlickrUploader\Command\AuthCommand;
use TheFox\FlickrUploader\Command\UploadCommand;

$application = new Application(FlickrUploader::NAME, FlickrUploader::VERSION);
$application->add(new AuthCommand());
$application->add(new UploadCommand());
$application->run();
