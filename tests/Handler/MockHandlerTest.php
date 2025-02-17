<?php

namespace Zoidberg\GuzzleHttp\Test\Handler;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\MockHandler
 */
class MockHandlerTest extends TestCase
{
    public function testReturnsMockResponse()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, []);
        self::assertSame($res, $p->wait());
    }

    public function testIsCountable()
    {
        $res = new Response();
        $mock = new MockHandler([$res, $res]);
        self::assertCount(2, $mock);
    }

    public function testEmptyHandlerIsCountable()
    {
        self::assertCount(0, new MockHandler());
    }

    public function testEnsuresEachAppendOnCreationIsValid()
    {
        $this->expectException(\TypeError::class);
        new MockHandler(['a']);
    }

    public function testEnsuresEachAppendIsValid()
    {
        $mock = new MockHandler();
        $this->expectException(\TypeError::class);
        $mock->append(['a']);
    }

    public function testCanQueueExceptions()
    {
        $e = new \Exception('a');
        $mock = new MockHandler([$e]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, []);
        try {
            $p->wait();
            self::fail();
        } catch (\Exception $e2) {
            self::assertSame($e, $e2);
        }
    }

    public function testCanGetLastRequestAndOptions()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $mock($request, ['foo' => 'bar']);
        self::assertSame($request, $mock->getLastRequest());
        self::assertSame(['foo' => 'bar'], $mock->getLastOptions());
    }

    public function testSinkFilename()
    {
        $filename = \sys_get_temp_dir().'/mock_test_'.\uniqid();
        $res = new Response(200, [], 'TEST CONTENT');
        $mock = new MockHandler([$res]);
        $request = new Request('GET', '/');
        $p = $mock($request, ['sink' => $filename]);
        $p->wait();

        self::assertFileExists($filename);
        self::assertStringEqualsFile($filename, 'TEST CONTENT');

        \unlink($filename);
    }

    public function testSinkResource()
    {
        $file = \tmpfile();
        $meta = \stream_get_meta_data($file);
        $res = new Response(200, [], 'TEST CONTENT');
        $mock = new MockHandler([$res]);
        $request = new Request('GET', '/');
        $p = $mock($request, ['sink' => $file]);
        $p->wait();

        self::assertFileExists($meta['uri']);
        self::assertStringEqualsFile($meta['uri'], 'TEST CONTENT');
    }

    public function testSinkStream()
    {
        $stream = new Stream(\tmpfile());
        $res = new Response(200, [], 'TEST CONTENT');
        $mock = new MockHandler([$res]);
        $request = new Request('GET', '/');
        $p = $mock($request, ['sink' => $stream]);
        $p->wait();

        self::assertFileExists($stream->getMetadata('uri'));
        self::assertStringEqualsFile($stream->getMetadata('uri'), 'TEST CONTENT');
    }

    public function testCanEnqueueCallables()
    {
        $r = new Response();
        $fn = static function ($req, $o) use ($r) {
            return $r;
        };
        $mock = new MockHandler([$fn]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, ['foo' => 'bar']);
        self::assertSame($r, $p->wait());
    }

    public function testEnsuresOnHeadersIsCallable()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');

        $this->expectException(\InvalidArgumentException::class);
        $mock($request, ['on_headers' => 'error!']);
    }

    public function testRejectsPromiseWhenOnHeadersFails()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $promise = $mock($request, [
            'on_headers' => static function () {
                throw new \Exception('test');
            },
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('An error was encountered during the on_headers event');
        $promise->wait();
    }

    public function testInvokesOnFulfilled()
    {
        $res = new Response();
        $mock = new MockHandler([$res], static function ($v) use (&$c) {
            $c = $v;
        });
        $request = new Request('GET', 'http://example.com');
        $mock($request, [])->wait();
        self::assertSame($res, $c);
    }

    public function testInvokesOnRejected()
    {
        $e = new \Exception('a');
        $c = null;
        $mock = new MockHandler([$e], null, static function ($v) use (&$c) {
            $c = $v;
        });
        $request = new Request('GET', 'http://example.com');
        $mock($request, [])->wait(false);
        self::assertSame($e, $c);
    }

    public function testThrowsWhenNoMoreResponses()
    {
        $mock = new MockHandler();
        $request = new Request('GET', 'http://example.com');

        $this->expectException(\OutOfBoundsException::class);
        $mock($request, []);
    }

    public function testCanCreateWithDefaultMiddleware()
    {
        $r = new Response(500);
        $mock = MockHandler::createWithMiddleware([$r]);
        $request = new Request('GET', 'http://example.com');

        $this->expectException(BadResponseException::class);
        $mock($request, ['http_errors' => true])->wait();
    }

    public function testInvokesOnStatsFunctionForResponse()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        /** @var TransferStats|null $stats */
        $stats = null;
        $onStats = static function (TransferStats $s) use (&$stats) {
            $stats = $s;
        };
        $p = $mock($request, ['on_stats' => $onStats]);
        $p->wait();
        self::assertSame($res, $stats->getResponse());
        self::assertSame($request, $stats->getRequest());
    }

    public function testInvokesOnStatsFunctionForError()
    {
        $e = new \Exception('a');
        $c = null;
        $mock = new MockHandler([$e], null, static function ($v) use (&$c) {
            $c = $v;
        });
        $request = new Request('GET', 'http://example.com');

        /** @var TransferStats|null $stats */
        $stats = null;
        $onStats = static function (TransferStats $s) use (&$stats) {
            $stats = $s;
        };
        $mock($request, ['on_stats' => $onStats])->wait(false);
        self::assertSame($e, $stats->getHandlerErrorData());
        self::assertNull($stats->getResponse());
        self::assertSame($request, $stats->getRequest());
    }

    public function testTransferTime()
    {
        $e = new \Exception('a');
        $c = null;
        $mock = new MockHandler([$e], null, static function ($v) use (&$c) {
            $c = $v;
        });
        $request = new Request('GET', 'http://example.com');
        $stats = null;
        $onStats = static function (TransferStats $s) use (&$stats) {
            $stats = $s;
        };
        $mock($request, ['on_stats' => $onStats, 'transfer_time' => 0.4])->wait(false);
        self::assertEquals(0.4, $stats->getTransferTime());
    }

    public function testResetQueue()
    {
        $mock = new MockHandler([new Response(200), new Response(204)]);
        self::assertCount(2, $mock);

        $mock->reset();
        self::assertEmpty($mock);

        $mock->append(new Response(500));
        self::assertCount(1, $mock);
    }
}
