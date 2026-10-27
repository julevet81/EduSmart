<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Enums\MessageChannel;
use InvalidArgumentException;

final class ChannelManager
{
    public function driver(MessageChannel $channel): ChannelDriver
    {
        $name = (string) config("messaging.drivers.{$channel->value}", 'log');

        return match (true) {
            $name === 'log' => new LogDriver,
            $name === 'null' => new NullDriver,
            class_exists($name) && is_subclass_of($name, ChannelDriver::class) => app($name),
            default => throw new InvalidArgumentException("Unknown messaging driver [{$name}] for channel [{$channel->value}]."),
        };
    }
}
