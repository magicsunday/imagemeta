<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\Core\ParseError;

use function ctype_space;
use function in_array;
use function is_numeric;
use function str_contains;
use function strtolower;

/**
 * Recursive descent parser for Apple's text property list format.
 *
 * Extracted from AppleDecoder and refactored to use a PlistTextCursor object
 * instead of by-reference offset parameters.
 *
 * @phpstan-type NativePlistScalar bool|float|int|string|null
 * @phpstan-type NativePlistValue NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar>>>>>>>
 * @phpstan-type NativePlistDictionary array<string, NativePlistValue>
 */
final readonly class PlistTextParser
{
    /**
     * Parses a raw text property list string into a dictionary.
     *
     * @param string $raw The raw text plist payload.
     *
     * @return NativePlistDictionary|null
     *
     * @phpstan-return NativePlistDictionary|null
     */
    public function parse(string $raw): ?array
    {
        $cursor = new PlistTextCursor($raw);

        $this->skipWhitespace($cursor);

        if ($cursor->isAtEnd() || $cursor->peek() !== '{') {
            return null;
        }

        try {
            $dictionary = $this->parseDictionary($cursor);
        } catch (ParseError) {
            // Malformed plist structure; yield null for graceful degradation.
            return null;
        }

        $this->skipWhitespace($cursor);

        if (!$cursor->isAtEnd()) {
            return null;
        }

        return $dictionary;
    }

    /**
     * Parses a dictionary in `{key=value; ...}` format.
     *
     * @param PlistTextCursor $cursor The text cursor.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return NativePlistDictionary
     *
     * @throws ParseError
     */
    private function parseDictionary(PlistTextCursor $cursor): array
    {
        if ($cursor->peek() !== '{') {
            throw new ParseError('Expected dictionary opening brace.', 1104);
        }

        $cursor->advance();

        $dictionary = [];

        while (true) {
            $this->skipWhitespace($cursor);

            if ($cursor->isAtEnd()) {
                throw new ParseError('Unterminated dictionary payload.', 1105);
            }

            $char = $cursor->peek();

            if ($char === '}') {
                $cursor->advance();
                break;
            }

            $key = $this->parseKey($cursor);

            $this->skipWhitespace($cursor);

            if ($cursor->isAtEnd()) {
                throw new ParseError('Dictionary entry without value.', 1106);
            }

            $delimiter = $cursor->peek();

            if ($delimiter !== '=' && $delimiter !== ':') {
                throw new ParseError('Dictionary entry is missing a separator.', 1107);
            }

            $cursor->advance();

            $value = $this->parseValue($cursor);

            $dictionary[$key] = $value;

            $this->skipWhitespace($cursor);

            if ($cursor->isAtEnd()) {
                throw new ParseError('Unexpected end of dictionary payload.', 1108);
            }

            $terminator = $cursor->peek();

            if ($terminator === ';' || $terminator === ',') {
                $cursor->advance();
                continue;
            }

            if ($terminator === '}') {
                continue;
            }
        }

        return $dictionary;
    }

    /**
     * Dispatches value parsing based on the first character.
     *
     * @param PlistTextCursor $cursor The text cursor.
     *
     * @return array<int|string, mixed>|bool|float|int|string|null
     *
     * @phpstan-return NativePlistValue
     *
     * @throws ParseError
     */
    private function parseValue(PlistTextCursor $cursor): array|bool|float|int|string|null
    {
        $this->skipWhitespace($cursor);

        if ($cursor->isAtEnd()) {
            throw new ParseError('Missing value for dictionary entry.', 1109);
        }

        $char = $cursor->peek();

        if ($char === '{') {
            /** @phpstan-ignore-next-line */
            return $this->parseDictionary($cursor);
        }

        if ($char === '(') {
            /** @phpstan-ignore-next-line */
            return $this->parseArray($cursor);
        }

        if ($char === '"') {
            return $this->parseQuotedString($cursor);
        }

        $word = $this->parseWord($cursor);

        if ($word === '') {
            return null;
        }

        $lower = strtolower($word);

        if ($lower === 'true' || $word === 'YES') {
            return true;
        }

        if ($lower === 'false' || $word === 'NO') {
            return false;
        }

        if ($lower === 'null') {
            return null;
        }

        if (is_numeric($word)) {
            if (str_contains($word, '.') || str_contains($word, 'e') || str_contains($word, 'E')) {
                return (float) $word;
            }

            return (int) $word;
        }

        return $word;
    }

    /**
     * Parses an array in `(value1, value2, ...)` format.
     *
     * @param PlistTextCursor $cursor The text cursor.
     *
     * @return array<int, mixed>
     *
     * @phpstan-return array<int, NativePlistValue>
     *
     * @throws ParseError
     */
    private function parseArray(PlistTextCursor $cursor): array
    {
        if ($cursor->peek() !== '(') {
            throw new ParseError('Expected array opening parenthesis.', 1110);
        }

        $cursor->advance();

        /** @var array<int, NativePlistValue> $values */
        $values = [];

        while (true) {
            $this->skipWhitespace($cursor);

            if ($cursor->isAtEnd()) {
                throw new ParseError('Unterminated array payload.', 1111);
            }

            if ($cursor->peek() === ')') {
                $cursor->advance();
                break;
            }

            $values[] = $this->parseValue($cursor);

            $this->skipWhitespace($cursor);

            if ($cursor->isAtEnd()) {
                throw new ParseError('Unexpected end of array payload.', 1112);
            }

            $terminator = $cursor->peek();

            if ($terminator === ',' || $terminator === ';') {
                $cursor->advance();
            }

            if ($terminator === ')') {
                continue;
            }
        }

        /** @var array<int, NativePlistValue> $values */
        return $values;
    }

    /**
     * Parses a quoted string with backslash escape support.
     *
     * @param PlistTextCursor $cursor The text cursor.
     *
     * @throws ParseError
     */
    private function parseQuotedString(PlistTextCursor $cursor): string
    {
        if ($cursor->peek() !== '"') {
            throw new ParseError('Expected quoted string.', 1113);
        }

        $cursor->advance();

        $start  = $cursor->offset();
        $buffer = '';

        while (!$cursor->isAtEnd()) {
            $char = $cursor->peek();

            if ($char === '\\') {
                if ($cursor->offset() + 1 >= $cursor->length()) {
                    throw new ParseError('Invalid escape sequence in string.', 1114);
                }

                $buffer .= $cursor->substr($start, $cursor->offset() - $start);
                $cursor->advance();
                $buffer .= $cursor->peek();
                $cursor->advance();
                $start = $cursor->offset();

                continue;
            }

            if ($char === '"') {
                $buffer .= $cursor->substr($start, $cursor->offset() - $start);
                $cursor->advance();

                return $buffer;
            }

            $cursor->advance();
        }

        throw new ParseError('Unterminated quoted string.', 1115);
    }

    /**
     * Parses an unquoted word token until a delimiter or whitespace is reached.
     *
     * @param PlistTextCursor $cursor The text cursor.
     */
    private function parseWord(PlistTextCursor $cursor): string
    {
        $start = $cursor->offset();

        while (!$cursor->isAtEnd()) {
            $char = $cursor->peek();

            if (in_array($char, [';', ',', ')', '}'], true) || ctype_space($char)) {
                break;
            }

            $cursor->advance();
        }

        return $cursor->substr($start, $cursor->offset() - $start);
    }

    /**
     * Parses a dictionary key (quoted or unquoted).
     *
     * @param PlistTextCursor $cursor The text cursor.
     *
     * @throws ParseError
     */
    private function parseKey(PlistTextCursor $cursor): string
    {
        $this->skipWhitespace($cursor);

        if ($cursor->isAtEnd()) {
            throw new ParseError('Missing dictionary key.', 1116);
        }

        if ($cursor->peek() === '"') {
            return $this->parseQuotedString($cursor);
        }

        $key = $this->parseWord($cursor);

        if ($key === '') {
            throw new ParseError('Dictionary key is empty.', 1117);
        }

        return $key;
    }

    /**
     * Advances the cursor past whitespace and null bytes.
     *
     * @param PlistTextCursor $cursor The text cursor.
     */
    private function skipWhitespace(PlistTextCursor $cursor): void
    {
        while (!$cursor->isAtEnd()) {
            $char = $cursor->peek();

            if ($char === "\0") {
                $cursor->advance();
                continue;
            }

            if (!ctype_space($char)) {
                break;
            }

            $cursor->advance();
        }
    }
}
