<?php
/**
 * Created by PhpStorm.
 * User: lhe
 * Date: 2017/9/21
 * Time: 8:12
 */
if(!defined('ROOT'))define('ROOT', __DIR__.'/..');

class easy{
    static public $pidsPath			= '/system/pids';
    static public $checkTime		= 1;
    public $server					= 'ezServer';
    public $serverData				= array();

    static public function getPids(){
        return json_decode(file_get_contents(self::$pidsPath),true);
    }
    static public function setPids($pids){
        file_put_contents(self::$pidsPath, json_encode($pids,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    }
    static public function addPid($type,$pid){
        if(empty($pid))return;
        if(empty($type))return;
        $pids = self::getPids();
        $pidData['pid'] = $pid;
        $pidData['state'] = 'run';
        $pidData['time'] = date('Y-m-d H:i:s',time());
        $pids[$type][] = $pidData;
        self::setPids($pids);
    }
    static public function delPid($pid){
        if(empty($pid))return;
        $pids = self::getPids();
        foreach ($pids as $type => &$pidList) {
            foreach ($pidList as $index=>$pidData) {
                if($pidData['pid'] == $pid)
                    unset($pidList[$index]);
            }
        }
        self::setPids($pids);
    }
    static public function updatePid($pid,$state = 'run'){
        if(empty($pid))return;
        $pids = self::getPids();
        foreach ($pids as $type => &$pidList) {
            foreach ($pidList as $index=>$pidData) {
                if($pidData['pid'] == $pid)
                    $pidList[$index]['state'] = $state;
            }
        }
        self::setPids($pids);
    }
    static public function getPidState($pid){
        if(empty($pid))return;
        $pids = self::getPids();
        foreach ($pids as $type => $pidList) {
            foreach ($pidList as $index=>$pidData) {
                if($pidData['pid'] == $pid)
                    return $pidList[$index]['state'];
            }
        }
    }
    static public function killPids($killPids){
        $killList = $killPids;
        while(count($killList)>0){
            $live = array();
            foreach ($killList as $pid) {
                if (posix_kill($pid, 0)) {
                    $ret = pcntl_waitpid($pid,$status,WNOHANG);
                    if($ret<=0) {
                        posix_kill($pid, SIGKILL);
                        $live[] = $pid;
                    }
                }
            }
            $killList = $live;
        }
        foreach ($killPids as $pid)
            self::delPid($pid);
    }
    static public function getChilds($self = 'main'){
        $pids = self::getPids();
        $childPids = array();
        foreach ($pids as $type => $pidList) {
            if($type == 'main')continue;
            if($self == 'server' && $type == 'server')continue;
            foreach ($pidList as $pidData)
                $childPids[$pidData['pid']] = $pidData;
        }
        return $childPids;
    }
    static public function checkExitPid($exitPid = 0){
        if($exitPid>0){
            $pidState = easy::getPidState($exitPid);
            if(isset($pidState)){
                easy::delPid($exitPid);
                if($pidState == 'run')
                    return true;
            }
        }
    }
    public function start(){
		$this->init();
		$this->back();
        $this->forkServer();
        $this->monitorServer();
    }
    private function back(){
        $pid  = pcntl_fork();
        if($pid > 0)exit();
        self::addPid('main',getmypid());
    }
    private function init(){
        self::$pidsPath = ROOT.self::$pidsPath;
    	file_put_contents(self::$pidsPath,null);
	}
    private function forkServer(){
        $pid = pcntl_fork();
        if($pid == 0) {
            self::addPid('server',getmypid());
            // load server file
            $serverPath = $this->server.'.php';
            require $serverPath;
            // start server
            $server = new $this->server();
            $server->serverData = $this->serverData;
            $server->start();
            exit();
        }
    }
    private function monitorServer(){
        while(true){
            $pid = pcntl_wait($status, WNOHANG );
            $this->checkServer($pid);
            sleep(self::$checkTime);
        }
        exit();
    }
    private function checkServer($exitPid){
        $mainStatus = self::getPidState(getmypid());
        if($mainStatus === 'run'){
            // normal runing
            if(self::checkExitPid($exitPid))
                $this->forkServer();
        }else if($mainStatus === 'stop'){
            // close all(self),del pids file
            self::killPids(array_keys(self::getChilds('main')));
            unlink(self::$pidsPath);
            exit();
        }else if($mainStatus === 'reload'){
            // close all(no self),reload server
            self::killPids(array_keys(self::getChilds('main')));
            self::updatePid(getmypid(),'run');
            $this->forkServer();
        }
    }
}