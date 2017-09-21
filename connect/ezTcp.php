<?php
/**
 * Created by PhpStorm.
 * User: win10
 * Date: 2017/9/21
 * Time: 13:15
 */

class ezTcp{
	public $onMessage 			= null;
	public $onClose				= null;

	public $socket 				= null;
	private $remote_address 	= null;
	private $sendBuffer 		= null;
	private $sendStatus 		= true;
	public $data				= null;

	public function __construct($socket,$remote_address){
		$this->socket = $socket;
		$this->remote_address = $remote_address;
	}
	public function getRemoteIp(){
		$pos = strrpos($this->remote_address, ':');
		if ($pos) return trim(substr($this->remote_address, 0, $pos), '[]');
		return '';
	}
	public function getRemotePort(){
		if ($this->remote_address) return (int)substr(strrchr($this->remote_address, ':'), 1);
		return 0;
	}
	// 回调处理读准备好事件
	public function onRead($socket){
		$buffer = fread($socket, 65535);
		// Check connection closed.
		if ($buffer === '' || $buffer === false || !is_resource($socket)) {
			$this->destroy();
			return;
		}
		if(ezServer()->protocol) $buffer = ezServer()->protocol->decode($buffer,$this);
		if($this->onMessage) {
			try{
				call_user_func_array($this->onMessage, array($this, $buffer));
			}catch (Exception $ex){
				ezLog($ex->getMessage());
			}
		}
	}
	public function setDelaySend(){
		$this->sendStatus = false;
	}
	public function setImmedSend(){
		$this->sendStatus = true;
	}
	// 回调处理写准备好事件
	public function onWrite($socket){
		if(!empty($this->sendBuffer)){
			$data = $this->sendBuffer;
			$this->sendBuffer = '';
			$len = fwrite($socket,$data,8192);
			if($len <= 0){
				if (!is_resource($this->socket) || feof($this->socket)) $this->destroy();
				else $this->sendBuffer = $data;
				return;
			}else if($len != strlen($data)) {
				$this->sendBuffer = substr($data, $len);
				return;
			}
		}
		ezReactorDel($this->socket,ezReactor::eventWrite);
	}
	// 发送数据
	public function send($data){
		if(!$this->sendStatus){
			$this->sendBuffer .= $data;
			return;
		}
		$data = $this->sendBuffer.$data;
		if(ezServer()->protocol)$data = ezServer()->protocol->encode($data,$this);
		$len = fwrite($this->socket,$data,8192);
		if($len == strlen($data)) {
			$this->sendBuffer = '';
			return true;
		}
		else if($len>0) $this->sendBuffer = substr($data, $len);
		else{
			if (!is_resource($this->socket) || feof($this->socket)) {
				$this->destroy();
				return false;
			}
			$this->sendBuffer = $data;
		}
		ezReactorAdd($this->socket,ezReactor::eventWrite,array($this,'onWrite'));
		return true;
	}
	//关闭当前连接
	public function close($data = null, $raw = false){
		if ($data !== null) $this->send($data);
		if ($this->sendBuffer === '') $this->destroy();
	}
	// 析构当前连接
	public function destroy()
	{
		// Remove event listener.
		ezReactorDel($this->socket, ezReactor::eventRead);
		ezReactorDel($this->socket, ezReactor::eventWrite);
		if($this->onClose){
			try{
				call_user_func_array($this->onClose,array($this));
			}catch (Exception $ex){
				ezLog($ex->getMessage());
			}
		}
		// Close socket.
		fclose($this->socket);
		ezDebugLog("destroy socket: ".$this->socket);
	}
}