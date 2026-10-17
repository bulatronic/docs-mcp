<?php

declare(strict_types=1);

namespace DocsMcp\Infrastructure\Google;

/**
 * Extracts plain text from a Google Docs documents.get JSON payload.
 *
 * Document tabs (Docs API "tabs") are not supported — only body.content is read.
 * Inline objects are skipped.
 */
final class DocumentPlainTextExtractor
{
    /**
     * @param array<string, mixed> $document
     */
    public function extract(array $document): string
    {
        $body = $document['body'] ?? null;
        if (!is_array($body)) {
            return '';
        }

        $content = $body['content'] ?? null;
        if (!is_array($content)) {
            return '';
        }

        $chunks = [];
        foreach ($content as $element) {
            if (!is_array($element)) {
                continue;
            }

            $paragraph = $element['paragraph'] ?? null;
            if (is_array($paragraph)) {
                /** @var array<string, mixed> $paragraph */
                $line = $this->paragraphToText($paragraph);
                if ($line !== '') {
                    $chunks[] = $line;
                }
                continue;
            }

            $table = $element['table'] ?? null;
            if (is_array($table)) {
                /** @var array<string, mixed> $table */
                $tableText = $this->tableToText($table);
                if ($tableText !== '') {
                    $chunks[] = $tableText;
                }
            }
        }

        return implode("\n", $chunks);
    }

    /**
     * @param array<string, mixed> $paragraph
     */
    private function paragraphToText(array $paragraph): string
    {
        $text = $this->structuralElementsToText($paragraph['elements'] ?? null);
        $text = rtrim($text, "\r\n");
        if ($text === '') {
            return '';
        }

        if ($this->isHeading($paragraph)) {
            return '# ' . $text;
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $paragraph
     */
    private function isHeading(array $paragraph): bool
    {
        $style = $paragraph['paragraphStyle'] ?? null;
        if (!is_array($style)) {
            return false;
        }

        $named = $style['namedStyleType'] ?? null;
        if (!is_string($named)) {
            return false;
        }

        return $named === 'TITLE'
            || $named === 'SUBTITLE'
            || str_starts_with($named, 'HEADING_');
    }

    /**
     * @param array<string, mixed> $table
     */
    private function tableToText(array $table): string
    {
        $rows = $table['tableRows'] ?? null;
        if (!is_array($rows)) {
            return '';
        }

        $lines = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = $row['tableCells'] ?? null;
            if (!is_array($cells)) {
                continue;
            }

            $cellTexts = [];
            foreach ($cells as $cell) {
                if (!is_array($cell)) {
                    $cellTexts[] = '';
                    continue;
                }
                /** @var array<string, mixed> $cell */
                $cellTexts[] = $this->cellToText($cell);
            }
            $lines[] = implode("\t", $cellTexts);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $cell
     */
    private function cellToText(array $cell): string
    {
        $content = $cell['content'] ?? null;
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $element) {
            if (!is_array($element) || !isset($element['paragraph']) || !is_array($element['paragraph'])) {
                continue;
            }
            $parts[] = $this->structuralElementsToText($element['paragraph']['elements'] ?? null);
        }

        return rtrim(implode('', $parts), "\r\n");
    }

    private function structuralElementsToText(mixed $elements): string
    {
        if (!is_array($elements)) {
            return '';
        }

        $buffer = '';
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            // Skip inline objects (inlineObjectElement) and other non-text runs.
            $textRun = $element['textRun'] ?? null;
            if (!is_array($textRun)) {
                continue;
            }
            $content = $textRun['content'] ?? null;
            if (is_string($content)) {
                $buffer .= $content;
            }
        }

        return $buffer;
    }
}
