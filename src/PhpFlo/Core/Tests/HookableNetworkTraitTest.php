<?php
/*
 * This file is part of the phpflo/phpflo package.
 *
 * (c) Marc Aschmann <maschmann@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace PhpFlo\Core\Tests;

use PhpFlo\Core\HookableNetworkTrait;
use PhpFlo\Common\Test\TestUtilityTrait;
use PhpFlo\Core\Interaction\InternalSocket;

class HookableNetworkTraitTest extends \PHPUnit_Framework_TestCase
{
    use TestUtilityTrait;

    /**
     * @var HookableNetworkTrait | \PHPUnit_Framework_MockObject_MockObject
     */
    private $hookTrait;

    public function setUp()
    {
        $this->hookTrait = $this->getObjectForTrait(HookableNetworkTrait::class);
    }

    public function testSetHook()
    {
        $this->hookTrait->hook(
            'data',
            'somename',
            function () {
                return 'callback1';
            }
        );

        $hooks = $this->hookTrait->hooks();

        $this->assertTrue(is_array($hooks));
        $this->assertArrayHasKey('data', $hooks);
        $this->assertArrayHasKey('somename', $hooks['data']);
        $this->assertInstanceOf('\Closure', $hooks['data']['somename']);
        $this->assertEquals('callback1', $hooks['data']['somename']());
    }

    /**
     * @expectedException \PhpFlo\Common\Exception\InvalidTypeException
     */
    public function testInvalidEventNameException()
    {
        $this->hookTrait->hook(
            'i_am_inavlid',
            'somename',
            function () {
                return 'callback1';
            }
        );
    }

    /**
     * @expectedException \PhpFlo\Common\Exception\FlowException
     */
    public function testEventAlreadyExistsException()
    {
        $this->hookTrait->hook(
            'data',
            'somename',
            function () {
                return 'callback1';
            }
        )->hook(
            'data',
            'somename',
            function () {
                return 'callback1';
            }
        );
    }

    public function testAddHooksToSocket()
    {
        $this->hookTrait->hook(
            'data',
            'somename',
            function () {
                return 'callback1';
            }
        );

        /** since we want to test the protected method, this is the only way in php -.- */
        $reflector = new \ReflectionClass(get_class($this->hookTrait));
        $method = $reflector->getMethod('addHooks');
        $method->setAccessible(true);

        $socket = $this->getMockBuilder(InternalSocket::class)
            ->disableOriginalConstructor()
            ->getMock();

        // bind closure to socket, to have object access
        $cb = \Closure::bind(
            function () {
                $this->set_listener['data'] = 'somename';
            },
            $socket
        );

        $socket
            ->expects($this->any())
            ->method('on')
            ->willReturnCallback($cb);

        // we just want to test if the $socket->on() method is called
        $socket = $method->invokeArgs($this->hookTrait, [$socket]);
        $this->assertEquals('somename', $socket->set_listener['data']);
    }
}
