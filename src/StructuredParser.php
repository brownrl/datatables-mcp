<?php

namespace DataTablesMcp;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Parses DataTables documentation HTML to extract structured information
 */
class StructuredParser
{
    /**
     * Parse an API method page
     * 
     * @return array{
     *   signature: ?string,
     *   since_version: ?string,
     *   description: string,
     *   parameters: array,
     *   returns: ?array,
     *   examples: array,
     *   related: array,
     *   notes: array
     * }
     */
    public function parseApiPage(string $html): array
    {
        $crawler = new Crawler($html);
        
        return [
            'signature' => $this->extractSignature($crawler),
            'since_version' => $this->extractVersion($crawler),
            'description' => $this->extractDescription($crawler),
            'parameters' => $this->extractParameters($crawler),
            'returns' => $this->extractReturnType($crawler),
            'examples' => $this->extractCodeExamples($crawler),
            'related' => $this->extractRelatedItems($crawler),
            'notes' => $this->extractNotes($crawler),
        ];
    }

    /**
     * Parse an Options page
     * 
     * @return array{
     *   since_version: ?string,
     *   description: string,
     *   value_types: array,
     *   examples: array,
     *   related: array,
     *   notes: array
     * }
     */
    public function parseOptionPage(string $html): array
    {
        $crawler = new Crawler($html);
        
        return [
            'since_version' => $this->extractVersion($crawler),
            'description' => $this->extractDescription($crawler),
            'value_types' => $this->extractValueTypes($crawler),
            'examples' => $this->extractCodeExamples($crawler),
            'related' => $this->extractRelatedItems($crawler),
            'notes' => $this->extractNotes($crawler),
        ];
    }

