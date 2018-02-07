<?php
namespace njpanderson\VarnishConnect;

use PHPUnit\Framework\TestCase;

final class SocketTest extends TestCase
{
    public function testInstantiation()
    {
        $this->assertInstanceOf(SocketTest::class, new SocketTest);
    }
}
