<?php
/**
 * Created by PhpStorm.
 * User: lhe
 * Date: 2017/9/21
 * Time: 8:34
 */

class ezServer{
    // config
    public $debug				= true;
    public $log               	= true;
    public $runTimePath       	= ROOT.'/runTime';
    public $logFile           	= '/log/log-$date.log';
    public $workCount 		    = 1;
    public $host 				= 'tcp://0.0.0.0:80';
    public $protocol 			= null;

    // call back
    public $onMessage 			= null;
    public $onStart 			= null;
    public $onStop		 		= null;
    public $onConnect 			= null;
    public $onClose 			= null;

    // run time data
    private $myPid              = 0;
    private $serverSocket 		= null;
    private $cacheData			= array();
    private $errorIgnorePaths	= array();
    public $processName	    	= 'server process';
    public $curConnect			= null;
    public $outScreen			= false;

    static public function getInterface(){
        static $server;
        if(empty($server)) $server = new ezServer();
        return $server;
    }
    public function __construct(){
        $this->myPid = getmypid();
    }
    public function log($msg){
        if($this->log){
            $time = time();
            $date = date('Y-m-d',$time);
            $time = date('H:i:s',$time);
            $file = str_replace('$date',$date,$this->runTimePath.$this->logFile);
            $pid = $this->myPid;
            file_put_contents($file,$this->processName."[$pid] $time -> $msg\n",FILE_APPEND);
        }
    }
    public function debugLog($msg){
        if($this->debug){
            $this->log("(debug) $msg");
        }
    }
    public function getCache($key){
        if(isset($this->cacheData[$key])) {
            $value = $this->cacheData[$key];
            if($value['overTime']>time())
                return $value['cacheData'];
        }
    }
    public function setCache($key,$value,$time=0){
        $this->cacheData[$key] = array('overTime'=>$time==0?strtotime('21000000'):$time+time(),'cacheData'=>$value);
    }
    public function addErrorIgnorePath($errno,$path){
        $this->errorIgnorePaths[$errno][$path] = true;
    }
    public function delErrorIgnorePath($errno,$path){
        if(isset($this->errorIgnorePaths[$errno][$path]))
            unset($this->errorIgnorePaths[$errno][$path]);
    }
    public function checkErrorIgnorePath($errno,$checkPath){
        if(empty($this->errorIgnorePaths[$errno]))return false;
        foreach ($this->errorIgnorePaths[$errno] as $path=>$value){
            if(strstr($checkPath,$path) !== false)
                return true;
        }
        return false;
    }
    public function start(){
        set_error_handler(array($this,'errorHandle'));
        $this->initDir();
        $this->createSocket();
        $this->forks();
        $this->monitorWorkers();
    }
    private function initDir(){
        if(!is_dir($this->runTimePath))
            mkdir($this->runTimePath);
    }
    private function createSocket(){
        $this->serverSocket = stream_socket_server($this->host);
        if (!$this->serverSocket) {
            $this->log("error -> create server socket fail!");
            easy::delPid($this->myPid);
            exit();
        }
        stream_set_blocking($this->serverSocket, 0);
        $this->log("server socket: " . $this->serverSocket);
    }
    private function forks(){
        for($i=0;$i<$this->workCount;$i++)
            $this->forkOne();
    }
    private function forkOne(){
        $pid = pcntl_fork();
        if($pid == 0) {
            easy::addPid('work',getmypid());
            $this->processName = 'work process';
            $this->myPid = getmypid();
            $this->reactor();
        }
        else $this->log("work pid: $pid");
    }
    private function reactor(){
        sleep(10000);
//        if(!empty($this->onStart))
//            call_user_func($this->onStart);
//        ezReactorAdd($this->serverSocket, ezReactor::eventRead, array($this, 'onAccept'));
//        ezReactor()->loop();
//        $this->log("work process exit reactor loop");
//        exit();
    }
    private function monitorWorkers(){
        $this->log("start monitor workers");
        while(true){
            $pid = pcntl_wait($status, WNOHANG );
            if($pid>0)$this->log("work process $pid exit");
            $this->checkWorks($pid);
            sleep(easy::$checkTime);
        }
        exit();
    }
    private function checkWorks($exitPid = 0){
        $pids = easy::getPids();
        $childPids = easy::getChilds($pids,'server');
        $status = $pids['server'][0]['state'];
        if ($status == "stop"){
            easy::killPids(array_keys($childPids));
            easy::delPid(getmypid());
            exit();
        }
        else if($status == "reload"){
            easy::killPids(array_keys($childPids));
            easy::updatePid(getmypid(),'run');
            $this->forks();
        }
        else if($status == "run"){
            $killList = array();
            foreach ($childPids as $pid => $pidData) {
                if($pidData['state'] == "stop")
                    $killList[] = $pid;
            }
            easy::killPids($killList);
            if(easy::checkExitPid($exitPid))$this->forkOne();
        }
        else if(strpos("debug+",$status) !== false) {
            $time = time()-substr($status, strpos("debug+", $status));
            $killList = array();
            foreach ($$pids['work'] as $pidData) {
                if($pidData['time']<$time)
                    $killList[] = $pidData['pid'];
            }
            easy::killPids($killList);
            if(easy::checkExitPid($exitPid))$this->forkOne();
            foreach ($$killList as $pid)
                $this->forkOne();
        }
    }
    public function onAccept($socket){
        $new_socket = stream_socket_accept($socket, 0, $remote_address);
        if (!$new_socket)return;
        $this->debugLog("connect socket: ".$new_socket);
        $this->log("remote address: ".$remote_address);
        stream_set_blocking($new_socket,0);

        $tcp = new ezTcp($new_socket,$remote_address);
        $tcp->onMessage = $this->onMessage;
        ezReactorAdd($new_socket, ezReactor::eventRead, array($tcp, 'onRead'));
        if(!empty($this->onConnect))
            call_user_func($this->onConnect,$tcp);
    }
    public function setRunTimeData($data,$file){
        file_put_contents($file,serialize($data));
    }
    public function getRunTimeData($file){
        if(is_file($file))
            return unserialize(file_get_contents($file));
    }
    public function errorHandle($errno, $errstr, $errfile, $errline){
        if($this->checkErrorIgnorePath($errno,$errfile))return;
        $msg = '';
        switch ($errno){
            case E_ERROR:{
                $msg = "easy E_ERROR -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_WARNING:{
                $msg =  "easy E_WARNING -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_PARSE:{
                $msg =  "easy E_PARSE -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_NOTICE:{
                $msg =  "easy E_NOTICE -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_CORE_ERROR:{
                $msg =  "easy E_CORE_ERROR -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_CORE_WARNING:{
                $msg =  "easy E_CORE_WARNING -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_COMPILE_ERROR:{
                $msg =  "easy E_COMPILE_ERROR -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_COMPILE_WARNING:{
                $msg =  "easy E_COMPILE_WARNING -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_USER_ERROR:{
                $msg =  "easy E_USER_ERROR -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_USER_WARNING:{
                $msg =  "easy E_USER_WARNING -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_USER_NOTICE:{
                $msg =  "easy E_USER_NOTICE -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_STRICT:{
                $msg =  "easy E_STRICT -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_RECOVERABLE_ERROR:{
                $msg =  "easy E_RECOVERABLE_ERROR -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_DEPRECATED:{
                $msg =  "easy E_DEPRECATED -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_USER_DEPRECATED:{
                $msg =  "easy E_USER_DEPRECATED -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
            case E_ALL:{
                $msg =  "easy E_ALL -> $errstr ; file -> $errfile ; errline -> $errline ; ";
            }
                break;
        }
        if($this->outScreen)
            echo "$msg<br>";
        else
            $this->log($msg);
    }
}