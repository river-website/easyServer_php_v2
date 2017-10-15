<?php
/**
 * Created by PhpStorm.
 * User: win10
 * Date: 2017/9/21
 * Time: 18:01
 */

require ROOT.'/system/ezServer.php';
require ROOT.'/library/ezDbPool.php';
//require ROOT.'/library/ezQueueEvent.php';
require ROOT.'/protocol/ezHttp.php';

class ezWebServer {
	private $host = '0.0.0.0:80';
	private $serverRoot = array();
	private $mimeTypeMap = array();
	public function __construct(){
		$server = ezServer();
		$server->onMessage = array($this, 'onMessage');
		$server->protocol = new ezHttp();
		$server->onStart = array($this,'onStart');
        $this->initMimeTypeMap();
	}
	public function setServerData($serverData){
		ezServer()->host = 'tcp://'.$serverData['host'];
		foreach ($serverData['serverRoot'] as $value)
			$this->serverRoot[$value['webSite']] = $value['path'];
	}
	public function onStart(){
		ezDb()->init();
//		ezQueue()->init();
	}
	public function start(){
		ezServer()->start();
	}
	// 处理从tcp来的数据
	public function onMessage($connection,$data){
		// REQUEST_URI.
		$workerman_url_info = parse_url($_SERVER['REQUEST_URI']);
		if (!$workerman_url_info) {
			ezHttp::header('HTTP/1.1 400 Bad Request');
			$connection->close('<h1>400 Bad Request</h1>');
			return;
		}

		$workerman_path = isset($workerman_url_info['path']) ? $workerman_url_info['path'] : '/';

		$workerman_path_info	  = pathinfo($workerman_path);
		$workerman_file_extension = isset($workerman_path_info['extension']) ? $workerman_path_info['extension'] : '';
		if ($workerman_file_extension === '') {
			$workerman_path		   = ($len = strlen($workerman_path)) && $workerman_path[$len - 1] === '/' ? $workerman_path . 'index.php' : $workerman_path . '/index.php';
			$workerman_file_extension = 'php';
		}

		$workerman_root_dir = isset($this->serverRoot[$_SERVER['SERVER_NAME']]) ? $this->serverRoot[$_SERVER['SERVER_NAME']] : current($this->serverRoot);

		$workerman_file = "$workerman_root_dir$workerman_path";
		if ($workerman_file_extension === 'php' && !is_file($workerman_file)) {

			$workerman_file = "$workerman_root_dir/index.php";
			if (!is_file($workerman_file)) {
				$workerman_file		   = "$workerman_root_dir/index.html";
				$workerman_file_extension = 'html';
			}

		}

		// File exsits.
		if (is_file($workerman_file)) {
			// Security check.
			if ((!($workerman_request_realpath = realpath($workerman_file)) || !($workerman_root_dir_realpath = realpath($workerman_root_dir))) || 0 !== strpos($workerman_request_realpath,
					$workerman_root_dir_realpath)
			) {
				ezHttp::header('HTTP/1.1 400 Bad Request');
				$connection->close('<h1>400 Bad Request</h1>');
				return;
			}

			$workerman_file = realpath($workerman_file);
			// Request php file.
			if ($workerman_file_extension === 'php') {
				ezServer()->curConnect = $connection;
				$workerman_cwd = getcwd();
				chdir($workerman_root_dir);
				ini_set('display_errors', 'off');
				$this->outScreen = true;
				ob_start();
				// Try to include php file.
				try {
					// $_SERVER.
					$_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
					$_SERVER['REMOTE_PORT'] = $connection->getRemotePort();
					include $workerman_file;
				} catch (Exception $e) {
					echo $e->getMessage();
				}
				$content = ob_get_clean();
				ini_set('display_errors', 'on');
				if (strtolower($_SERVER['HTTP_CONNECTION']) === "keep-alive") {
					$connection->send($content);
				} else {
					$connection->close($content);
				}
				$this->outScreen = false;
				chdir($workerman_cwd);
				return;
			}
			// Send file to client.
			return $this->sendFile($connection, $workerman_file);
		} else {
			// 404
			ezHttp::header("HTTP/1.1 404 Not Found");
			$connection->close('<html><head><title>404 File not found</title></head><body><center><h3>404 Not Found</h3></center></body></html>');
			return;
		}
	}
    public function initMimeTypeMap()
    {
        $mime_file = ezHttp::getMimeTypesFile();
        if (!is_file($mime_file)) {
            ezLog("$mime_file mime.type file not fond");
            return;
        }
        $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($items)) {
            ezLog("get $mime_file mime.type content fail");
            return;
        }
        foreach ($items as $content) {
            if (preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match)) {
                $mime_type                      = $match[1];
                $workerman_file_extension_var   = $match[2];
                $workerman_file_extension_array = explode(' ', substr($workerman_file_extension_var, 0, -1));
                foreach ($workerman_file_extension_array as $workerman_file_extension) {
                    $this->mimeTypeMap[$workerman_file_extension] = $mime_type;
                }
            }
        }
    }
    public function sendFile($connection, $file_path)
    {
        // Check 304.
        $info = stat($file_path);
        $modified_time = $info ? date('D, d M Y H:i:s', $info['mtime']) . ' ' . date_default_timezone_get() : '';
        if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $info) {
            // Http 304.
            if ($modified_time === $_SERVER['HTTP_IF_MODIFIED_SINCE']) {
                // 304
                ezHttp::header('HTTP/1.1 304 Not Modified');
                // Send nothing but http headers..
                $connection->close('');
                return;
            }
        }
        // Http header.
        if ($modified_time) {
            $modified_time = "Last-Modified: $modified_time\r\n";
        }
        $file_size = filesize($file_path);
        $file_info = pathinfo($file_path);
        $extension = isset($file_info['extension']) ? $file_info['extension'] : '';
        $file_name = isset($file_info['filename']) ? $file_info['filename'] : '';
        $header = "HTTP/1.1 200 OK\r\n";
        if (isset($this->mimeTypeMap[$extension])) {
            $header .= "Content-Type: " . $this->mimeTypeMap[$extension] . "\r\n";
        } else {
            $header .= "Content-Type: application/octet-stream\r\n";
            $header .= "Content-Disposition: attachment; filename=\"$file_name\"\r\n";
        }
        $header .= "Connection: keep-alive\r\n";
        $header .= $modified_time;
        $header .= "Content-Length: $file_size\r\n\r\n";
        $trunk_limit_size = 1024*1024;
        if ($file_size < $trunk_limit_size) {
            return $connection->send($header.file_get_contents($file_path), false);
        }
        $connection->send($header, false);
        // Read file content from disk piece by piece and send to client.
        $connection->fileHandler = fopen($file_path, 'r');
        $do_write = function()use($connection)
        {
            // Send buffer not full.
            while(empty($connection->bufferFull))
            {
                // Read from disk.
                $buffer = fread($connection->fileHandler, 8192);
                // Read eof.
                if($buffer === '' || $buffer === false)
                {
                    return;
                }
                $connection->send($buffer, false);
            }
        };
        // Send buffer full.
        $connection->onBufferFull = function($connection)
        {
            $connection->bufferFull = true;
        };
        // Send buffer drain.
        $connection->onBufferDrain = function($connection)use($do_write)
        {
            $connection->bufferFull = false;
            $do_write();
        };
        $do_write();
    }
}