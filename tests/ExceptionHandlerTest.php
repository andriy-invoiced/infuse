<?php

/**
 * @author Jared King <j@jaredtking.com>
 *
 * @link http://jaredtking.com
 *
 * @copyright 2015 Jared King
 * @license MIT
 */
use Infuse\Application;
use Infuse\ExceptionHandler;
use Infuse\Request;
use Infuse\Response;
use PHPUnit\Framework\TestCase;

class ExceptionHandlerTest extends TestCase
{
    public function testInvoke()
    {
        $app = new Application(['dirs' => ['views' => __DIR__.'/views']]);
        $handler = new ExceptionHandler();
        $handler->setApp($app);
        $e = new Exception();
        $req = new Request([], [], [], [], ['HTTP_ACCEPT' => 'text/html']);
        $res = new Response();
        $this->assertEquals($res, $handler($req, $res, $e));
        $this->assertEquals(500, $res->getCode());
        $this->assertEquals('exception', $res->getBody());
    }
}
