<?php
/** Byte-preserving HTML document validation and metadata transformation. */
class HTMLPress_Document {
    public static function validate(string $html) {
        if (strlen($html) > 2097152 || strpos($html, "\0") !== false || !preg_match('//u', $html) || strpos($html, '<?') !== false) {
            return new WP_Error('htmlpress_invalid_document', 'Documento HTML inválido.');
        }

        $stack = [];
        $structure = [];
        foreach (self::scan($html) as $tag) {
            if ($tag['template'] > 0) {
                continue;
            }
            $name = $tag['name'];
            if (in_array($name, ['html', 'head', 'body'], true)) {
                if (!$tag['closing']) {
                    if (isset($structure[$name]) || ($tag['self_closing'] ?? false)) {
                        return new WP_Error('htmlpress_invalid_document', 'Estrutura do documento HTML inválida.');
                    }
                    $expected = ['html' => [], 'head' => ['html'], 'body' => ['html']];
                    $validBodyOrder = $name !== 'body' || (($structure['head'] ?? null) === 'closed');
                    if ($stack !== $expected[$name] || !$validBodyOrder || ($name === 'head' && isset($structure['head']))) {
                        return new WP_Error('htmlpress_invalid_document', 'Ordem da estrutura HTML inválida.');
                    }
                    $stack[] = $name;
                    $structure[$name] = 'open';
                } else {
                    if (end($stack) !== $name) {
                        return new WP_Error('htmlpress_invalid_document', 'Fechamento da estrutura HTML inválido.');
                    }
                    array_pop($stack);
                    $structure[$name] = 'closed';
                }
            }
        }
        return $stack === [] && ($structure === ['html'=>'closed','head'=>'closed','body'=>'closed'])
            ? true : new WP_Error('htmlpress_invalid_document', 'Documento HTML incompleto.');
    }

