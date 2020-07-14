## 简单使用

```php
<?php
use Worker\Worker;

$worker = new Worker("http://127.0.0.1:9999");

$worker->onConnect = function($connection)
{
    echo "new client connection\n";
};

$worker->onMessage = function($connection, $data)
{
    $connection->send($data);
};

$worker->onClose = function($connection)
{
    echo "client close\n";
};

$worker->run();
```