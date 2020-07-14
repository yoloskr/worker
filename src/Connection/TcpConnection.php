<?php
/**
 * Created by PhpStorm.
 * User: yoloskr
 * Date: 2020-07-14
 * Time: 17:03
 */

namespace Worker\Connection;

use Worker\Protocols\Http;

class TcpConnection extends ConnectionInterface
{

    /**
     * Socket
     *
     * @var resource
     */
    protected $_socket = null;

    /**
     * Protocol
     * @var null
     */
    public $protocol = null;


    /**
     * TcpConnection constructor.
     * @param $socket
     */
    public function __construct($socket)
    {
        $this->_socket = $socket;
    }


    /**
     * Send data
     * @param $send_buffer
     * @return null
     */
    public function send($send_buffer)
    {
        if (is_array($send_buffer)) {
            $send_buffer = json_encode($send_buffer);
        }
        if ($this->protocol !== null) {
            $send_buffer = Http::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return null;
            }
        }
        socket_write($this->_socket, $send_buffer);
    }

    /**
     * @param null $data
     */
    public function onClose($data = null)
    {
        call_user_func($this->_onClose, $this);
    }

    /**
     * @param $data
     * @return |null
     */
    public function onMessage($data)
    {
        $read_buffer = $data;
        if ($this->protocol !== null) {
            $read_buffer = Http::decode($data, $this);
            if ($read_buffer === '') {
                return null;
            }
        }
        call_user_func($this->_onMessage, $this, $read_buffer);
    }
}