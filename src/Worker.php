<?php
/**
 * Created by PhpStorm.
 * User: yoloskr
 * Date: 2020-07-14
 * Time: 17:05
 */

namespace Worker;


use Worker\Connection\TcpConnection;

class Worker {

    /**
     * 读取最大字节
     */
    const READ_BUFFER_SIZE = 65535;


    /**
     * 主机
     * @var bool|string
     */
    private $host;

    /**
     * 端口
     * @var
     */
    private $port;

    /**
     * 有客户端连接时的回调函数
     * @var
     */
    public $onConnect;

    /**
     * 客户端发送数据的回调函数
     * @var
     */
    public $onMessage;

    /**
     * 客户端断开连接的回调函数
     * @var
     */
    public $onClose;

    /**
     * 应用协议
     * @var
     */
    protected $protocol;

    /**
     * scheme
     * @var
     */
    protected $scheme;

    /**
     * 目前支持的传输协议
     * @var array
     */
    protected static $_builtinTransports = [
        'tcp'   => 'tcp'
    ];

    /**
     * 存储客户端连接
     *
     * @var array
     */
    public $connections = [];

    /**
     * Worker constructor.
     * @param $socket_name
     */
    public function __construct($socket_name) {

        list($scheme, $address, $port) = explode(":", $socket_name, 3);
        $this->scheme = $scheme;
        if (!isset(static::$_builtinTransports[$scheme])) {
            $this->protocol = $scheme;
        }
        $this->host = substr($address,2);
        $this->port = $port;
    }


    /**
     * 启动运行
     */
    public function run() {
        //创建socket服务,开启监听
        $server = socket_create(AF_INET, SOCK_STREAM, 0);
        socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($server, $this->host, $this->port);
        socket_listen($server);
        socket_set_nonblock($server);
        //使用select模型开始处理请求
        $this->select($server);
    }

    /**
     * @param $server
     */
    public function select($server) {
        $clients = [];
        while (true) {
            $read = [$server];
            foreach ($clients as $client) $read[] = $client;
            $write = $except = null;
            $num = socket_select($read, $write, $except, null);
            while ($num-- >0) {
                //如果是服务端有事件，应该是客户端有连接
                if (in_array($server, $read)) {
                    //接收连接
                    $client = socket_accept($server);
                    socket_set_nonblock($client);
                    //处理连接事件
                    if ($this->onConnect) {
                        $connect = $this->acceptConnection($client);
                        call_user_func($this->onConnect, $connect);
                    }
                    //存储客户端连接
                    $index = intval($client);
                    $clients[$index] = $client;
                }

                //处理客户端事件
                foreach ($clients as $index=> $client) {
                    //判断哪个客户端有事件
                    if (in_array($client, $read)) {
                        $index = intval($client);
                        //读取客户端数据
                        if (($content = socket_read($client, self::READ_BUFFER_SIZE)) === "") {
                            //客户端关闭，处理客户端关闭事件
                            if ($this->onClose) {
                                call_user_func([$this->connections[$index], "onClose"], $this->connections[$index]);
                            }
                            //删除客户端存储
                            unset($clients[$index]);
                            //关闭客户端连接
                            socket_close($client);
                        } else {
                            //处理客户端发送数据事件
                            if ($this->onMessage) {
                                $data = call_user_func([$this->connections[$index], "onMessage"], $content);
                                socket_write($client, $data);
                            }
                        }
                    }
                }

            }
        }

    }

    /**
     * @param $socket
     * @return TcpConnection
     */
    public function acceptConnection($socket) {
        $id = intval($socket);
        $connection             = new TcpConnection($socket);
        $this->connections[$id] = $connection;
        $connection->_onMessage = $this->onMessage;
        $connection->_onClose   = $this->onClose;
        $connection->protocol   = $this->protocol;
        return $connection;
    }
}