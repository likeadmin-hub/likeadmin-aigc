<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Chat\Messages\Message;

interface MessageMapperInterface
{
    /**
     * @param array<Message> $messages
     */
    public function map(array $messages): array;
}
