<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Enums;

enum MessageRole: string
{
    case USER = 'user';
    case ASSISTANT = 'assistant';
    case MODEL = 'model';
    case TOOL = 'tool';
    case SYSTEM = 'system';
    case DEVELOPER = 'developer';
}
