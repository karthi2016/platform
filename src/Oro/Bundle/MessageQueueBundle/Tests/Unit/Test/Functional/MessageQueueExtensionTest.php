<?php

namespace Oro\Bundle\MessageQueueBundle\Tests\Unit;

use Oro\Bundle\MessageQueueBundle\Test\Functional\MessageCollector;
use Oro\Bundle\MessageQueueBundle\Test\Functional\MessageQueueExtension;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MessageQueueExtensionTest extends \PHPUnit_Framework_TestCase
{
    use MessageQueueExtension;

    /** @var ContainerInterface */
    private static $container;

    /** @var MessageCollector */
    private static $messageCollector;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->initClient();
    }

    /**
     * {@inheritdoc}
     */
    public static function tearDownAfterClass()
    {
        self::$container = null;
        self::$messageCollector = null;
    }

    protected function initClient()
    {
        if (null === self::$container) {
            self::$container = new Container();
            self::$messageCollector = new MessageCollector($this->getMock(MessageProducerInterface::class));
            self::$container->set('oro_message_queue.test.message_collector', self::$messageCollector);
        }
    }

    /**
     * @return ContainerInterface
     */
    protected static function getContainer()
    {
        return self::$container;
    }

    public function testShouldAllowGetMessageCollector()
    {
        self::assertSame(self::$messageCollector, self::getMessageCollector());
    }

    public function testShouldAllowGetSentMessages()
    {
        $topic = 'test topic';
        $message = 'test message';

        self::$messageCollector->send($topic, $message);

        self::assertEquals(
            [
                ['topic' => $topic, 'message' => $message]
            ],
            self::getSentMessages()
        );
    }

    public function testAssertMessageSentShouldThrowValidExceptionIfAssertionIsFalse()
    {
        $exception = false;
        try {
            self::assertMessageSent('test topic', 'test message');
        } catch (\PHPUnit_Framework_ExpectationFailedException $e) {
            $exception = $e;
            self::assertContains('Failed asserting that the message', $exception->getMessage());
            self::assertContains('All sent messages', $exception->getMessage());
        }
        if (!$exception) {
            self::fail('\PHPUnit_Framework_ExpectationFailedException expected');
        }
    }

    public function testAssertMessageSentShouldNotThrowExceptionIfAssertionIsTrue()
    {
        $topic = 'test topic';
        $message = 'test message';

        self::$messageCollector->send($topic, $message);

        self::assertMessageSent($topic, $message);
    }

    public function testAssertMessagesSentShouldThrowValidExceptionIfOneOfMessageAssertionIsFalse()
    {
        $topic = 'test topic';
        $message1 = 'test message 1';
        $message2 = 'test message 2';

        self::$messageCollector->send($topic, $message1);
        self::$messageCollector->send($topic, $message2);

        $exception = false;
        try {
            self::assertMessagesSent($topic, [$message1, 'another message']);
        } catch (\PHPUnit_Framework_ExpectationFailedException $e) {
            $exception = $e;
            self::assertContains('Failed asserting that the message', $exception->getMessage());
            self::assertContains('All sent messages', $exception->getMessage());
        }
        if (!$exception) {
            self::fail('\PHPUnit_Framework_ExpectationFailedException expected');
        }
    }

    public function testAssertMessagesSentShouldThrowValidExceptionIfCountAssertionIsFalse()
    {
        $topic = 'test topic';
        $message1 = 'test message 1';
        $message2 = 'test message 2';

        self::$messageCollector->send($topic, $message1);
        self::$messageCollector->send($topic, $message2);

        $exception = false;
        try {
            self::assertMessagesSent($topic, [$message2]);
        } catch (\PHPUnit_Framework_ExpectationFailedException $e) {
            $exception = $e;
            self::assertContains(
                'Failed asserting that exactly given messages were sent to "test topic" topic',
                $exception->getMessage()
            );
            self::assertContains(
                'actual size 2 matches expected size 1',
                $exception->getMessage()
            );
        }
        if (!$exception) {
            self::fail('\PHPUnit_Framework_ExpectationFailedException expected');
        }
    }

    public function testAssertMessagesSentShouldNotThrowExceptionIfAssertionIsTrue()
    {
        $topic = 'test topic';
        $message1 = 'test message 1';
        $message2 = 'test message 2';

        $anotherTopic = 'another topic';
        $anotherMessage = 'another message';

        self::$messageCollector->send($topic, $message1);
        self::$messageCollector->send($topic, $message2);
        self::$messageCollector->send($anotherTopic, $anotherMessage);

        self::assertMessagesSent($topic, [$message1, $message2]);
        // test that send order is not taken into account
        self::assertMessagesSent($topic, [$message2, $message1]);
        // do test for another topic
        self::assertMessagesSent($anotherTopic, [$anotherMessage]);
    }

    public function testAssertEmptyMessagesShouldThrowValidExceptionIfAssertionIsFalse()
    {
        $topic = 'test topic';

        self::$messageCollector->send($topic, 'test message');

        $exception = false;
        try {
            self::assertEmptyMessages($topic);
        } catch (\PHPUnit_Framework_ExpectationFailedException $e) {
            $exception = $e;
            self::assertContains(
                'Failed asserting that exactly given messages were sent to "test topic" topic',
                $exception->getMessage()
            );
            self::assertContains(
                'actual size 1 matches expected size 0',
                $exception->getMessage()
            );
        }
        if (!$exception) {
            self::fail('\PHPUnit_Framework_ExpectationFailedException expected');
        }
    }

    public function testAssertEmptyMessagesShouldNotThrowExceptionIfAssertionIsTrue()
    {
        self::assertEmptyMessages('test topic');
    }

    public function testAssertAllMessagesSentShouldThrowValidExceptionIfAssertionIsFalse()
    {
        $exception = false;
        try {
            self::assertAllMessagesSent([['topic' => 'test topic', 'message' => 'test message']]);
        } catch (\PHPUnit_Framework_ExpectationFailedException $e) {
            $exception = $e;
            self::assertContains('Failed asserting that exactly all messages were sent', $exception->getMessage());
        }
        if (!$exception) {
            self::fail('\PHPUnit_Framework_ExpectationFailedException expected');
        }
    }

    public function testAssertAllMessagesSentShouldNotThrowExceptionIfAssertionIsTrue()
    {
        $topic = 'test topic';
        $message = 'test message';

        self::$messageCollector->send($topic, $message);

        self::assertAllMessagesSent([['topic' => $topic, 'message' => $message]]);
    }
}
