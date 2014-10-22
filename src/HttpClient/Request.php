<?php

/**
 * This file is part of ReactGuzzleRing.
 *
 ** (c) 2014 Cees-Jan Kiewiet
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WyriHaximus\React\RingPHP\HttpClient;

use GuzzleHttp\Ring\Core;
use GuzzleHttp\Message\MessageFactory;
use React\EventLoop\LoopInterface;
use React\HttpClient\Client as ReactHttpClient;
use React\HttpClient\Request as HttpRequest;
use React\HttpClient\Response as HttpResponse;
use React\Promise\Deferred;
use React\Stream\Stream;

/**
 * Class Request
 *
 * @package WyriHaximus\React\Guzzle\HttpClient
 */
class Request
{
    /**
     * @var ReactHttpClient
     */
    protected $httpClient;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var HttpResponse
     */
    protected $httpResponse;

    /**
     * @var MessageFactory
     */
    protected $messageFactory;

    /**
     * @var string
     */
    protected $buffer = '';

    /**
     * @var \Exception
     */
    protected $error = '';

    /**
     * @var \React\EventLoop\Timer\TimerInterface
     */
    protected $connectionTimer;

    /**
     * @var \React\EventLoop\Timer\TimerInterface
     */
    protected $requestTimer;

    /**
     * @var ProgressInterface
     */
    protected $progress;

    /**
     * @var Deferred
     */
    protected $deferred;

    /**
     * @var array
     */
    protected $request;

    /**
     * @var array
     */
    protected $requestDefaults = [
        'client' => [
            'stream' => false,
            'connect_timeout' => 0,
            'timeout' => 0,
        ],
    ];

    /**
     * @var bool
     */
    protected $connectionTimedOut = false;

    /**
     * @param array $request
     * @param ReactHttpClient $httpClient
     * @param LoopInterface $loop
     * @param ProgressInterface $progress
     */
    public function __construct($request, ReactHttpClient $httpClient, LoopInterface $loop, ProgressInterface $progress = null)
    {
        $this->request = array_replace_recursive($this->requestDefaults, $request);
        //var_export($this->request);die();
        $this->httpClient = $httpClient;
        $this->loop = $loop;
        $this->messageFactory = new MessageFactory();

        if ($progress instanceof ProgressInterface) {
            $this->progress = $progress;
        } else {
            $this->progress = new Progress();
        }
    }

    /**
     * @return \React\Promise\Promise
     */
    public function send()
    {
        $this->deferred = new Deferred();

        $this->loop->futureTick(function () {
            $request = $this->setupRequest();
            $this->setupListeners($request);

            $this->setConnectionTimeout($request);
            $request->end((string)$this->request['body']);
            $this->setRequestTimeout($request);
        });

        return $this->deferred->promise();
    }

    /**
     * @return HttpRequest mixed
     */
    protected function setupRequest()
    {
        $request = $this->request;
        $headers = [];
        foreach ($request['headers'] as $key => $values) {
            $headers[$key] = implode(';', $values);
        }
        return $this->httpClient->request($request['http_method'], $request['url'], $headers);
    }

    /**
     * @param HttpRequest $request
     */
    protected function setupListeners(HttpRequest $request)
    {
        $request->on(
            'headers-written',
            function () {
                $this->onHeadersWritten();
            }
        );
        $request->on(
            'response',
            function (HttpResponse $response) {
                $this->onResponse($response);
            }
        );
        $request->on(
            'error',
            function ($error) {
                $this->onError($error);
            }
        );
        $request->on(
            'end',
            function () {
                $this->onEnd();
            }
        );
    }

    /**
     * @param HttpRequest $request
     */
    public function setConnectionTimeout(HttpRequest $request)
    {
        if ($this->request['client']['connect_timeout'] > 0) {
            $this->connectionTimer = $this->loop->addTimer($this->request['client']['connect_timeout'], function () use ($request) {
                $request->closeError(new \Exception('Connection time out'));
            });
        }
    }

    /**
     * @param HttpRequest $request
     */
    public function setRequestTimeout(HttpRequest $request)
    {
        if ($this->request['client']['timeout'] > 0) {
            $this->requestTimer = $this->loop->addTimer($this->request['client']['timeout'], function () use ($request) {
                $request->close(new \Exception('Transaction time out'));
            });
        }
    }

    protected function onHeadersWritten()
    {
        if ($this->connectionTimer !== null) {
            $this->loop->cancelTimer($this->connectionTimer);
        }
    }

    /**
     * @param HttpResponse $response
     */
    protected function onResponse(HttpResponse $response)
    {
        if (!empty($this->request['client']['save_to'])) {
            $this->saveTo($response);
        } else {
            $response->on(
                'data',
                function ($data) use ($response) {
                    $this->onData($data);
                }
            );
        }

        $this->deferred->progress($this->progress->setEvent('response')->onResponse($response));

        $this->httpResponse = $response;
    }

    /**
     * @param HttpResponse $response
     */
    protected function saveTo(HttpResponse $response)
    {
        $saveTo = $this->request['client']['save_to'];

        $writeStream = fopen($saveTo, 'w');
        stream_set_blocking($writeStream, 0);
        $saveToStream = new Stream($writeStream, $this->loop);

        $saveToStream->on(
            'end',
            function () {
                $this->onEnd();
            }
        );

        $response->pipe($saveToStream);
    }

    /**
     * @param string $data
     * @todo implement proper streaming
     */
    protected function onData($data)
    {
        if (!$this->request['client']['stream']) {
            $this->buffer .= $data;
        }

        $this->deferred->progress($this->progress->setEvent('data')->onData($data));
    }

    /**
     * @param \Exception $error
     */
    protected function onError(\Exception $error)
    {
        $this->error = $error;
    }

    /**
     *
     */
    protected function onEnd()
    {
        if ($this->requestTimer !== null) {
            $this->loop->cancelTimer($this->requestTimer);
        }

        $this->loop->futureTick(function () {
            if ($this->httpResponse === null) {
                $this->deferred->reject($this->error);
            } else {
                $this->handleResponse();
            }
        });
    }

    /**
     *
     */
    protected function handleResponse()
    {
        $headers = $this->httpResponse->getHeaders();
        if (isset($headers['location'])) {
            $this->followRedirect($headers['location']);
            return;
        }

        $response = [
            'effective_url' => $this->request['url'],
            'body' => $this->buffer,
            'headers' => $this->httpResponse->getHeaders(),
            'status' => $this->httpResponse->getCode(),
            'reason' => $this->httpResponse->getReasonPhrase(),
        ];

        Core::rewindBody($response);
        $this->deferred->resolve($response);
    }

    /**
     * @param string $location
     */
    protected function followRedirect($location)
    {
        $request = $this->request;
        $request['client']['redirect']['max']--;
        if ($request['client']['redirect']['max'] <= 0) {
            $this->deferred->reject();
            return;
        }
        $request['url'] = $location;
        (new Request($request, $this->httpClient, $this->loop))->send()->then(function($response) {
            $this->deferred->resolve($response);
        });
    }
}