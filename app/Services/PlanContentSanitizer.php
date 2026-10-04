<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Keeps plan descriptions useful for storefront markup while removing
 * executable HTML and unsafe CSS/URLs.
 */
class PlanContentSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'code', 'del', 'div', 'em', 'h1', 'h2', 'h3', 'h4',
        'h5', 'h6', 'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 's', 'small', 'span', 'strong',
        'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul'
    ];

    private const DROP_CONTENT_TAGS = [
        'applet', 'base', 'button', 'embed', 'form', 'iframe', 'input', 'link', 'math',
        'meta', 'noscript', 'object', 'option', 'script', 'select', 'style', 'svg',
        'template', 'textarea'
    ];

    private const ALLOWED_STYLE_PROPERTIES = [
        'align-items', 'background', 'background-color', 'background-image', 'border',
        'border-color', 'border-radius', 'border-style', 'border-width', 'box-shadow',
        'color', 'display', 'flex', 'flex-direction', 'font-family', 'font-size',
        'font-style', 'font-weight', 'gap', 'height', 'justify-content', 'line-height',
        'list-style', 'list-style-type', 'margin', 'margin-bottom', 'margin-left',
        'margin-right', 'margin-top', 'max-height', 'max-width', 'min-height', 'min-width',
        'opacity', 'overflow', 'overflow-wrap', 'padding', 'padding-bottom', 'padding-left',
        'padding-right', 'padding-top', 'text-align', 'text-decoration', 'vertical-align',
        'white-space', 'width', 'word-break'
    ];

    public static function sanitize(string $html): string
    {
        // JSON feature lists are rendered as text by the themes. Parsing them
        // as HTML would alter their encoding before the frontend can decode them.
        if (self::isJson($html)) {
            return $html;
        }

        if (!class_exists(DOMDocument::class) || !class_exists(DOMXPath::class)) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div id="v2board-plan-content-root">' . $html . '</div>',
                LIBXML_NONET | LIBXML_HTML_NODEFDTD
            );
            if (!$loaded) {
                return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            $root = (new DOMXPath($document))
                ->query('//*[@id="v2board-plan-content-root"]')
                ->item(0);
            if (!$root) {
                return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            self::sanitizeChildren($root);

            $safeHtml = '';
            foreach ($root->childNodes as $child) {
                $safeHtml .= $document->saveHTML($child);
            }
            return $safeHtml;
        } catch (\Throwable $e) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    private static function isJson(string $content): bool
    {
        $trimmed = trim($content);
        if ($trimmed === '' || $trimmed[0] !== '[') {
            return false;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || $decoded === []) {
            return false;
        }

        foreach ($decoded as $item) {
            if (!is_array($item) || !array_key_exists('feature', $item)) {
                return false;
            }
        }

        return true;
    }

    private static function sanitizeChildren(DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                $parent->removeChild($child);
                continue;
            }

            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::DROP_CONTENT_TAGS, true)) {
                $parent->removeChild($child);
                continue;
            }

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                self::sanitizeChildren($child);
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            self::sanitizeAttributes($child, $tag);
            self::sanitizeChildren($child);
        }
    }

    private static function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = ['class', 'title', 'style'];
        if ($tag === 'a') {
            $allowed = array_merge($allowed, ['href', 'target']);
        } elseif ($tag === 'img') {
            $allowed = array_merge($allowed, ['src', 'alt', 'width', 'height']);
        } elseif ($tag === 'td' || $tag === 'th') {
            $allowed = array_merge($allowed, ['colspan', 'rowspan']);
        }

        $attributeNames = [];
        foreach ($element->attributes as $attribute) {
            $attributeNames[] = $attribute->name;
        }

        foreach ($attributeNames as $name) {
            $name = strtolower($name);
            if (!in_array($name, $allowed, true)) {
                $element->removeAttribute($name);
                continue;
            }

            $value = trim($element->getAttribute($name));
            if ($name === 'style') {
                $value = self::sanitizeStyle($value);
                if ($value === '') {
                    $element->removeAttribute($name);
                } else {
                    $element->setAttribute($name, $value);
                }
            } elseif ($name === 'href' && !self::isSafeUrl($value, ['http', 'https', 'mailto', 'tel'])) {
                $element->removeAttribute($name);
            } elseif ($name === 'src' && !self::isSafeUrl($value, ['http', 'https'])) {
                $element->removeAttribute($name);
            } elseif ($name === 'target' && !in_array(strtolower($value), ['_blank', '_self'], true)) {
                $element->removeAttribute($name);
            } elseif ($name === 'class' && !preg_match('/^[A-Za-z0-9 _-]{1,128}$/', $value)) {
                $element->removeAttribute($name);
            } elseif (in_array($name, ['width', 'height', 'colspan', 'rowspan'], true)
                && !preg_match('/^[0-9]{1,4}$/', $value)) {
                $element->removeAttribute($name);
            } elseif ($name === 'title' && preg_match('/[\x00-\x1F\x7F]/', $value)) {
                $element->removeAttribute($name);
            }
        }

        if ($tag === 'a' && strtolower($element->getAttribute('target')) === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function sanitizeStyle(string $style): string
    {
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if (!in_array($property, self::ALLOWED_STYLE_PROPERTIES, true)
                || $value === '' || strlen($value) > 512
                || preg_match('/[\x00-\x1F\x7F]/', $value)
                || preg_match('/(?:url|expression|javascript|vbscript|behavior|-moz-binding|@import)\s*\(/i', $value)
                || preg_match('/<\/?style/i', $value)) {
                continue;
            }

            $safe[] = $property . ':' . $value;
        }

        return implode(';', $safe);
    }

    private static function isSafeUrl(string $url, array $allowedSchemes): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches)) {
            return in_array(strtolower($matches[1]), $allowedSchemes, true);
        }

        return true;
    }
}
