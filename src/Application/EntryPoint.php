<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation)
 * Supports Saga pattern and Event Sourcing
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ServiceBus\Application;

use function Amp\call;
use Amp\Loop;
use Amp\Promise;
use Desperado\ServiceBus\Common\Contract\Messages\Command;
use Desperado\ServiceBus\Common\Contract\Messages\Event;
use Desperado\ServiceBus\Endpoint\EndpointRegistry;
use Desperado\ServiceBus\Endpoint\MessageRecipient;
use Desperado\ServiceBus\Infrastructure\MessageSerialization\Exceptions\DecodeMessageFailed;
use Desperado\ServiceBus\Infrastructure\MessageSerialization\IncomingMessageDecoder;
use Desperado\ServiceBus\Infrastructure\Transport\Package\IncomingPackage;
use Desperado\ServiceBus\Infrastructure\Transport\Queue;
use Desperado\ServiceBus\Infrastructure\Transport\Transport;
use Desperado\ServiceBus\MessageRouter\Router;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 *
 */
final class EntryPoint
{
    /**
     * @var Transport
     */
    private $transport;

    /**
     * @var Router
     */
    private $messagesRouter;

    /**
     * @var Queue|null
     */
    private $listenQueue;

    /**
     * @var MessageExecutor
     */
    private $messageExecutor;

    /**
     * Decoding of incoming messages
     *
     * @var IncomingMessageDecoder
     */
    private $messageDecoder;

    /**
     * Endpoints to which messages will be sent
     *
     * @var EndpointRegistry
     */
    private $endpointRegistry;

    /**
     * Point to which the message will be sent by default
     *
     * @var MessageRecipient
     */
    private $defaultRecipient;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Transport              $transport
     * @param IncomingMessageDecoder $messageDecoder
     * @param EndpointRegistry       $endpointRegistry
     * @param MessageExecutor        $messageExecutor
     * @param Router|null            $messagesRouter
     * @param LoggerInterface|null   $logger
     */
    public function __construct(
        Transport $transport,
        IncomingMessageDecoder $messageDecoder,
        EndpointRegistry $endpointRegistry,
        MessageRecipient $defaultMessageRecipient,
        MessageExecutor $messageExecutor = null,
        ?Router $messagesRouter = null,
        ?LoggerInterface $logger = null
    )
    {
        $this->logger = $logger ?? new NullLogger();

        $this->transport        = $transport;
        $this->messageDecoder   = $messageDecoder;
        $this->endpointRegistry = $endpointRegistry;
        $this->defaultRecipient = $defaultMessageRecipient;
        $this->messageExecutor  = $messageExecutor ?? new DefaultMessageExecutor($this->logger);
        $this->messagesRouter   = $messagesRouter ?? new Router();

    }

    /**
     * Register command handler
     * For 1 command there can be only 1 handler
     *
     * @param Command|string $command Command object or class
     * @param callable       $handler
     *
     * @return void
     *
     * @throws \Desperado\ServiceBus\MessageRouter\Exceptions\InvalidCommandClassSpecified
     * @throws \Desperado\ServiceBus\MessageRouter\Exceptions\MultipleCommandHandlersNotAllowed
     */
    public function registerCommandHandler($command, callable $handler): void
    {
        $this->messagesRouter->registerHandler($command, $handler);
    }

    /**
     * Add event listener
     * For each event there can be many listeners
     *
     * @param Event|string $event Event object or class
     * @param callable     $handler
     *
     * @return void
     *
     * @throws \Desperado\ServiceBus\MessageRouter\Exceptions\InvalidEventClassSpecified
     */
    public function registerEventListener($event, callable $handler): void
    {
        $this->messagesRouter->registerHandler($event, $handler);
    }

    /**
     * Start queue listen
     *
     * @param Queue $queue
     *
     * @return Promise<null>
     */
    public function listen(Queue $queue): Promise
    {
        $this->listenQueue = $queue;
        $transport         = $this->transport;
        $logger            = $this->logger;

        $decoder  = $this->messageDecoder;
        $router   = $this->messagesRouter;
        $executor = $this->messageExecutor;

        $defaultRecipient = $this->defaultRecipient;
        $endpointsRegistry = $this->endpointRegistry;

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(Queue $queue) use ($transport, $decoder, $executor, $router, $logger, $defaultRecipient, $endpointsRegistry): \Generator
            {
                /** @var \Amp\Iterator $iterator */
                $iterator = yield $transport->consume($queue);

                while(yield $iterator->advance())
                {
                    /** @var IncomingPackage $package */
                    $package = $iterator->getCurrent();

                    try
                    {
                        /** @var \Desperado\ServiceBus\Common\Contract\Messages\Message $message */
                        $message  = yield $decoder->decode($package);
                        $handlers = $router->match($message);

                        $context = new KernelContext(
                            $package,
                            $defaultRecipient,
                            $endpointsRegistry,
                            $logger
                        );

                        $logger->debug('Handle message "{messageClass}"', [
                                'messageClass' => \get_class($message),
                                'operationId'  => $package->id()
                            ]
                        );

                        yield $executor->process($message, $context, $handlers);

                        yield $package->ack();

                        unset($handlers, $message, $context);
                    }
                    catch(\Throwable $throwable)
                    {
                        $throwable instanceof DecodeMessageFailed
                            ? yield $package->reject(false)
                            : yield $package->reject(true);

                        $logger->critical($throwable->getMessage(), [
                            'operationId'    => $package->id(),
                            'throwablePoint' => \sprintf('%s:%d', $throwable->getFile(), $throwable->getLine())
                        ]);
                    }

                    unset($package);
                }
            },
            $queue
        );
    }

    /**
     * @param int $interval
     *
     * @return void
     */
    public function stop(int $interval): void
    {
        Loop::defer(
            function() use ($interval): \Generator
            {
                yield $this->transport->stop($this->listenQueue);

                $this->logger->info('Handler will stop after {duration} seconds', ['duration' => $interval / 1000]);

                Loop::delay(
                    $interval,
                    function(): void
                    {
                        $this->logger->info('The event loop has been stopped');

                        Loop::stop();
                    }
                );
            }
        );
    }
}