    /** Tokenize tags without treating comments or raw-text contents as markup. */
    private static function scan(string $html): array {
        $tags = [];
        $length = strlen($html);
        $offset = 0;
        $rawText = null;
        $templateDepth = 0;
        while ($offset < $length) {
            if ($rawText !== null) {
                if (!preg_match('~</' . preg_quote($rawText, '~') . '\s*>~i', $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
                    break;
                }
                $offset = $match[0][1];
                $rawText = null;
                continue;
            }
            if (substr($html, $offset, 4) === '<!--') {
                $end = strpos($html, '-->', $offset + 4);
                $offset = $end === false ? $length : $end + 3;
                continue;
            }
            if ($html[$offset] !== '<' || !preg_match('/\G<\s*(\/?)\s*([a-zA-Z][\w:-]*)\b/A', $html, $match, 0, $offset)) {
                $offset++;
                continue;
            }
            $start = $offset;
            $quote = null;
            $offset += strlen($match[0]);
            while ($offset < $length) {
                $char = $html[$offset++];
                if ($quote !== null) {
                    if ($char === $quote) $quote = null;
                } elseif ($char === '"' || $char === "'") {
                    $quote = $char;
                } elseif ($char === '>') {
                    break;
                }
            }
            if ($html[$offset - 1] !== '>') break;

            $name = strtolower($match[2]);
            $closing = $match[1] === '/';
            $chunk = substr($html, $start, $offset - $start);
            $attrs = self::attributes($chunk);
            $tag = ['start'=>$start, 'end'=>$offset, 'name'=>$name, 'closing'=>$closing,
                'self_closing'=>preg_match('~/\s*>$~', $chunk) === 1, 'attrs'=>$attrs,
                'template'=>$templateDepth];
            $tags[] = $tag;

            if ($name === 'template') {
                if ($closing) $templateDepth = max(0, $templateDepth - 1);
                else $templateDepth++;
            }
            if (!$closing && in_array($name, ['script','style','textarea','title','xmp','iframe','noframes','noembed'], true)) {
                $rawText = $name;
            }
        }
        return $tags;
    }

    /** Attribute values and whole-attribute spans, relative to the tag start. */
    private static function attributes(string $tag): array {
        $attributes = [];
        preg_match('/^<\s*\/?\s*[a-zA-Z][\w:-]*\b/', $tag, $opener);
        $attributeOffset = strlen($opener[0] ?? '');
        preg_match_all('/([a-zA-Z_:][\w:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', substr($tag, $attributeOffset), $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $name = strtolower($match[1][0]);
            $value = '';
            $valueStart = -1;
            $valueEnd = -1;
            for ($i = 2; $i <= 4; $i++) {
                if (isset($match[$i]) && $match[$i][1] >= 0) {
                    $value = $match[$i][0];
                    $valueStart = $match[$i][1] + 1;
                    $valueEnd = $valueStart + strlen($value);
                    break;
                }
            }
            $attributes[] = ['name'=>$name, 'value'=>$value, 'start'=>$match[0][1] + $attributeOffset,
                'end'=>$match[0][1] + strlen($match[0][0]) + $attributeOffset,
                'value_start'=>$valueStart, 'value_end'=>$valueEnd];
        }
        return $attributes;
    }

    private static function attrValue(array $tag, string $name): string {
        foreach ($tag['attrs'] as $attribute) {
            if ($attribute['name'] === $name) return html_entity_decode($attribute['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    private static function linkKind(array $tag): string {
        if ($tag['name'] !== 'link' || $tag['closing']) return '';
        $rels = preg_split('/\s+/', strtolower(trim(self::attrValue($tag, 'rel'))));
        if (in_array('canonical', $rels, true)) return 'canonical';
        if (in_array('alternate', $rels, true) && self::attrValue($tag, 'hreflang') !== '') return 'hreflang';
        return '';
    }

    public static function transform(string $html, string $locale, string $canonical, array $alternates): string {
        $tags = self::scan($html);
        $edits = [];
        $insideHead = false;
        $headEnd = null;
        $htmlTag = null;
        foreach ($tags as $tag) {
            if ($tag['template'] > 0) continue;
            if ($tag['name'] === 'html' && !$tag['closing']) $htmlTag = $tag;
            if ($tag['name'] === 'head' && !$tag['closing']) $insideHead = true;
            if ($tag['name'] === 'head' && $tag['closing'] && $insideHead) {
                $headEnd = $tag['start'];
                $insideHead = false;
            }
            if ($insideHead && self::linkKind($tag) !== '') $edits[] = [$tag['start'], $tag['end'], ''];
        }

        if ($htmlTag !== null) {
            $langAttributes = array_values(array_filter($htmlTag['attrs'], static function ($attribute) {
                return $attribute['name'] === 'lang';
            }));
            foreach ($langAttributes as $index => $attribute) {
                $replacement = $index === 0
                    ? 'lang="' . htmlspecialchars($locale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                    : '';
                $edits[] = [$htmlTag['start'] + $attribute['start'], $htmlTag['start'] + $attribute['end'], $replacement];
            }
            if ($langAttributes === []) {
                $edits[] = [$htmlTag['end'] - 1, $htmlTag['end'] - 1, ' lang="' . htmlspecialchars($locale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'];
            }
        }

        $metadata = '<link rel="canonical" href="' . htmlspecialchars($canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        $seen = [];
        foreach ($alternates as $language => $url) {
            $seen[strtolower($language)] = true;
            $metadata .= '<link rel="alternate" hreflang="' . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        }
        if (!isset($seen['x-default'])) {
            $metadata .= '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($alternates['pt-BR'] ?? $canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        }
        if ($headEnd !== null) $edits[] = [$headEnd, $headEnd, $metadata];

        usort($edits, static function ($left, $right) { return $right[0] <=> $left[0]; });
        foreach ($edits as [$start, $end, $replacement]) {
            $html = substr($html, 0, $start) . $replacement . substr($html, $end);
        }
        return $html;
    }
}
