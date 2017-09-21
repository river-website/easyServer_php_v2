<?php
/**
 * Created by PhpStorm.
 * User: win10
 * Date: 2017/9/21
 * Time: 12:44
 */

require 'ezReactorLibEvent.php';

if (!function_exists('ezReactor')) {
	function ezReactor(){
		return ezReactor::getInterface();
	}
}
if (!function_exists('ezReactorAdd')) {
	function ezReactorAdd($fd, $status, $func,$arg = null){
		ezReactor()->add($fd, $status, $func,$arg);
	}
}
if (!function_exists('ezReactorDel')) {
	function ezReactorDel($fd,$status){
		ezReactor()->del($fd, $status);
	}
}


class ezReactor{

	const eventTime 		= 1;
	const eventRead 		= 2;
	const eventWrite 		= 4;
	const eventSignal	    = 8;
	const eventTimeOnce		= 16;
	const eventClock		= 32;
	const eventExcept 		= 64;

	private $reactor 		= null;

	public function __construct(){
		$this->init();
	}
	static public function getInterface(){
		static $reactor;
		if(empty($reactor)) $reactor = new ezReactor();
		return $reactor;
	}
	private function init(){
		if(extension_loaded('libevent')) $this->reactor = new ezReactorLibEvent();
		else $this->reactor = new ezReactorSelect();
	}
	// 对外接口 增加一个监视资源，状态及事件处理
	public function add($fd, $status, $func,$arg = null){
		$this->reactor->add($fd, $status, $func,$arg);
	}
	// 对外接口 删除一个监视资源，状态及事件处理
	public function del($fd,$status){
		$this->reactor->del($fd,$status);
	}
	// 对外接口 开始监视资源
	public function loop(){
		$this->reactor->loop();
	}
}