<?php

include __DIR__.'/../vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->load();

$apiKey = getenv('API_KEY');
$endpoint = getenv('ENDPOINT');