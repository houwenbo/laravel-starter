<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderBag;

define('LARAVEL_START', microtime(true));
define('TEXT_REG', '#\.html.*|\.js.*|\.css.*|\.html.*#');
define('BINARY_REG', '#\.ttf.*|\.woff.*|\.woff2.*|\.gif.*|\.jpg.*|\.png.*|\.jepg.*|\.swf.*|\.bmp.*|\.ico.*#');

/**
 * 静态文件处理
 */
function handlerStatic($path, $isBase64Encoded)
{
    $filename = __DIR__ . "/public" . $path;
    if (!file_exists($filename)) {
        return [
            "isBase64Encoded" => false,
            "statusCode" => 404,
            "headers" => [
                'Content-Type' => '',
            ],
            "body" => "404 Not Found",
        ];
    }
    $handle = fopen($filename, "r");
    $contents = fread($handle, filesize($filename));
    fclose($handle);

    $base64Encode = false;
    $headers = [
        'Content-Type' => '',
        'Cache-Control' => "max-age=8640000",
        'Accept-Ranges' => 'bytes',
    ];
    $body = $contents;
    if ($isBase64Encoded || preg_match(BINARY_REG, $path)) {
        $base64Encode = true;
        $headers = [
            'Content-Type' => '',
            'Cache-Control' => "max-age=86400",
        ];
        $body = base64_encode($contents);
    }
    return [
        "isBase64Encoded" => $base64Encode,
        "statusCode" => 200,
        "headers" => $headers,
        "body" => $body,
    ];
}

function initEnvironment($isBase64Encoded)
{
    $envName = '';
    if (file_exists(__DIR__ . "/.env")) {
        $envName = '.env';
    } elseif (file_exists(__DIR__ . "/.env.production")) {
        $envName = '.env.production';
    } elseif (file_exists(__DIR__ . "/.env.local")) {
        $envName = ".env.local";
    }
    if (!$envName) {
        return [
            'isBase64Encoded' => $isBase64Encoded,
            'statusCode' => 500,
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => $isBase64Encoded ? base64_encode([
                'error' => "Dotenv config file not exist"
            ]) : [
                'error' => "Dotenv config file not exist"
            ]
        ];
    }

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__, $envName);
    $dotenv->load();
}

function decodeFormData($rawData)
{
    $files = array();
    $data = array();
    $boundary = substr($rawData, 0, strpos($rawData, "\r\n"));

    $parts = array_slice(explode($boundary, $rawData), 1);
    foreach ($parts as $part) {
        if ($part == "--\r\n") {
            break;
        }

        $part = ltrim($part, "\r\n");
        list($rawHeaders, $content) = explode("\r\n\r\n", $part, 2);
        $content = substr($content, 0, strlen($content) - 2);
        // 获取请求头信息
        $rawHeaders = explode("\r\n", $rawHeaders);
        $headers = array();
        foreach ($rawHeaders as $header) {
            list($name, $value) = explode(':', $header);
            $headers[strtolower($name)] = ltrim($value, ' ');
        }

        if (isset($headers['content-disposition'])) {
            $filename = null;
            preg_match('/^form-data; *name="([^"]+)"(; *filename="([^"]+)")?/', $headers['content-disposition'], $matches);
            $fieldName = $matches[1];
            $fileName = (isset($matches[3]) ? $matches[3] : null);

            // If we have a file, save it. Otherwise, save the data.
            if ($fileName !== null) {
                $localFileName = tempnam('/tmp', 'sls');
                file_put_contents($localFileName, $content);

                $arr = array(
                    'name' => $fileName,
                    'type' => $headers['content-type'],
                    'tmp_name' => $localFileName,
                    'error' => 0,
                    'size' => filesize($localFileName)
                );

                if (substr($fieldName, -2, 2) == '[]') {
                    $fieldName = substr($fieldName, 0, strlen($fieldName) - 2);
                }

                if (array_key_exists($fieldName, $files)) {
                    array_push($files[$fieldName], $arr);
                } else {
                    $files[$fieldName] = $arr;
                }

                // register a shutdown function to cleanup the temporary file
                register_shutdown_function(function () use ($localFileName) {
                    unlink($localFileName);
                });
            } else {
                parse_str($fieldName . '=__INPUT__', $parsedInput);
                $dottedInput = arrayDot($parsedInput);
                $targetInput = arrayAdd([], array_keys($dottedInput)[0], $content);

                $data = array_merge_recursive($data, $targetInput);
            }
        }
    }
    return (object)([
        'data' => $data,
        'files' => $files
    ]);
}

