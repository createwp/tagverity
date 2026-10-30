<?php
namespace GTMAuditor;

use Masterminds\HTML5;

final class SitePreviewRenderer {
    // xmp, noembed, noframes and plaintext are raw-text elements that masterminds/html5 does not parse
    // the way browsers do; none of them matter for a visual preview, so they are removed outright.
    private const REMOVED_ELEMENTS = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'base', 'template', 'portal', 'math', 'xmp', 'noembed', 'noframes', 'plaintext'];
    private const SVG_REMOVED_ELEMENTS = ['foreignobject', 'animate', 'set', 'handler', 'listener'];
    private const DROPPED_ATTRIBUTES = ['srcdoc', 'formaction', 'ping', 'target', 'xmlns:xlink'];
    private const URL_ATTRIBUTES = ['href', 'src', 'xlink:href', 'action', 'data', 'poster', 'background', 'srcset'];
    private const LOWER = "translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')";

    public function render(string $html, string $pageUrl): string {
        // HTML5 parser (as used by browsers). disable_html_ns keeps HTML elements namespace-free,
        // so every lookup below goes by local name because svg/math children keep their own namespace.
        $parser = new HTML5(['disable_html_ns' => true]);
        try {
            $dom = $parser->loadHTML($html);
        } catch (\Throwable $e) {
            throw new \RuntimeException('preview_parse_failed', 0, $e);
        }

        $xpath = new \DOMXPath($dom);
        $this->removeNodes($xpath, $this->removalQuery());
        $this->sanitiseAttributes($xpath);

        $htmlNode = $dom->getElementsByTagName('html')->item(0);
        if (!$htmlNode) {
            $htmlNode = $dom->appendChild($dom->createElement('html'));
        }
        $head = $dom->getElementsByTagName('head')->item(0);
        if (!$head) {
            $head = $dom->createElement('head');
            $htmlNode->insertBefore($head, $htmlNode->firstChild);
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            $body = $dom->createElement('body');
            $htmlNode->appendChild($body);
        }

        $base = $dom->createElement('base');
        $base->setAttribute('href', $pageUrl);
        $head->insertBefore($base, $head->firstChild);

        $referrer = $dom->createElement('meta');
        $referrer->setAttribute('name', 'referrer');
        $referrer->setAttribute('content', 'no-referrer');
        $head->appendChild($referrer);

        $style = $dom->createElement('style');
        $style->appendChild($dom->createTextNode($this->previewCss()));
        $head->appendChild($style);

        $banner = $dom->createElement('div', 'TagVerity preview: move over the page and click the element you want to track.');
        $banner->setAttribute('data-tagverity-ui', 'true');
        $banner->setAttribute('class', 'tagverity-preview-banner');
        $body->insertBefore($banner, $body->firstChild);

        $script = $dom->createElement('script');
        $script->appendChild($dom->createTextNode($this->previewScript()));
        $body->appendChild($script);

        return $parser->saveHTML($dom);
    }

    private function removalQuery(): string {
        $named = static fn (array $names): string => implode(' or ', array_map(
            static fn (string $name): string => self::LOWER . "='" . $name . "'",
            $names
        ));
        $anywhere = '//*[' . $named(self::REMOVED_ELEMENTS) . ']';
        $inSvg = '//*[(' . $named(self::SVG_REMOVED_ELEMENTS) . ") and ancestor::*[" . self::LOWER . "='svg']]";
        // style inside svg: masterminds parses it as raw text, a browser parses it as markup, so it is removed.
        // Page-level style elements (outside svg) are kept.
        $svgStyle = '//*[' . self::LOWER . "='style' and ancestor::*[" . self::LOWER . "='svg']]";
        $metaRefresh = '//*[' . self::LOWER . "='meta'][translate(@http-equiv,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='refresh']";
        return implode('|', [$anywhere, $inSvg, $svgStyle, $metaRefresh]);
    }

    private function removeNodes(\DOMXPath $xpath, string $query): void {
        $nodes = [];
        foreach ($xpath->query($query) as $node) {
            $nodes[] = $node;
        }
        foreach ($nodes as $node) {
            if ($node->parentNode) $node->parentNode->removeChild($node);
        }
    }

    private function sanitiseAttributes(\DOMXPath $xpath): void {
        $elements = [];
        foreach ($xpath->query('//*') as $element) {
            $elements[] = $element;
        }
        foreach ($elements as $element) {
            // Collect the attribute nodes first so removal never mutates the list being walked.
            $attributes = [];
            foreach ($element->attributes as $attribute) {
                $attributes[] = $attribute;
            }
            foreach ($attributes as $attribute) {
                if ($this->isUnsafeAttribute($element, $attribute)) {
                    $element->removeAttributeNode($attribute);
                }
            }
            if (strtolower($element->localName ?? $element->nodeName) === 'form') {
                $element->setAttribute('action', '#');
                $element->setAttribute('method', 'get');
            }
        }
    }

    private function isUnsafeAttribute(\DOMElement $element, \DOMAttr $attribute): bool {
        $name = strtolower($attribute->name);
        $local = strtolower($attribute->localName ?? $attribute->name);
        $tag = strtolower($element->localName ?? $element->nodeName);

        if (str_starts_with($name, 'on') || str_starts_with($local, 'on')) {
            return true;
        }
        if (in_array($name, self::DROPPED_ATTRIBUTES, true) || in_array($local, self::DROPPED_ATTRIBUTES, true)) {
            return true;
        }

        if ($name === 'style' || $local === 'style') {
            $css = strtolower(preg_replace('/[\x00-\x20"\']/', '', $attribute->value) ?? '');
            return str_contains($css, 'expression(') || str_contains($css, 'url(javascript') || str_contains($css, '-moz-binding');
        }

        if (in_array($name, self::URL_ATTRIBUTES, true) || in_array($local, self::URL_ATTRIBUTES, true)) {
            $url = strtolower(preg_replace('/[\x00-\x20]/', '', $attribute->value) ?? '');
            if (preg_match('/^(javascript|vbscript|file):/', $url)) {
                return true;
            }
            if (str_starts_with($url, 'data:')) {
                return !($tag === 'img' && $name === 'src' && str_starts_with($url, 'data:image/'));
            }
        }

        return false;
    }

    private function previewCss(): string {
        return <<<'CSS'
.tagverity-preview-banner{position:sticky;top:0;z-index:2147483647;padding:10px 14px;background:#e8f0fe;color:#174ea6;border-bottom:2px solid #1a73e8;font:600 13px/1.4 Roboto,system-ui,sans-serif;text-align:center;box-shadow:0 2px 8px rgba(60,64,67,.18)}
.tagverity-preview-hover{outline:3px solid #00897b!important;outline-offset:2px!important;cursor:crosshair!important}
.tagverity-preview-selected{outline:3px solid #6750a4!important;outline-offset:2px!important;box-shadow:0 0 0 5px rgba(103,80,164,.2)!important}
CSS;
    }

    private function previewScript(): string {
        return <<<'JS'
(function () {
  'use strict';
  var hovered = null;
  var selected = null;

  function escapeCss(value) {
    if (window.CSS && CSS.escape) return CSS.escape(value);
    return String(value).replace(/[^a-zA-Z0-9_-]/g, function (char) { return '\\' + char; });
  }

  function stableValue(value) {
    return value && value.length <= 80 && !/^\d+$/.test(value) && !/[a-f0-9]{12,}/i.test(value);
  }

  function usefulClasses(element) {
    return Array.from(element.classList || []).filter(function (name) {
      return stableValue(name) && !/^(active|selected|hover|focus|open|closed|css-|sc-|tagverity-preview-)/i.test(name);
    }).slice(0, 2);
  }

  function segment(element) {
    var tag = element.tagName.toLowerCase();
    var classes = usefulClasses(element);
    var part = tag + classes.map(function (name) { return '.' + escapeCss(name); }).join('');
    var siblings = element.parentElement ? Array.from(element.parentElement.children).filter(function (item) { return item.tagName === element.tagName; }) : [];
    if (!classes.length && siblings.length > 1) part += ':nth-of-type(' + (siblings.indexOf(element) + 1) + ')';
    return part;
  }

  function selectorFor(element) {
    if (stableValue(element.id)) return '#' + escapeCss(element.id);
    var dataAttributes = ['data-testid', 'data-test', 'data-qa', 'data-id'];
    for (var i = 0; i < dataAttributes.length; i++) {
      var value = element.getAttribute(dataAttributes[i]);
      if (stableValue(value)) return element.tagName.toLowerCase() + '[' + dataAttributes[i] + '="' + String(value).replace(/"/g, '\\"') + '"]';
    }
    var parts = [];
    var current = element;
    while (current && current.nodeType === 1 && current.tagName.toLowerCase() !== 'html' && parts.length < 5) {
      parts.unshift(segment(current));
      if (stableValue(current.id)) {
        parts[0] = '#' + escapeCss(current.id);
        break;
      }
      current = current.parentElement;
    }
    return parts.join(' > ');
  }

  function cleanText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim().slice(0, 180);
  }

  function suggestedName(element) {
    var value = element.getAttribute('aria-label') || element.getAttribute('title') || element.getAttribute('alt') || cleanText(element.textContent) || element.getAttribute('placeholder') || element.getAttribute('name');
    return cleanText(value) || element.tagName.toLowerCase() + ' element';
  }

  function recommendedIntent(element, text) {
    var tag = element.tagName.toLowerCase();
    var type = (element.getAttribute('type') || '').toLowerCase();
    var signal = (text + ' ' + element.className + ' ' + element.id).toLowerCase();
    if (tag === 'form' || element.closest('form')) return 'form';
    if (type === 'search' || /search|filter|sort/.test(signal)) return 'site_search';
    if (/price|amount|currency|product[-_ ]?(name|id|title)|sku/.test(signal)) return 'ecommerce_value';
    if (tag === 'a' || tag === 'button' || type === 'button' || type === 'submit') return 'click';
    return 'visibility';
  }

  function labelFor(element) {
    if (stableValue(element.id)) {
      var explicit = document.querySelector('label[for="' + escapeCss(element.id) + '"]');
      if (explicit) return cleanText(explicit.textContent);
    }
    var wrapping = element.closest('label');
    return wrapping ? cleanText(wrapping.textContent) : '';
  }

  function payloadFor(element) {
    var text = cleanText(element.textContent);
    var form = element.tagName.toLowerCase() === 'form' ? element : element.closest('form');
    return {
      selector: selectorFor(element),
      tag: element.tagName.toLowerCase(),
      text: text,
      id: cleanText(element.id),
      class_name: cleanText(element.className),
      href: cleanText(element.getAttribute('href')),
      type: cleanText(element.getAttribute('type')),
      name: cleanText(element.getAttribute('name')),
      field_label: labelFor(element),
      autocomplete: cleanText(element.getAttribute('autocomplete')),
      form_id: form ? cleanText(form.id) : '',
      form_class_name: form ? cleanText(form.className) : '',
      form_name: form ? cleanText(form.getAttribute('name')) : '',
      form_selector: form ? selectorFor(form) : '',
      aria_label: cleanText(element.getAttribute('aria-label')),
      placeholder: cleanText(element.getAttribute('placeholder')),
      suggested_name: suggestedName(element),
      recommended_intent: recommendedIntent(element, text)
    };
  }

  document.addEventListener('mouseover', function (event) {
    var target = event.target;
    if (!(target instanceof Element) || target.closest('[data-tagverity-ui]')) return;
    if (hovered && hovered !== selected) hovered.classList.remove('tagverity-preview-hover');
    hovered = target;
    if (hovered !== selected) hovered.classList.add('tagverity-preview-hover');
  }, true);

  document.addEventListener('mouseout', function (event) {
    if (hovered && hovered !== selected) hovered.classList.remove('tagverity-preview-hover');
  }, true);

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!(target instanceof Element) || target.closest('[data-tagverity-ui]')) return;
    event.preventDefault();
    event.stopPropagation();
    var payload = payloadFor(target);
    if (selected) selected.classList.remove('tagverity-preview-selected');
    selected = target;
    selected.classList.remove('tagverity-preview-hover');
    selected.classList.add('tagverity-preview-selected');
    window.parent.postMessage({source: 'tagverity-preview', type: 'element_selected', payload: payload}, '*');
  }, true);

  document.addEventListener('submit', function (event) {
    event.preventDefault();
    event.stopPropagation();
  }, true);

  window.addEventListener('message', function (event) {
    if (!event.data || event.data.source !== 'tagverity-parent' || event.data.type !== 'highlight_selector') return;
    try {
      var match = document.querySelector(event.data.selector);
      if (!match || match.closest('[data-tagverity-ui]')) return;
      if (selected) selected.classList.remove('tagverity-preview-selected');
      selected = match;
      selected.classList.add('tagverity-preview-selected');
      selected.scrollIntoView({block: 'center', inline: 'center'});
    } catch (error) {
      return;
    }
  });

  window.parent.postMessage({source: 'tagverity-preview', type: 'preview_ready'}, '*');
}());
JS;
    }
}
