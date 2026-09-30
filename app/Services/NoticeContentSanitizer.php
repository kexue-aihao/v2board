<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class NoticeContentSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'code', 'del', 'div', 'em', 'h1', 'h2', 'h3', 'h4',
        'h5', 'h6', 'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 's', 'span', 'strong',
        'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul'
    ];

    private const DROP_CONTENT_TAGS = [
        'applet', 'base', 'button', 'embed', 'form', 'iframe', 'input', 'link', 'math',
        'meta', 'noscript', 'object', 'option', 'script', 'select', 'style', 'svg',
        'template', 'textarea'
    ];

    public static function sanitize(string $html): string
    {
        if (!class_exists(DOMDocument::class) || !class_exists(DOMXPath::class)) {
            return self::escape($html);
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div id="v2board-notice-content-root">' . $html . '</div>',
                LIBXML_NONET | LIBXML_HTML_NODEFDTD
            );
            if (!$loaded) {
                return self::escape($html);
            }

            $root = (new DOMXPath($document))
                ->query('//*[@id="v2board-notice-content-root"]')
                ->item(0);
            if (!$root) {
                return self::escape($html);
            }

            self::sanitizeChildren($root);

            $safeHtml = '';
            foreach ($root->childNodes as $child) {
                $safeHtml .= $document->saveHTML($child);
            }
            return $safeHtml;
        } catch (\Throwable $e) {
            return self::escape($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
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
        $allowed = ['class', 'title'];
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
            if ($name === 'href' && !self::isSafeUrl($value, ['http', 'https', 'mailto', 'tel'])) {
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

    private static function escape(string $html): string
    {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
