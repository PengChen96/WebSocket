<?php
set_time_limit(0);
$host = '192.168.1.107';
$port = '1235'; 

//创建TCP socket
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);	//Resource id #2
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($socket, $host, $port);
//监听端口
socket_listen($socket);
//连接的socket列表 (客户端连接socket)
$socket_list = array($socket);

while(true){
	$changed = $socket_list;
	$write  = NULL;
	$except = NULL;
	socket_select($changed, $write, $except, 0, 10);
	
	if(in_array($socket, $changed)){
		$socket_new = socket_accept($socket); 	//Resource id #3
		//将￥socket_new添加到$socket_list数组
		$socket_list[] = $socket_new;
		//读取数据包
		$header = socket_read($socket_new, 1024); 
		//进行握手
	    perform_handshaking($header, $socket_new, $host, $port);
		$msg = mask(json_encode(array('type'=>'system'.$socket, 'message'=>"message".$socket_new)));
		send_message($msg);
		//搜索$change数组中的$socket并返回键名
		$found_socket = array_search($socket, $changed);
		//销毁$changed数组中指定的元素
        unset($changed[$found_socket]);
	}
	
	//轮询 每个client socket 连接
    foreach ($changed as $changed_socket) {    
        //如果有client数据发送过来
        while(socket_recv($changed_socket, $buf, 1024, 0) >= 1)
        {
            //解码发送过来的数据
            $received_text = unmask($buf); 
            $tst_msg = json_decode($received_text);  
            $alpha = $tst_msg->alpha; 
            $beta = $tst_msg->beta; 
            
            //把消息发送回所有连接的 client 上去
            $msg = mask(json_encode(array('type'=>'usermsg', 'alpha'=>$alpha, 'beta'=>$beta)));
//			send_message($msg);
//			global $socket_list;
		    foreach($socket_list as $changed_socket)
		    {
		        socket_write($changed_socket,$msg,strlen($msg));
		    }
            break 2; 
        }
        
        //检查offline的client
        $buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
        if ($buf === false) { 
            $found_socket = array_search($changed_socket, $socket_list);
            socket_getpeername($changed_socket, $ip);
            unset($socket_list[$found_socket]);
            $msg = mask(json_encode(array('type'=>'system', 'message'=>$ip.' disconnected')));
			send_message($msg);
        }
    }
}
socket_close($socket);
//发送消息的方法
function send_message($msg)
{
    global $socket_list;
    foreach($socket_list as $changed_socket)
    {
        @socket_write($changed_socket,$msg,strlen($msg));
    }
    return true;
}
//编码数据
function mask($text)
{
    $b1 = 0x80 | (0x1 & 0x0f);
    $length = strlen($text);
    
    if($length <= 125)
        $header = pack('CC', $b1, $length);
    elseif($length > 125 && $length < 65536)
        $header = pack('CCn', $b1, 126, $length);
    elseif($length >= 65536)
        $header = pack('CCNN', $b1, 127, $length);
    return $header.$text;
}
//解码数据
function unmask($text) {
    $length = ord($text[1]) & 127;
    if($length == 126) {
        $masks = substr($text, 4, 4);
        $data = substr($text, 8);
    }
    elseif($length == 127) {
        $masks = substr($text, 10, 4);
        $data = substr($text, 14);
    }
    else {
        $masks = substr($text, 2, 4);
        $data = substr($text, 6);
    }
    $text = "";
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i%4];
    }
    return $text;
}
//
//握手的逻辑
function perform_handshaking($receved_header,$client_conn, $host, $port)
{
    $headers = array();
    $lines = preg_split("/\r\n/", $receved_header);	//Array
    foreach($lines as $line)
    {
        $line = chop($line);	//chop() 函数移除字符串右端的空白字符或其他预定义字符。
        if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
        {
            $headers[$matches[1]] = $matches[2];
        }
    }
    $secKey = $headers['Sec-WebSocket-Key'];
    $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
    "Upgrade: websocket\r\n" .
    "Connection: Upgrade\r\n" .
    "WebSocket-Origin: $host\r\n" .
    "WebSocket-Location: ws://$host:$port/demo/shout.php\r\n".
    "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
    socket_write($client_conn,$upgrade,strlen($upgrade));
}
?>