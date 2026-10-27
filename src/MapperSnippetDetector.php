<?php
namespace GTMAuditor;

final class MapperSnippetDetector {
    public function detect(string $html): bool { return str_contains($html, 'assets/js/mapper-snippet.js'); }
}