function arrayGet($array, $key, $default = null)
{
    if (is_null($key)) {
        return $array;
    }

    if (array_key_exists($key, $array)) {
        return $array[$key];
    }

    if (strpos($key, '.') === false) {
        return $array[$key] ?? value($default);
    }

    foreach (explode('.', $key) as $segment) {
        $array = $array[$segment];
    }

    return $array;
}

function arrayAdd($array, $key, $value)
{
    if (is_null(arrayGet($array, $key))) {
        arraySet($array, $key, $value);
    }

    return $array;
}

function arraySet(&$array, $key, $value)
{
    if (is_null($key)) {
        return $array = $value;
    }

    $keys = explode('.', $key);

    foreach ($keys as $i => $key) {
        if (count($keys) === 1) {
            break;
        }

        unset($keys[$i]);

        if (!isset($array[$key]) || !is_array($array[$key])) {
            $array[$key] = [];
        }

        $array = &$array[$key];
    }

    $array[array_shift($keys)] = $value;

    return $array;
}

function arrayDot($array, $prepend = '')
{
    $results = [];

    foreach ($array as $key => $value) {
        if (is_array($value) && !empty($value)) {
            $results = array_merge($results, dot($value, $prepend . $key . '.'));
        } else {
            $results[$prepend . $key] = $value;
        }
    }

    return $results;
}

function getHeadersContentType($headers)
{
    if (isset($headers['Content-Type'])) {
        return $headers['Content-Type'];
    } else if (isset($headers['content-type'])) {
        return $headers['content-type'];
    }
    return '';
}

function handler($event, $context)
{
    require __DIR__ . '/vendor/autoload.php';

    $isBase64Encoded = $event->isBase64Encoded;


    initEnvironment($isBase64Encoded);

    $app = require __DIR__ . '/bootstrap/app.php';

    // change storage path to APP_STORAGE in dotenv
    $app->useStoragePath(env('APP_STORAGE', base_path() . '/storage'));


    // 获取请求路径
    $path = str_replace("//", "/", $event->path);

    if (preg_match(TEXT_REG, $path) || preg_match(BINARY_REG, $path)) {
        return handlerStatic($path, $isBase64Encoded);
    }

    // 处理请求头
    $headers = $event->headers ?? [];
    $headers = json_decode(json_encode($headers), true);

    // 处理请求数据
    $data = [];
    $rawBody = $event->body ?? null;
    if ($event->httpMethod === 'GET') {
        $data = !empty($event->queryString) ? $event->queryString : [];
    } else {
        if ($isBase64Encoded) {
            $rawBody = base64_decode($rawBody);
        }
        $contentType = getHeadersContentType($headers);
        if (preg_match('/multipart\/form-data/', $contentType)) {
            $requestData = !empty($rawBody) ? decodeFormData($rawBody) : [];
            $data = $requestData->data;
            $files = $requestData->files;
        } else if (preg_match('/application\/x-www-form-urlencoded/', $contentType)) {
            if (!empty($rawBody)) {
                mb_parse_str($rawBody, $data);
            }
        } else {
            $data = !empty($rawBody) ? json_decode($rawBody, true) : [];
        }
    }

    // 将请求交给 laravel 处理
    $kernel = $app->make(Kernel::class);

    var_dump($path, $event->httpMethod);

    $request = Request::create($path, $event->httpMethod, (array)$data, [], [], $headers, $rawBody);
    $request->headers = new HeaderBag($headers);
    if (!empty($files)) {
        $request->files->add($files);
    }


    $response = $kernel->handle($request);

    // 处理返回内容
    $body = $response->getContent();
    $headers = $response->headers->all();
    $response_headers = [];
    foreach ($headers as $k => $header) {
        if (is_string($header)) {
            $response_headers[$k] = $header;
        } elseif (is_array($header)) {
            $response_headers[$k] = implode(';', $header);
        }
    }

    return [
        'isBase64Encoded' => $isBase64Encoded,
        'statusCode' => $response->getStatusCode() ?? 200,
        'headers' => $response_headers,
        'body' => $isBase64Encoded ? base64_encode($body) : $body
    ];
}