    /**
     * Extract method signature (e.g., "ajax.reload( callback, resetPaging )")
     */
    private function extractSignature(Crawler $crawler): ?string
    {
        // Try multiple selectors for signature
        $selectors = [
            '.api-signature',
            'code.signature',
            'pre.signature',
            '.method-signature',
        ];

        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                return trim($node->text());
            }
        }

        // Fallback: look for code block near title
        $h1 = $crawler->filter('h1')->first();
        if ($h1->count() > 0) {
            $text = $h1->text();
            // If title contains parentheses, it's likely the signature
            if (preg_match('/^([a-zA-Z0-9_.()]+)\s*\(.*\)/', $text, $matches)) {
                return trim($text);
            }
        }

        return null;
    }

    /**
     * Extract version information (e.g., "Since: DataTables 1.10")
     */
    private function extractVersion(Crawler $crawler): ?string
    {
        // Look for "Since: DataTables X.X" text
        $text = $crawler->filter('body')->text();
        
        if (preg_match('/Since:\s*DataTables\s+([\d.]+)/i', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract main description/summary
     */
    private function extractDescription(Crawler $crawler): string
    {
        // DataTables has description in h2[data-anchor="Description"] section
        $descHeading = $crawler->filter('h2[data-anchor="Description"]')->first();
        
        if ($descHeading->count() > 0) {
            // Get the next paragraph sibling
            $next = $descHeading->nextAll()->filter('p')->first();
            if ($next->count() > 0) {
                return trim($next->text());
            }
        }

        // Fallback: look for .reference-description or first meaningful paragraph
        $selectors = [
            '.reference-description p:first-child',
            '.description p:first-child',
            '.doc-content > p:first-child',
        ];

        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                $text = trim($node->text());
                if (strlen($text) > 50) {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * Extract parameters from API method
     * 
     * @return array<array{position: int, name: string, type: string, optional: bool, default: ?string, description: string}>
     */
    private function extractParameters(Crawler $crawler): array
    {
        $parameters = [];

        // Look for parameters table (DataTables uses table.parameters)
        $table = $crawler->filter('table.parameters')->first();
        
        if ($table->count() === 0) {
            return $parameters;
        }

        $rows = $table->filter('tbody tr');
        $currentParam = null;

        foreach ($rows as $row) {
            $rowCrawler = new Crawler($row);
            
            // Check if this is a continuation row (description)
            $classes = $rowCrawler->attr('class') ?? '';
            if (strpos($classes, 'continuation') !== false) {
                // This is a description row
                if ($currentParam !== null) {
                    $descCell = $rowCrawler->filter('td')->last();
                    if ($descCell->count() > 0) {
                        $currentParam['description'] = trim($descCell->text());
                    }
                    $parameters[] = $currentParam;
                    $currentParam = null;
                }
                continue;
            }

            // This is a parameter definition row
            $cells = $rowCrawler->filter('td');

            if ($cells->count() < 4) {
                continue; // Not enough cells
            }

            $position = (int) trim($cells->eq(0)->text());
            $name = trim($cells->eq(1)->filter('code')->text());
            $type = trim($cells->eq(2)->filter('code')->text());
            $optionalText = trim($cells->eq(3)->text());

            // Parse optional and default
            $optional = strpos($optionalText, 'Yes') !== false;
            $default = null;
            
            if (preg_match('/default:\s*([^\s]+)/i', $optionalText, $matches)) {
                $default = $matches[1];
            }

            $currentParam = [
                'position' => $position,
                'name' => $name,
                'type' => $type,
                'optional' => $optional,
                'default' => $default,
                'description' => '',
            ];
        }

        // Add last parameter if no continuation row followed
        if ($currentParam !== null) {
            $parameters[] = $currentParam;
        }

        return $parameters;
    }

    /**
     * Extract return type information
     * 
     * @return ?array{type: string, description: string}
     */
    private function extractReturnType(Crawler $crawler): ?array
    {
        // Look for "Returns" section
        $headings = $crawler->filter('h2, h3, h4, strong');
        
        foreach ($headings as $heading) {
            $headingCrawler = new Crawler($heading);
            $text = $headingCrawler->text();
            
            if (preg_match('/^Returns?:?$/i', trim($text))) {
                // Get next sibling or paragraph
                $next = $headingCrawler->nextAll()->first();
                if ($next->count() > 0) {
                    $returnText = trim($next->text());
                    
                    // Try to parse type from text
                    if (preg_match('/^([A-Za-z0-9_.]+)/', $returnText, $matches)) {
                        return [
                            'type' => $matches[1],
                            'description' => $returnText,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract code examples
     * 
     * @return array<array{title: ?string, code: string, language: string}>
     */
    private function extractCodeExamples(Crawler $crawler): array
    {
        $examples = [];
        
        // DataTables uses .reference_example blocks
        $exampleBlocks = $crawler->filter('.reference_example');
        
        if ($exampleBlocks->count() > 0) {
            foreach ($exampleBlocks as $exampleBlock) {
                $blockCrawler = new Crawler($exampleBlock);
                
                // Get title from .title div
                $title = null;
                $titleNode = $blockCrawler->filter('.title p')->first();
                if ($titleNode->count() > 0) {
                    $title = trim($titleNode->text());
                }
                
                // Get code from pre code
                $codeNode = $blockCrawler->filter('pre code')->first();
                if ($codeNode->count() > 0) {
                    $code = trim($codeNode->text());
                    
                    // Detect language from class
                    $language = 'javascript';
                    $classes = $codeNode->attr('class') ?? '';
                    if (strpos($classes, 'language-js') !== false) {
                        $language = 'javascript';
                    } elseif (strpos($classes, 'language-html') !== false) {
                        $language = 'html';
                    } elseif (strpos($classes, 'language-css') !== false) {
                        $language = 'css';
                    }
                    
                    $examples[] = [
                        'title' => $title,
                        'code' => $code,
                        'language' => $language,
                    ];
                }
            }
        }

        return $examples;
    }

    /**
     * Extract related items (API methods, options, events)
     * 
     * @return array{API: array<string>, Options: array<string>, Events: array<string>}
     */
    private function extractRelatedItems(Crawler $crawler): array
    {
        $related = [
            'API' => [],
            'Options' => [],
            'Events' => [],
        ];

        // DataTables uses .reference_related divs with category as text + ul
        $relatedBlocks = $crawler->filter('.reference_related');
        
        foreach ($relatedBlocks as $block) {
            $blockCrawler = new Crawler($block);
            
            // Get category name (text node before ul)
            $html = $blockCrawler->html();
            $category = null;
            
            // Extract text before <ul> tag
            if (preg_match('/^([A-Za-z]+)<ul/', $html, $matches)) {
                $category = trim($matches[1]);
            }
            
            // Get all links in this block
            $links = $blockCrawler->filter('ul li a code');
            $items = [];
            
            foreach ($links as $link) {
                $items[] = trim((new Crawler($link))->text());
            }
            
            // Map category name to our keys
            if ($category === 'API' && !empty($items)) {
                $related['API'] = $items;
            } elseif ($category === 'Options' && !empty($items)) {
                $related['Options'] = $items;
            } elseif ($category === 'Events' && !empty($items)) {
                $related['Events'] = $items;
            }
        }

        return $related;
    }

    /**
     * Extract notes and caveats (warnings, important notices)
     * 
     * @return array<string>
     */
    private function extractNotes(Crawler $crawler): array
    {
        $notes = [];

        // Look for bold text, warnings, or note sections
        $boldText = $crawler->filter('strong, b, .warning, .note, .important');
        
        foreach ($boldText as $node) {
            $nodeCrawler = new Crawler($node);
            $text = trim($nodeCrawler->text());
            
            // Look for warning keywords
            if (preg_match('/\b(note|warning|important|caution|deprecated)\b/i', $text)) {
                $notes[] = $text;
            }
        }

        return array_unique($notes);
    }

    /**
     * Extract value types for options (string, object, function, etc.)
     * 
     * @return array<array{type: string, description: string, sub_properties: array}>
     */
    private function extractValueTypes(Crawler $crawler): array
    {
        $types = [];

        // Look for type sections (usually h2 or h3 with type names)
        $headings = $crawler->filter('h2, h3');
        
        foreach ($headings as $heading) {
            $headingCrawler = new Crawler($heading);
            $typeText = trim($headingCrawler->text());
            
            // Check if heading is a type name
            if (preg_match('/^(string|boolean|integer|number|object|function|array)$/i', $typeText)) {
                // Get description from following paragraphs
                $description = '';
                $next = $headingCrawler->nextAll()->filter('p')->first();
                if ($next->count() > 0) {
                    $description = trim($next->text());
                }

                $types[] = [
                    'type' => strtolower($typeText),
                    'description' => $description,
                    'sub_properties' => [], // TODO: extract sub-properties for object types
                ];
            }
        }

        return $types;
    }
}
