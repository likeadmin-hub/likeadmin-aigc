<?php

declare(strict_types=1);

namespace NeuronAI\RAG\PreProcessor;

enum QueryTransformationType: string
{
    case REWRITING = 'rewriting';
    case DECOMPOSITION = 'decomposition';
    case HYDE = 'hyde';
}
