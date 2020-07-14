<?php
/**
 * Created by PhpStorm.
 * User: jiaowenlong
 * Date: 2020-07-14
 * Time: 17:04
 */

namespace Worker\Protocols;


use Worker\Connection\ConnectionInterface;
use Worker\ProtocolInterface;
use Worker\Connection\TcpConnection;

class Http implements ProtocolInterface
{

    public static $methods = array('GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS');

    /**
     * Check the integrity of the package.
     *
     * @param string        $recv_buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input($recv_buffer,ConnectionInterface $connection)
    {
        if (!strpos($recv_buffer, "\r\n\r\n")) {
            return 0;
        }

        list($header,) = explode("\r\n\r\n", $recv_buffer, 2);
        $method = substr($header, 0, strpos($header, ' '));

        if(in_array($method, static::$methods)) {
            return static::getRequestSize($header, $method);
        }else{
            echo "HTTP/1.1 400 Bad Request\r\n\r\n";
            return 0;
        }
    }

    /**
     * Get whole size of the request
     * includes the request headers and request body.
     * @param string $header The request headers
     * @param string $method The request method
     * @return integer
     */
    protected static function getRequestSize($header, $method)
    {
        if($method === 'GET' || $method === 'OPTIONS' || $method === 'HEAD') {
            return strlen($header) + 4;
        }
        $match = array();
        if (preg_match("/\r\nContent-Length: ?(\d+)/i", $header, $match)) {
            $content_length = isset($match[1]) ? $match[1] : 0;
            return $content_length + strlen($header) + 4;
        }
        return $method === 'DELETE' ? strlen($header) + 4 : 0;
    }

