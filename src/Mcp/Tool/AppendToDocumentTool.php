<?php

declare(strict_types=1);

namespace DocsMcp\Mcp\Tool;

use DocsMcp\Application\UseCase\AppendToDocument;
use Mcp\Capability\Attribute\McpTool;

final readonly class AppendToDocumentTool
{
    public function __construct(
        private AppendToDocument $useCase,
    ) {}

    /**
     * Дописать текст в конец Google Doc из allowlist.
     * Вызов не идемпотентен: повтор допишет блок ещё раз — не повторяйте при сомнении.
     *
     * @return array{document_id: string, appended_chars: int, modified_time: string|null}
     */
    #[McpTool(
        name: 'append_to_document',
        description: 'Дописать текст в конец Google Doc из allowlist (нужны DOCS_WRITE_ENABLED и DOCS_APPEND_ALLOWED_IDS). Опциональный heading добавляет строку с датой/временем. Вызов НЕ идемпотентен: повторный вызов допишет блок ещё раз — при сомнении не повторяйте.',
    )]
    public function appendToDocument(string $document_id, string $text, ?string $heading = null): array
    {
        $result = $this->useCase->execute($document_id, $text, $heading);

        return [
            'document_id' => $result->documentId->value,
            'appended_chars' => $result->appendedChars,
            'modified_time' => $result->modifiedTime?->format(\DATE_ATOM),
        ];
    }
}
