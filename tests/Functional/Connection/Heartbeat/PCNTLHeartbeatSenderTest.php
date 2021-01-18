<?php

namespace PhpAmqpLib\Tests\Functional\Connection\Heartbeat;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Tests\Functional\AbstractConnectionTest;

/**
 * @group connection
 * @group signals
 * @requires extension pcntl
 * @requires PHP 7.1
 */
class PCNTLHeartbeatSenderTest extends AbstractConnectionTest
{
    /** @var AbstractConnection */
    protected $connection;

    /** @var PCNTLHeartbeatSender */
    protected $sender;

    /** @var int */
    protected $heartbeatTimeout = 4;

    protected function setUp()
    {
        $this->connection = $this->conection_create(
            'stream',
            HOST,
            PORT,
            ['timeout' => 3, 'heartbeat' => $this->heartbeatTimeout]
        );
        try {
            $this->sender = new PCNTLHeartbeatSender($this->connection);
        } catch (\Exception $exception) {
            $this->markTestSkipped($exception->getMessage());
        }
    }

    public function tearDown()
    {
        if ($this->sender) {
            $this->sender->unregister();
        }
        if ($this->connection) {
            $this->connection->close();
        }
        $this->sender = null;
        $this->connection = null;
    }

    /**
     * @test
     */
    public function register_should_fail_with_closed_connection()
    {
        $this->expectException(AMQPRuntimeException::class);
        $this->expectExceptionMessage('Unable to register heartbeat sender, connection is not active');

        $this->connection->close();
        $this->sender->register();
    }

    /**
     * @test
     */
    public function register_should_fail_after_unregister()
    {
        $this->expectException(AMQPRuntimeException::class);
        $this->expectExceptionMessage('Unable to re-register heartbeat sender');

        $this->sender->unregister();
        $this->sender->register();
    }

    /**
     * @test
     */
    public function unregister_should_return_default_signal_handler()
    {
        $this->sender->register();
        $this->sender->unregister();

        self::assertEquals(SIG_IGN, pcntl_signal_get_handler(SIGALRM));
    }

    /**
     * @test
     */
    public function heartbeat_should_interrupt_non_blocking_action()
    {
        $this->sender->register();

        $timeLeft = $this->heartbeatTimeout;
        $continuation = 0;
        while ($timeLeft > 0) {
            $timeLeft = sleep($timeLeft);
            $continuation++;
        }

        self::assertEquals(2, $continuation);
    }

    /**
     * @test
     */
    public function alarm_sig_should_be_registered_when_conn_is_writing()
    {
        $connection = $this->getMockBuilder(AbstractConnection::class)
            ->setMethods(['isConnected', 'getHeartbeat', 'isWriting', 'getLastActivity'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $connection->expects($this->exactly(3))->method('isConnected')->willReturn(true);
        $connection->expects($this->once())->method('getHeartbeat')->willReturn($this->heartbeatTimeout);
        $connection->expects($this->exactly(2))
            ->method('isWriting')
            ->willReturnOnConsecutiveCalls(true, false);
        $connection->expects($this->exactly(1))
            ->method('getLastActivity')
            ->willReturn(time() + 99);

        $sender = new PCNTLHeartbeatSender($connection);
        $sender->register();

        $timeLeft = $this->heartbeatTimeout + 1;
        while ($timeLeft > 0) {
            $timeLeft = sleep($timeLeft);
        }

        $sender->unregister();
    }
}
