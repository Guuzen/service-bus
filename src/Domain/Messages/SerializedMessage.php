<?php

/**
 * CQRS/Event Sourcing Non-blocking concurrency framework
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @url     https://github.com/mmasiukevich
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ConcurrencyFramework\Domain\Messages;

/**
 * Serialized message DTO
 */
final class SerializedMessage
{
    /**
     * Message payload
     *
     * @var array
     */
    public $message;

    /**
     * Message class namespace
     *
     * @var string
     */
    public $namespace;

    /**
     * Metadata
     *
     * @var array
     */
    public $metadata;
}
