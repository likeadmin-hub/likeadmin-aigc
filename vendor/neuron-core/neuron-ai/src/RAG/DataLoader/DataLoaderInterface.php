<?php

declare(strict_types=1);

namespace NeuronAI\RAG\DataLoader;

use NeuronAI\RAG\Document;

interface DataLoaderInterface
{
    /**
     * @return Document[]
     */
    public function getDocuments(): array;
}
