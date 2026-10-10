<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use NeuronAI\RAG\Document;

interface EmbeddingsProviderInterface
{
    /**
     * @return float[]
     */
    public function embedText(string $text): array;

    public function embedDocument(Document $document): Document;

    /**
     * @param Document[] $documents
     * @return Document[]
     */
    public function embedDocuments(array $documents): array;
}
