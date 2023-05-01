#!/usr/bin/env php
<?php
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Chrome\ChromeOptions;

# https://github.com/php-webdriver/php-webdriver/issues/537#issuecomment-1004396394
require_once('./vendor/autoload.php');

$host106 = "http://172.17.0.3:4444";
$host111 = "http://172.17.0.2:4444";
$hostLocal = "http://host.docker.internal:3100";
$hostChrome = "http://chrome:4444";

// Chrome
$options = new ChromeOptions();
$options->addArguments(['--start-maximized']);
$desiredCapabilities = DesiredCapabilities::chrome();

$desiredCapabilities->setCapability(ChromeOptions::CAPABILITY, $options);

$driver = RemoteWebDriver::create($hostChrome, $desiredCapabilities);
$driver->get("https://google.com");

sleep(10);

$driver->quit();

?>
