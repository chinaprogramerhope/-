<?php
/**
 * Created by PhpStorm.
 * User: hjl
 * Date: 18-10-20
 * Time: 上午10:49
 */

error_reporting(E_ALL);
set_time_limit(0);
date_default_timezone_set('Asia/shanghai');

class Server {
    const LOG_PATH = '/tmp/';
    const LISTEN_SOCKET_NUM = 9;

    /**
     * @var array [(int)$socket => []]
     */
    private $sockets = [];
    private $master;

    public function __construct($host, $port) {
        try {
            // 创建服务端socket
            $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

            // 设置ip和端口重用, 在重启服务器后能重新使用此端口
            socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);

            // 绑定ip和端口
            socket_bind($this->master, $host, $port);

            // 监听连接
            socket_listen($this->master, self::LISTEN_SOCKET_NUM);
        } catch (Exception $e) {
            $errCode = socket_last_error();
            $errMsg = socket_strerror($errCode);

            $this->error([
                'err_init_server',
                $errCode,
                $errMsg
            ]);
        }

        $this->sockets[0] = ['resource' => $this->master];
        $pid = posix_getpid();
        $this->debug(["server: {$this->master} started, pid: {$pid}"]);

        while (true) {
            try {
                $this->doServer();
            } catch (Exception $e) {
                $this->error([
                    'err_do_server',
                    $e->getCode(),
                    $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 处理客户端请求消息
     */
    private function doServer() {
        $write = $except = null;
        $sockets = array_column($this->sockets, 'resource'); // 返回数组中指定的一列 (socket组成的数组)

        /*
         * select函数使用传统的select模型, 可读, 写, 异常的socket会被分别放入$socket, $write, $except数组中,
         * 然后返回状态改变的socket的数目, 如果发生了错误, 函数将会返回false.
         * 需要注意的是最后恋歌事件参数, 他们只有单位不同, 可以搭配使用, 用来表示socket_select阻塞的时长,
         * 为0时此函数立即返回, 可以用于轮询机制. 为null时, 函数会一直阻塞下去,
         * 这里我们置$tv_sec为null, 让它一直阻塞, 直到有可操作的socket返回
         */
        $readNum = socket_select($sockets, $write, $except, null);
        if (false == $readNum) {
            $this->error([
                'error_select',
                $errCode = socket_last_error(),
                socket_strerror($errCode)
            ]);
            return;
        }

        foreach ($sockets as $socket) {
            if ($socket == $this->master) { // 可读的是server socket. 处理连接逻辑
                /*
                 * 创建, 绑定, 监听后, socket_accept函数将会接收客户端的socket连接
                 * 一旦有一个连接成功, 将会返回一个新的socket资源用以交互,
                 * 如果是一个多个连接的队列, 只会处理第一个,
                 * 如果没有连接的话, 进程将会被阻塞, 直到连接上
                 * 如果用set_socket_blocking或socket_set_noblock设置了阻塞, 会返回false
                 * 返回资源后, 将会持续等待连接
                 */
                $client = socket_accept($this->master);
                if (false == $client) {
                    $this->error([
                        'err_accept',
                        $errCode = socket_last_error(),
                        socket_strerror($errCode)
                    ]);
                    continue;
                } else {
                    self::connect($client); // 将client socket添加到已连接列表
                    continue;
                }
            } else { // 可读的是client socket. 读取数据, 并处理应答逻辑
                $bytes = @socket_recv($socket, $buffer, 2048, 0);

                /*
                 * 当客户端忽然中断时, 服务器会接收到一个8字节长度的消息,
                 * (由于websocket数据帧机制, 8字节的消息我们认为是客户端异常中断的消息),
                 * 服务端处理客户端下线逻辑, 并将其封装为消息广播出去
                 */
                if ($bytes < 9) { // 处理客户端下线逻辑
                    $recvMsg = $this->disconnect($socket);
                } else {
                    if (!$this->sockets[(int)$socket]['handshake']) { // 如果此客户端还未握手, 执行握手逻辑
                        self::handShake($socket, $buffer); // 服务端同客户端握手
                        continue;
                    } else { // 解析客户端发来的数据
                        $recvMsg = self::parse($buffer);
                    }
                }

                array_unshift($recvMsg, 'receive_msg'); // 在数组开头插入一个或多个单元

                // 将数据组装成websocket数据帧
                $msg = self::dealMsg($socket, $recvMsg);

                // 把客户端信息广播到聊天室
                $this->broadcast($msg);
            }
        }
    }

    /**
     * 将socket添加到已连接列表, 但握手状态留空
     * @param $socket
     */
    private function connect($socket) {
        // 获取socket的ip和端口, 存入ip和port
        socket_getpeername($socket, $ip, $port);
        $socketInfo = [
            'resource' => $socket,
            'uname' => '',
            'hanshake' => false,
            'ip' => $ip,
            'port' => $port,
        ];
        $this->sockets[(int)$socket] = $socketInfo;
        $this->debug(array_merge(['socket_connect'], $socketInfo));
    }

    /**
     * 处理客户端下线, 并返回下线客户端的相关信息
     * @param $socket
     * @return array
     */
    public function disconnect($socket) {
        $recvMsg = [
            'type' => 'logout',
            'content' => $this->sockets[(int)$socket['uname']]
        ];
        unset($this->sockets[(int)$socket]);

        return $recvMsg;
    }

    /**
     * 用公共握手算法握手
     * @param $socket - 服务端socket
     * @param $buffer - 客户端数据
     * @return bool
     */
    public function handShake($socket, $buffer) {
        // 获取到客户端的升级密钥
        $lineWithKey = substr($buffer, strpos($buffer, 'Sec-WebSocket-Key:') + 18);
        $key = trim(substr($lineWithKey, 0, strpos($lineWithKey, "\r\n")));

        // 生成升级密钥, 并拼接websocket升级头
        $upgradeKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)); // 升级key的算法
        $upgradeMsg = "HTTP/1.1 101 Switching Protocols\r\n";
        $upgradeMsg .= "Upgrade: websocket\r\n";
        $upgradeMsg .= "Sec-WebSocket-Version: 13\r\n";
        $upgradeMsg .= "Connection: Upgrade\r\n";
        $upgradeMsg .= "Sec-WebSocket-Accept:" . $upgradeKey . "\r\n\r\n";

        // 向socket写入升级信息
        socket_write($socket, $upgradeMsg, strlen($upgradeMsg));
        $this->sockets[(int)$socket]['handshake'] = true;

        socket_getpeername($socket, $ip, $port); // 获取socket的ip和端口, 存入ip和port
        $this->debug([
            'hand_shake',
            $socket,
            $ip,
            $port
        ]);

        // 向客户端发送握手成功信息, 以触发客户端发送用户名动作
        $msg = [
            'type' => 'handshake',
            'content' => 'done'
        ];
        $msg = $this->build(json_encode($msg)); // 封装数据
        socket_write($socket, $msg, strlen($msg));

        return true;
    }

    /**
     * 解析客户端发来的数据
     * @param $buffer
     * @return mixed
     */
    private function parse($buffer) {
        $decoded = '';
        $len = ord($buffer[1]) & 127; // ord - 返回字符的 ASCII 码值
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }

        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }

        return json_decode($decoded, true);
    }

