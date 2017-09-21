<?php
/**
 * Created by PhpStorm.
 * User: win10
 * Date: 2017/7/20
 * Time: 10:04
 */
class ezReactorLibEvent{

	private $base 			= null;
	private $allEvent 		= array();

	public function __construct(){
		$this->base = event_base_new();
	}
	// 增加一个监视资源，状态及事件处理
	public function add($fd, $status, $func, $arg = null){
		switch ($status){
            case ezReactor::eventTimeOnce:
            case ezReactor::eventTime:
            case ezReactor::eventClock:{
                if(ezReactor::eventClock == $status) {
                    // $fd 如 03:15:30,即每天3:15:30执行
                    $time = strtotime($fd);
                    $now = time();
                    if ($now >= $time)$time = strtotime('+1 day', $time);
                    $time = ($time - $now) * 1000;
                }
                else $time = $fd * 1000;

				$event = event_new();
				if (!event_set($event, 0, EV_TIMEOUT,array($this,'onTime'), array($event,$fd,$status,$func,$arg))) return false;
				if (!event_base_set($event, $this->base)) return false;
				if (!event_add($event, $time)) return false;
				$this->allEvent[(int)$event][$status] = $event;
				return (int)$event;
			}
				break;
			case ezReactor::eventSignal: {
				$event = event_new();
				if (!event_set($event, $fd, $status | EV_PERSIST, $func, array($arg))) return false;
				if (!event_base_set($event, $this->base)) return false;
				if (!event_add($event)) return false;
				$this->allEvent[(int)$fd][$status] = $event;
			}
				break;
			case ezReactor::eventRead:
			case ezReactor::eventWrite: {
				$event = event_new();
				if (!event_set($event, $fd, $status | EV_PERSIST, $func, array($arg))) return false;
				if (!event_base_set($event, $this->base)) return false;
				if (!event_add($event)) return false;
				$this->allEvent[(int)$fd][$status] = $event;
			}
				break;
			default:
				break;
		}
		return true;
	}
	// 删除一个监视资源，状态及事件处理
	public function del($fd, $status){
		if(!empty($this->allEvent[(int)$fd][$status])) {
			$ev = $this->allEvent[(int)$fd][$status];
			event_del($ev);
			unset($this->allEvent[(int)$fd][$status]);
		}
	}
	// 开始监视资源
	public function loop(){
		event_base_loop($this->base);
	}
	public function onTime($fd,$type,$args){
        if(count($args) != 5)return;
        $event  = $args[0];
        $fd     = $args[1];
        $status = $args[2];
        $func   = $args[3];
        $arg    = $args[4];
        if($status != ezReactor::eventTimeOnce) {
            if ($status == ezReactor::eventClock) {
                // $fd 如 03:15:30,即每天3:15:30执行
                $time = strtotime($fd);
                $now = time();
                $time = strtotime('+1 day', $time);
                $time = ($time - $now) * 1000;
            }
            else $time = $fd * 1000;
            event_add($event,$time);
        }
        try{
           call_user_func($func,$arg);
        }catch (Exception $ex){
            ezLog($ex->getMessage());
        }
    }
}