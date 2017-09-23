<?php
/**
 * Created by PhpStorm.
 * User: win10
 * Date: 2017/9/21
 * Time: 10:20
 */
require 'system/easy.php';
$easy = new easy();
$easy->server = 'ezWebServer';
$serverData['host'] = '0.0.0.0:80';
$web['webSite'] = '39.108.148.255';
$web['path'] = '/download/v2';
$webs[] = $web;
$serverData['serverRoot'] = $webs;
$easy->serverData = $serverData;
$easy->start();