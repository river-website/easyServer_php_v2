<?php
/**
 * Created by PhpStorm.
 * User: lhe
 * Date: 2017/9/24
 * Time: 9:42
 */

if (!function_exists('ezDb')) {
    function ezDb(){
        return ezDbPool::getInterface();
    }
}
if (!function_exists('ezDbExcute')) {
    function ezDbExcute($sql, $func = null,$queEvent = false){
        return ezDb()->excute($sql, $func, $queEvent);
    }
}

class ezDbPool
{
    public $maxAsyncLinks = 10;
    public $dbPoolTime = 1;
    public $connectFunc = null;

    private $syncLink = null;
    private $asyncLinks = array();
    private $linkKeys = array();
    private $freeAsyncLink = array();
    private $sqlList = array();
    private $bakLink = array();

    public function __construct(){
    }

    static public function getInterface(){
        static $dbPool;
        if (empty($dbPool)) {
            $dbPool = new ezDbPool();
        }
        return $dbPool;
    }

    public function init(){
        if ($this->dbPoolTime == 0 || $this->maxAsyncLinks == 0) return;
        ezReactorAdd($this->dbPoolTime, ezReactor::eventTime, array($this, 'loop'));
    }

    // if back queue process need do,should find a better way
    public function bakLinks(){
        $this->bakLink[] = $this->syncLink;
        $this->bakLink[] = $this->asyncLinks;
        $this->bakLink[] = $this->linkKeys;
        $this->bakLink[] = $this->freeAsyncLink;
        $this->bakLink[] = $this->sqlList;

        $this->syncLink = null;
        $this->asyncLinks = null;
        $this->linkKeys = null;
        $this->freeAsyncLink = null;
        $this->sqlList = null;
    }

    private function linkToKey($link){
        return $link->thread_id;
    }

    public function excute($sql, $func = null, $queEvent = false){
        if($func === true)
            return new promise(array($this,'asyncExcute'),$sql);
        else
            return $this->asyncExcute($sql);
    }
    public function asyncExcute($sql, $func = null, $queEvent = false)
    {
        if (!empty($func) || $queEvent) {
            $con = ezServer()->curConnect;
            if (!empty($func)) {
                $con->setDelaySend();
                $con->data['HTTP_CONNECTION'] = $_SERVER['HTTP_CONNECTION'];
            }
            $link = array_shift($this->freeAsyncLink);
            if (empty($link) && count($this->asyncLinks) >= $this->maxAsyncLinks)
                $this->sqlList[] = array($sql, $func, $con);
            else {
                if (empty($link)) {
                    $link = call_user_func($this->connectFunc);
                    $this->asyncLinks[] = $link;
                    ezDebugLog("async link is: " . $this->linkToKey($link));
                }
                $linkKey = $this->linkToKey($link);
                $ret = mysqli_query($link, $sql, MYSQLI_ASYNC);
                $this->linkKeys[$linkKey] = array($func, $con);
            }
        } else {
            if (empty($this->syncLink)) {
                $this->syncLink = call_user_func($this->connectFunc);
                ezDebugLog("sync link is: " . $this->linkToKey($this->syncLink));
            }
            $time = microtime(true);
            $row = mysqli_query($this->syncLink, $sql);
            ezDebugLog('run time:'.((microtime(true)-$time)*1000));
            if (is_object($row)) return $row->fetch_all(MYSQLI_ASSOC);
            else if ($row == true) return true;
            else {
                echo $this->syncLink->error . "\n";
                echo "<br>$sql<br>";
            }
        }
    }

    //  db loop do
    public function loop()
    {
        while (true) {
            if (count($this->asyncLinks) == 0) return;
            $read = $errors = $reject = $this->asyncLinks;
            $re = mysqli_poll($read, $errors, $reject, 0);
            if (false === $re){
                ezLog('mysqli_poll failed');
                return;
            }
            elseif ($re < 1) return;

            foreach ($read as $link) {
                $sql_result = $link->reap_async_query();
                if (is_object($sql_result))
                    $linkData = $sql_result->fetch_all(MYSQLI_ASSOC);
                else
                    ezLog($link->error);
                $linkKey = $this->linkToKey($link);
                $linkInfo = $this->linkKeys[$linkKey];
                $sqlInfo = array_shift($this->sqlList);
                if (empty($sqlInfo)) {
                    $this->freeAsyncLink[] = $link;
                    unset($this->linkKeys[$linkKey]);
                } else {
                    ezDebugLog("do sql que");
                    mysqli_query($link, $sqlInfo[0], MYSQLI_ASYNC);
                    $this->linkKeys[$linkKey] = array($sqlInfo[1], $sqlInfo[2]);
                }
                $func = $linkInfo[0];
                if (empty($func)) continue;
                $socketCon = $linkInfo[1];
				ezDebugLog($socketCon->socket);
                ezDebugLog($linkKey);
                $socketCon->setImmedSend();
				ezServer()->curConnect = $socketCon;
                ob_start();
                try {
                    call_user_func_array($func, array($linkData));
                } catch (Exception $ex) {
					ezLog($ex->getMessage());
					echo $ex->getMessage();
                }
                $contents = ob_get_clean();
                if (strtolower($socketCon->data['HTTP_CONNECTION']) === "keep-alive") {
                    $socketCon->send($contents);
                } else {
                    $socketCon->close($contents);
                }
                ezDebugLog("close");
            }
            return;
        }
    }
}