    /**
     * Parse $_POST、$_GET、$_COOKIE.
     *
     * @param string        $recv_buffer
     * @param TcpConnection $connection
     * @return array
     */
    public static function decode($recv_buffer, ConnectionInterface $connection)
    {
        $_POST                         = $_GET = $_COOKIE = $_REQUEST = $_SESSION = $_FILES = [];
        $GLOBALS['HTTP_RAW_POST_DATA'] = '';

        // $_SERVER
        $_SERVER = [
            'QUERY_STRING'         => '',
            'REQUEST_METHOD'       => '',
            'REQUEST_URI'          => '',
            'SERVER_PROTOCOL'      => '',
            'SERVER_SOFTWARE'      => 'yolo/1.0',
            'SERVER_NAME'          => '',
            'HTTP_HOST'            => '',
            'HTTP_USER_AGENT'      => '',
            'HTTP_ACCEPT'          => '',
            'HTTP_ACCEPT_LANGUAGE' => '',
            'HTTP_ACCEPT_ENCODING' => '',
            'HTTP_COOKIE'          => '',
            'HTTP_CONNECTION'      => '',
            'CONTENT_TYPE'         => '',
            'REMOTE_ADDR'          => '',
            'REMOTE_PORT'          => '0',
            'REQUEST_TIME'         => time()
        ];

        // Parse headers.
        list($http_header, $http_body) = explode("\r\n\r\n", $recv_buffer, 2);
        $header_data = explode("\r\n", $http_header);

        list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = explode(' ',
            $header_data[0]);

        $http_post_boundary = '';
        unset($header_data[0]);
        foreach ($header_data as $content) {
            // \r\n\r\n
            if (empty($content)) {
                continue;
            }
            list($key, $value)       = explode(':', $content, 2);
            $key                     = str_replace('-', '_', strtoupper($key));
            $value                   = trim($value);
            $_SERVER['HTTP_' . $key] = $value;
            switch ($key) {
                // HTTP_HOST
                case 'HOST':
                    $tmp                    = explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if (isset($tmp[1])) {
                        $_SERVER['SERVER_PORT'] = $tmp[1];
                    }
                    break;
                // cookie
                case 'COOKIE':
                    parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                    break;
                // content-type
                case 'CONTENT_TYPE':
                    if (!preg_match('/boundary="?(\S+)"?/', $value, $match)) {
                        if ($pos = strpos($value, ';')) {
                            $_SERVER['CONTENT_TYPE'] = substr($value, 0, $pos);
                        } else {
                            $_SERVER['CONTENT_TYPE'] = $value;
                        }
                    } else {
                        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
                        $http_post_boundary      = '--' . $match[1];
                    }
                    break;
                case 'CONTENT_LENGTH':
                    $_SERVER['CONTENT_LENGTH'] = $value;
                    break;
            }
        }
        // Parse $_POST.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_SERVER['CONTENT_TYPE'])) {
                switch ($_SERVER['CONTENT_TYPE']) {
                    case 'multipart/form-data':
                        self::parseUploadFiles($http_body, $http_post_boundary);
                        break;
                    case 'application/json':
                        $_POST = json_decode($http_body, true);
                        break;
                    case 'application/x-www-form-urlencoded':
                        parse_str($http_body, $_POST);
                        break;
                }
            }
        }

        // Parse other HTTP action parameters
        if ($_SERVER['REQUEST_METHOD'] != 'GET' && $_SERVER['REQUEST_METHOD'] != "POST") {
            $data = array();
            if ($_SERVER['CONTENT_TYPE'] === "application/x-www-form-urlencoded") {
                parse_str($http_body, $data);
            } elseif ($_SERVER['CONTENT_TYPE'] === "application/json") {
                $data = json_decode($http_body, true);
            }
            $_REQUEST = array_merge($_REQUEST, $data);
        }

        // HTTP_RAW_REQUEST_DATA HTTP_RAW_POST_DATA
        $GLOBALS['HTTP_RAW_REQUEST_DATA'] = $GLOBALS['HTTP_RAW_POST_DATA'] = $http_body;

        // QUERY_STRING
        $_SERVER['QUERY_STRING'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        if ($_SERVER['QUERY_STRING']) {
            // $GET
            parse_str($_SERVER['QUERY_STRING'], $_GET);
        } else {
            $_SERVER['QUERY_STRING'] = '';
        }

        if (is_array($_POST)) {
            // REQUEST
            $_REQUEST = array_merge($_GET, $_POST, $_REQUEST);
        } else {
            // REQUEST
            $_REQUEST = array_merge($_GET, $_REQUEST);
        }


        return array('get' => $_GET, 'post' => $_POST, 'cookie' => $_COOKIE, 'server' => $_SERVER, 'files' => $_FILES);
    }

    public static function encode($content, ConnectionInterface $connection)
    {

        $header = "HTTP/1.1 200 OK\r\n";

        $header .= "Content-Type: text/html;charset=utf-8\r\n";

        // header
        $header .= "Server: yolo/1.0\r\nContent-Length: " . strlen($content) . "\r\n\r\n";


        // the whole http package
        return $header . $content;
    }

    /**
     * Parse $_FILES.
     *
     * @param string $http_body
     * @param string $http_post_boundary
     * @return void
     */
    protected static function parseUploadFiles($http_body, $http_post_boundary)
    {
        $http_body           = substr($http_body, 0, strlen($http_body) - (strlen($http_post_boundary) + 4));
        $boundary_data_array = explode($http_post_boundary . "\r\n", $http_body);
        if ($boundary_data_array[0] === '') {
            unset($boundary_data_array[0]);
        }
        $key = -1;
        foreach ($boundary_data_array as $boundary_data_buffer) {
            list($boundary_header_buffer, $boundary_value) = explode("\r\n\r\n", $boundary_data_buffer, 2);
            // Remove \r\n from the end of buffer.
            $boundary_value = substr($boundary_value, 0, -2);
            $key ++;
            foreach (explode("\r\n", $boundary_header_buffer) as $item) {
                list($header_key, $header_value) = explode(": ", $item);
                $header_key = strtolower($header_key);
                switch ($header_key) {
                    case "content-disposition":
                        // Is file data.
                        if (preg_match('/name="(.*?)"; filename="(.*?)"$/', $header_value, $match)) {
                            // Parse $_FILES.
                            $_FILES[$key] = array(
                                'name' => $match[1],
                                'file_name' => $match[2],
                                'file_data' => $boundary_value,
                                'file_size' => strlen($boundary_value),
                            );
                            break;
                        } // Is post field.
                        else {
                            // Parse $_POST.
                            if (preg_match('/name="(.*?)"$/', $header_value, $match)) {
                                $_POST[$match[1]] = $boundary_value;
                            }
                        }
                        break;
                    case "content-type":
                        // add file_type
                        $_FILES[$key]['file_type'] = trim($header_value);
                        break;
                }
            }
        }
    }
}