<?php
/**
 * Created by PhpStorm.
 * User: win10
 * Date: 2017/10/27
 * Time: 13:57
 */
class ezWebSocket{
    public static function input($buffer,ezTcp $connect){
        if(strlen($buffer)<2)return 0;
        $len = ord($buffer[1]) & 127;
        if($len == 127)$len = substr($buffer,2,8)+14;
        else if($len == 126)$len = substr($buffer,2,2)+8;
        else $len += 6;
        return $len;
    }
    // $buffer是一个完整的报文，解析获取数据
    public static function decode($buffer,ezTcp $connect){
        $maks = $data = $len = null;
        $len = ord($buffer[1]) & 127;
        if($len == 127){
            $maks = substr($buffer,10,4);
            $data = substr($buffer,14);
        }else if($len == 126){
            $maks = substr($buffer,4,4);
            $data = substr($buffer,8);
        }else{
            $maks = substr($buffer,2,4);
            $data = substr($buffer,6);
        }
        for ($i=0;$i<strlen($data);$i++) $data[$i] = $data[$i]^$maks[$i % 4];
        return $data;
    }
    public function encode($buffer,$connect){
        $first = "\x81";
        $len = strlen($buffer);
        $data = $first;
        if($len < 126)$data .= chr($len).$buffer;
        else if($len <= 65535)$data.= chr(126).pack('n',$len).$buffer;
        else $data.=chr(127).pack('J',$len).$buffer;
        return $data;
    }




}