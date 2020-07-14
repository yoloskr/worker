<?php
/**
 * Created by PhpStorm.
 * User: yoloskr
 * Date: 2020-07-14
 * Time: 17:02
 */

namespace Worker\Connection;


abstract class ConnectionInterface
{

    public $_onMessage = null;

    public $_onClose   = null;

    abstract public function send($send_buffer);
}