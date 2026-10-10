<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Splitter;

use NeuronAI\RAG\Document;

interface SplitterInterface
{
    /**
     * @return Document[]
     */
    public function splitDocument(Document $document): array;

    /**
     * @param  Document[]  $documents
     * @return Document[]
     */
    public function splitDocuments(array $documents): array;
}
