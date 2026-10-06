<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Rich-text (the editor) is stored as a small, safe subset of HTML.
 *   Html::clean($posted)  → what goes into the database (unknown tags/attributes removed, images only from our own upload folder)
 *   Html::render($stored) → what the page prints (image paths made absolute)
 */
final class Html
{
    private const TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'blockquote', 'pre', 'code', 'span', 'img', 'sub', 'sup', 'div'];
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'link', 'meta', 'base', 'noscript', 'template'];
    private const STYLE_OK = ['text-align', 'width', 'height', 'color', 'background-color'];

    public static function clean(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '' || mb_strlen($html) > 200000) {
            return null;
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="rt-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('rt-root');
        if (! $root) {
            return null;
        }
        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        $out = trim(str_replace(['&nbsp;', "\xC2\xA0"], ' ', $out));
        $hasText = trim(html_entity_decode(strip_tags(str_replace('&nbsp;', ' ', $out)), ENT_QUOTES, 'UTF-8')) !== '';

        return ($hasText || str_contains($out, '<img')) ? $out : null;
    }

    /** Stored HTML → HTML for the browser. */
    public static function render(?string $stored): string
    {
        return $stored ? str_replace('src="uploads/editor/', 'src="'.e(base_url()).'/uploads/editor/', $stored) : '';
    }

    public static function text(?string $stored): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '</li>', '</h1>', '</h2>', '</h3>'], ' ', (string) $stored)), ENT_QUOTES, 'UTF-8')) ?? '');
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            if (! in_array($tag, self::TAGS, true)) {          // unknown tag: keep its content
                self::walk($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            if (! self::attributes($child, $tag)) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child);
        }
    }

    /** Strip attributes that are not on the list. Returns false when the element itself must go (e.g. a foreign image). */
    private static function attributes(\DOMElement $el, string $tag): bool
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $val  = $attr->value;
            $keep = false;

            if ($name === 'class') {
                $keep = (bool) preg_match('/^(ql-[a-z0-9-]+)( ql-[a-z0-9-]+)*$/', $val);
            } elseif ($name === 'style') {
                $safe = [];
                foreach (explode(';', $val) as $decl) {
                    [$k, $v] = array_pad(array_map('trim', explode(':', $decl, 2)), 2, '');
                    if (in_array(strtolower($k), self::STYLE_OK, true) && preg_match('/^[#a-z0-9%.(),\s-]{1,40}$/i', $v) && ! preg_match('/url|expression|javascript/i', $v)) {
                        $safe[] = strtolower($k).': '.$v;
                    }
                }
                if ($safe) {
                    $el->setAttribute('style', implode('; ', $safe));
                    continue;
                }
            } elseif ($tag === 'a' && $name === 'href') {
                $keep = (bool) preg_match('#^(https?://|mailto:|tel:)#i', trim($val));
            } elseif ($tag === 'img' && $name === 'src') {
                if (preg_match('#(?:^|/)uploads/editor/([a-f0-9]{24}\.(?:png|jpg|webp|gif))$#i', trim($val), $m)) {
                    $el->setAttribute('src', 'uploads/editor/'.strtolower($m[1]));
                    continue;
                }
                return false;
            } elseif ($tag === 'img' && in_array($name, ['width', 'height'], true)) {
                $keep = (bool) preg_match('/^\d{1,4}(px|%)?$/', trim($val));
            } elseif ($tag === 'img' && $name === 'alt') {
                $keep = mb_strlen($val) < 200;
            } elseif ($name === 'data-list' && $tag === 'li') {
                $keep = in_array($val, ['bullet', 'ordered', 'checked', 'unchecked'], true);
            }
            if (! $keep) {
                $el->removeAttribute($attr->name);
            }
        }
        if ($tag === 'img' && ! $el->hasAttribute('src')) {
            return false;
        }
        if ($tag === 'a') {
            if (! $el->hasAttribute('href')) {
                return true;
            }
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }

        return true;
    }
}