    /**
     * 拼装信息
     * @param $socket - 客户端socket
     * @param $recvMsg - 客户端发来的数据
     * @return mixed
     */
    private function dealMsg($socket, $recvMsg) {
        $msgType = $recvMsg['type'];
        $msgContent = $recvMsg['content'];
        $response = [];

        switch ($msgType) {
            case 'login':
                $this->sockets[(int)$socket]['uname'] = $msgContent;
                // 取得最新的名字记录
                $userList = array_column($this->sockets, 'uname'); // 返回数组中指定的一列
                $response['type'] = 'login';
                $response['content'] = $msgContent;
                $response['userList'] = $userList;
                break;
            case 'logout':
                $userList = array_column($this->sockets, 'uname');
                $response['type'] = 'logout';
                $response['content'] = $msgContent;
                $response['userList'] = $userList;
                break;
            case 'user':
                $uname = $this->sockets[(int)$socket]['uname'];
                $response['type'] = 'user';
                $response['from'] = $uname;
                $response['content'] = $msgContent;
                break;
        }

        return $this->build(json_encode($response));
    }

    /**
     * 将普通信息组装成websocket数据帧
     * @param $msg
     * @return string
     */
    private function build($msg) {
        $frame = [];
        $frame[0] = '81';
        $len = strlen($msg);
        if ($len < 126) {
            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len); // dechex - 十进制转换为十六进制
        } else if ($len < 65025) {
            $s = dechex($len);
            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
        } else {
            $s = dechex($len);
            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
        }
        $data = '';
        $l = strlen($msg);
        for ($i = 0; $i < $l; $i++) {
            $data .= dechex(ord($msg{$i}));
        }
        $frame[2] = $data;
        $data = implode('', $frame);

        return pack('H*', $data); // pack - 将数据打包成二进制字符串
    }

    /**
     * 广播消息
     * @param $data
     */
    private function broadcast($data) {
        foreach ($this->sockets as $socket) {
            if ($socket['resource'] == $this->master) {
                continue;
            }
            socket_write($socket['resource'], $data, strlen($data));
        }
    }

    /**
     * 记录错误日志
     * @param array $info
     */
    private function error(array $info) {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time); // 在数组开头插入一个或多个单元

        $info = array_map('json_encode', $info); // 为数组的每个元素应用回调函数
        file_put_contents(self::LOG_PATH . 'websocket_error.log',
            implode(' | ', $info) . "\r\n", FILE_APPEND); // implode - 将一个一维数组的值转化为字符串
    }
    /*
     * 记录debug日志
     */
    private function debug(array $info) {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);

        $info = array_map('json_encode', $info);
        file_put_contents(self::LOG_PATH . 'websocket_debug.log',
            implode(' | ', $info) . "\r\n", FILE_APPEND);
    }

    /**
     * 自定义日志
     * @param $content
     */
    private function log($content) {
        $file = self::LOG_PATH . 'websocket.log';
        $timeNow = date('Y-m-d H:i:s');
        $content = $timeNow . ' : ' . $content . "\n";

        file_put_contents($file, $content, FILE_APPEND);
    }
}

$ws = new Server('127.0.0.1', 8080);