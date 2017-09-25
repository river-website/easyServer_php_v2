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
$web['webSite'] = '127.0.0.1';
$web['path'] = '/www/wwwroot/easyPHP';
$webs[] = $web;
$serverData['serverRoot'] = $webs;
$easy->serverData = $serverData;
$easy->start();