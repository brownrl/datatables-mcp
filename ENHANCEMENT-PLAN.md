# DataTables MCP Enhancement Plan

Based on expert MCP recommendations and analysis of DataTables.net page structure.

## Current State

### What We Have
- **1,206 documents indexed**: 938 reference, 120 examples, 86 extensions, 62 manual
- **Basic FTS5 search**: Single query mode, returns raw text blobs
- **Simple schema**: title, url, content, section, doc_type
- **One MCP tool**: `search_datatables(query, limit)`
- **Raw text responses**: No structured data, agents must parse prose

### What's Missing
- ❌ No structured parameter/type extraction
- ❌ No separate code example storage
- ❌ No cross-reference mapping
- ❌ No topic/function-based search
- ❌ No diagnostic/linting capability
- ❌ No "why" explanations (caveats/notes)

## Page Structure Analysis

### API Reference Pages (309 pages)

**Highly structured with consistent elements**:

```
Title: ajax.reload()
├── Method signature: ajax.reload( callback, resetPaging )
├── Version: "Since: DataTables 1.10"
├── Description (brief + detailed prose)
├── Parameters (numbered, structured):
│   ├── 1. callback (function, optional, default: null)
│   │   └── Description with expected behavior
│   └── 2. resetPaging (boolean, optional, default: true)
│       └── Description of true/false behavior
├── Returns: DataTables.Api (with description)
├── Examples (typically 2-3):
│   ├── Example 1: "Reload every 30 seconds"
│   ├── Example 2: "Reload without resetting page"
│   └── Example 3: "Using callback"
└── Related (categorized):
    ├── API: [ajax.json(), ajax.url(), ajax.url().load()]
    ├── Events: [xhr]
    └── Options: [ajax]
```

**CSS Selectors for Extraction**:
- Signature: `h2:contains("Type") ~ h3`
- Parameters: `h4:contains("Parameters:") ~ content` (numbered 1, 2, 3...)
- Returns: `h4:contains("Returns:") ~ content`
- Examples: `h2:contains("Examples") ~ pre.multiline.js`
- Related: `h2:contains("Related") ~ ul li a`

### Options Reference Pages (386 pages)

**More complex due to multiple value types**:

```
Title: ajax
├── Option name: ajax
├── Version: "Since: DataTables 1.10"
├── Description (brief + detailed prose)
├── Types (multiple value types accepted):
│   ├── string:
│   │   ├── Description: "URL to load data from"
│   │   └── Examples (2)
│   ├── object:
│   │   ├── Description: "jQuery.ajax configuration"
│   │   ├── Sub-properties:
│   │   │   ├── data → link to ajax.data
│   │   │   ├── dataSrc → link to ajax.dataSrc
│   │   │   ├── submitAs → link to ajax.submitAs
│   │   │   └── success (warning: do not override)
│   │   └── Example (1)
│   └── function (signature: ajax( data, callback, settings )):
│       ├── Description: "Custom Ajax function"
│       ├── Parameters table (3 params):
│       │   ├── data (object, required)
│       │   ├── callback (function, required)
│       │   └── settings (DataTables.Settings, required)
│       └── Example (1)
├── Notes/Caveats (inline):
│   ├── "DataTables expects array in data parameter"
│   ├── "Empty string supported in DataTables 2"
│   └── ".NET handling automatic as of 2.1"
├── Examples (8 dedicated examples showing various patterns)
└── Related (categorized):
    ├── API: [ajax.json(), ajax.reload(), ajax.url(), ajax.url().load()]
    └── Options: [serverSide, ajax.data, ajax.dataSrc, ajax.submitAs]
```

**CSS Selectors for Extraction**:
- Types section: `h2:contains("Types") ~ h3` (string, object, function)
- Sub-properties: Bullet lists under object type
- Function parameters: `h4:contains("Parameters:") ~ table/content`
- Inline warnings: Bold text with `**Must _not_ be overridden**`
- Examples: `h2:contains("Examples") ~ pre.multiline.js`

### Key Parsing Insights

✅ **Consistent heading hierarchy**: h1 (title) → h2 (sections) → h3 (subsections) → h4 (details)
✅ **Semantic structure**: Description, Types, Parameters, Examples, Related always present
✅ **Type information**: Linked references to type definitions
✅ **Version metadata**: "Since: DataTables X.X" pattern
✅ **Code examples**: Marked with `multiline js` class
✅ **Cross-references**: Consistent URL patterns for API/Options/Events
✅ **Inline caveats**: Bold text for warnings, nested bullets for notes

## Enhancement Phases

### Phase 1: Structured Data Extraction (Foundation) 🎯

**Goal**: Extract structured fields instead of raw text blobs.

**Database Schema Changes**:

```sql
-- Add columns to documentation table
ALTER TABLE documentation ADD COLUMN signature TEXT;
ALTER TABLE documentation ADD COLUMN since_version TEXT;
ALTER TABLE documentation ADD COLUMN description TEXT;
ALTER TABLE documentation ADD COLUMN raw_html TEXT;

-- Create new tables for structured data
CREATE TABLE parameters (
    id INTEGER PRIMARY KEY,
    doc_id INTEGER,
    position INTEGER,
    name TEXT,
    type TEXT,
    optional BOOLEAN,
    default_value TEXT,
    description TEXT,
    FOREIGN KEY (doc_id) REFERENCES documentation(id)
);

CREATE TABLE return_types (
    id INTEGER PRIMARY KEY,
    doc_id INTEGER,
    type TEXT,
    description TEXT,
    FOREIGN KEY (doc_id) REFERENCES documentation(id)
);

CREATE TABLE code_examples (
    id INTEGER PRIMARY KEY,
    doc_id INTEGER,
    title TEXT,
    code TEXT,
    language TEXT,
    position INTEGER,
    FOREIGN KEY (doc_id) REFERENCES documentation(id)
);

CREATE TABLE related_items (
    id INTEGER PRIMARY KEY,
    doc_id INTEGER,
    related_doc_id INTEGER,
    category TEXT, -- 'API', 'Options', 'Events', 'Types'
    FOREIGN KEY (doc_id) REFERENCES documentation(id),
    FOREIGN KEY (related_doc_id) REFERENCES documentation(id)
);

CREATE TABLE value_types (
    id INTEGER PRIMARY KEY,
    doc_id INTEGER,
    type_name TEXT, -- 'string', 'object', 'function', etc.
    description TEXT,
    signature TEXT, -- For function types
    position INTEGER,
    FOREIGN KEY (doc_id) REFERENCES documentation(id)
);

CREATE TABLE value_type_parameters (
    id INTEGER PRIMARY KEY,
    value_type_id INTEGER,
    position INTEGER,
    name TEXT,
    type TEXT,
    optional BOOLEAN,
    description TEXT,
    FOREIGN KEY (value_type_id) REFERENCES value_types(id)
);

CREATE TABLE sub_properties (
    id INTEGER PRIMARY KEY,
    doc_id INTEGER,
    parent_option TEXT, -- e.g., 'ajax'
    property_name TEXT, -- e.g., 'data'
    full_option_path TEXT, -- e.g., 'ajax.data'
    linked_doc_id INTEGER,
    description TEXT,
    FOREIGN KEY (doc_id) REFERENCES documentation(id),
    FOREIGN KEY (linked_doc_id) REFERENCES documentation(id)
);

CREATE TABLE notes_caveats (
    id INTEGER PRIMARY KEY,
    doc_id INTEGER,
    type TEXT, -- 'warning', 'note', 'caveat', 'limitation'
    content TEXT,
    position INTEGER,
    FOREIGN KEY (doc_id) REFERENCES documentation(id)
);

-- Create indexes for performance
CREATE INDEX idx_parameters_doc_id ON parameters(doc_id);
CREATE INDEX idx_return_types_doc_id ON return_types(doc_id);
CREATE INDEX idx_code_examples_doc_id ON code_examples(doc_id);
CREATE INDEX idx_related_items_doc_id ON related_items(doc_id);
CREATE INDEX idx_related_items_related_doc_id ON related_items(related_doc_id);
CREATE INDEX idx_value_types_doc_id ON value_types(doc_id);
CREATE INDEX idx_value_type_parameters_type_id ON value_type_parameters(value_type_id);
CREATE INDEX idx_sub_properties_doc_id ON sub_properties(doc_id);
CREATE INDEX idx_sub_properties_linked_doc_id ON sub_properties(linked_doc_id);
CREATE INDEX idx_notes_caveats_doc_id ON notes_caveats(doc_id);
```

**New PHP Class**: `src/StructuredParser.php`

```php
<?php

namespace DataTablesMcp;

use Symfony\Component\DomCrawler\Crawler;

class StructuredParser
{
    /**
     * Parse an API method page
     */
    public function parseApiPage(Crawler $crawler): array
    {
        return [
            'signature' => $this->extractSignature($crawler),
            'since_version' => $this->extractVersion($crawler),
            'description' => $this->extractDescription($crawler),
            'parameters' => $this->extractParameters($crawler),
            'returns' => $this->extractReturnType($crawler),
            'examples' => $this->extractCodeExamples($crawler),
            'related' => $this->extractRelatedItems($crawler),
            'notes' => $this->extractNotesCaveats($crawler),
        ];
    }

    /**
     * Parse an option page
     */
    public function parseOptionPage(Crawler $crawler): array
    {
        return [
            'since_version' => $this->extractVersion($crawler),
            'description' => $this->extractDescription($crawler),
            'value_types' => $this->extractValueTypes($crawler),
            'sub_properties' => $this->extractSubProperties($crawler),
            'examples' => $this->extractCodeExamples($crawler),
            'related' => $this->extractRelatedItems($crawler),
            'notes' => $this->extractNotesCaveats($crawler),
        ];
    }

    private function extractSignature(Crawler $crawler): ?string
    {
        // Look for h3 under "Type" section with method signature
        // Pattern: "ajax.reload( callback, resetPaging )"
    }

    private function extractVersion(Crawler $crawler): ?string
    {
        // Find text matching "Since: DataTables X.X"
        // Extract version number
    }

    private function extractParameters(Crawler $crawler): array
    {
        // Find "Parameters:" h4, extract numbered list
        // Return: [{position: 1, name: 'callback', type: 'function', optional: true, default: 'null', description: '...'}]
    }

    private function extractValueTypes(Crawler $crawler): array
    {
        // Find h3 under "Types" section (string, object, function)
        // For function types, extract signature and parameters
        // Return: [{type: 'string', description: '...', examples: [...]}]
    }

    private function extractCodeExamples(Crawler $crawler): array
    {
        // Find all pre.multiline.js elements
        // Extract preceding descriptive text as title
        // Return: [{title: 'Reload every 30 seconds', code: '...', language: 'js', position: 1}]
    }

    private function extractRelatedItems(Crawler $crawler): array
    {
        // Find "Related" section, group by category (API, Options, Events)
        // Extract links and categorize
        // Return: [{category: 'API', items: ['ajax.json()', 'ajax.url()']}]
    }

    private function extractSubProperties(Crawler $crawler): array
    {
        // Find bullet lists under object type
        // Extract: data ( ajax.data ) - description
        // Return: [{name: 'data', full_path: 'ajax.data', linked_url: '...', description: '...'}]
    }

    private function extractNotesCaveats(Crawler $crawler): array
    {
        // Find bold warnings, "Note:" paragraphs, inline caveats
        // Return: [{type: 'warning', content: 'Must not be overridden', position: 1}]
    }
}
```

**Update DocumentationIndexer.php**:

```php
private function parseAndStoreReferencePage(string $html, string $url, string $category): void
{
    $crawler = new Crawler($html);
    
    // Existing extraction
    $title = $crawler->filter('h1')->first()->text();
    $content = $crawler->filter('.content')->text(); // Keep for FTS5
    
    // NEW: Structured extraction
    $parser = new StructuredParser();
    
    if ($category === 'API') {
        $structured = $parser->parseApiPage($crawler);
    } elseif ($category === 'Options') {
        $structured = $parser->parseOptionPage($crawler);
    } else {
        $structured = []; // Events, Types, etc. - simpler structure
    }
    
    // Store main document with new fields
    $docId = $this->storeDocument(
        $title, 
        $url, 
        $content, 
        $category, 
        'reference',
        $structured['signature'] ?? null,
        $structured['since_version'] ?? null,
        $structured['description'] ?? null,
        $html // Store raw HTML for future re-parsing
    );
    
    // Store structured data
    $this->storeParameters($docId, $structured['parameters'] ?? []);
    $this->storeReturnTypes($docId, $structured['returns'] ?? []);
    $this->storeCodeExamples($docId, $structured['examples'] ?? []);
    $this->storeValueTypes($docId, $structured['value_types'] ?? []);
    $this->storeSubProperties($docId, $structured['sub_properties'] ?? []);
    $this->storeNotesCaveats($docId, $structured['notes'] ?? []);
    
    // Store related items (requires URL → doc_id resolution)
    $this->storeRelatedItems($docId, $structured['related'] ?? []);
}
```

**Effort**: 2-3 days
**Impact**: Foundation for all other enhancements

---

### Phase 2: Multiple Search Modes 🔍

**Goal**: Add topic-based and function-based search beyond FTS5.

**New MCP Tools**:

```json
{
  "name": "search_by_topic",
  "description": "Search DataTables documentation by topic/category",
  "inputSchema": {
    "type": "object",
    "properties": {
      "category": {
        "type": "string",
        "enum": ["API", "Options", "Events", "Types", "Buttons", "Features"],
        "description": "Documentation category"
      },
      "section": {
        "type": "string",
        "description": "Optional subsection (e.g., 'ajax', 'columns', 'styling')"
      },
      "limit": {"type": "number", "default": 10}
    },
    "required": ["category"]
  }
}

{
  "name": "search_by_function",
  "description": "Search for specific API methods or options by name",
  "inputSchema": {
    "type": "object",
    "properties": {
      "name": {
        "type": "string",
        "description": "Method or option name (e.g., 'ajax.reload', 'columns.render')"
      },
      "exact_match": {"type": "boolean", "default": false}
    },
    "required": ["name"]
  }
}

{
  "name": "get_examples",
  "description": "Get code examples for a specific feature or task",
  "inputSchema": {
    "type": "object",
    "properties": {
      "query": {
        "type": "string",
        "description": "What you want to do (e.g., 'reload table via ajax', 'custom button')"
      },
      "limit": {"type": "number", "default": 5}
    },
    "required": ["query"]
  }
}
```

**Implementation in SearchEngine.php**:

```php
public function searchByTopic(string $category, ?string $section = null, int $limit = 10): array
{
    $sql = "
        SELECT d.*, 
               p.name as param_names, 
               rt.type as return_type,
               (SELECT GROUP_CONCAT(title, '\n---\n') FROM code_examples WHERE doc_id = d.id) as examples
        FROM documentation d
        LEFT JOIN parameters p ON p.doc_id = d.id
        LEFT JOIN return_types rt ON rt.doc_id = d.id
        WHERE d.doc_type = 'reference' 
        AND d.section = :category
    ";
    
    if ($section) {
        $sql .= " AND d.title LIKE :section";
    }
    
    $sql .= " GROUP BY d.id ORDER BY d.title LIMIT :limit";
    
    // Execute and return
}

public function searchByFunction(string $name, bool $exactMatch = false): array
{
    if ($exactMatch) {
        $sql = "SELECT * FROM documentation WHERE title = :name";
    } else {
        $sql = "SELECT * FROM documentation WHERE title LIKE :name";
        $name = "%{$name}%";
    }
    
    // Execute and return with joined structured data
}

public function searchExamples(string $query, int $limit = 5): array
{
    // FTS5 search on code_examples table
    // Return examples with context (which page they're from)
    
    $sql = "
        SELECT ce.title, ce.code, ce.language, d.title as page_title, d.url
        FROM code_examples ce
        JOIN documentation d ON d.id = ce.doc_id
        WHERE ce.code LIKE :query OR ce.title LIKE :query
        ORDER BY ce.position
        LIMIT :limit
    ";
}
```

**Effort**: 1 day
**Impact**: High - agents can navigate docs by intent, not just keywords

---

### Phase 3: Structured JSON Responses 📊

**Goal**: Return structured data instead of text blobs.

**Current Response** (raw text):
```json
{
  "title": "ajax.reload()",
  "url": "https://datatables.net/reference/api/ajax.reload()",
  "content": "DataTables Advanced interaction Features...{3000 chars of text}...",
  "section": "API",
  "doc_type": "reference",
  "rank": -0.5
}
```

**Enhanced Response** (structured):
```json
{
  "title": "ajax.reload()",
  "url": "https://datatables.net/reference/api/ajax.reload()",
  "type": "API method",
  "signature": "ajax.reload( callback, resetPaging )",
  "since_version": "1.10",
  "description": "Reload the table data from the Ajax data source. Note that this function will automatically re-sort and re-filter the table, and will display the 'processing' show if enabled.",
  "parameters": [
    {
      "position": 1,
      "name": "callback",
      "type": "function",
      "optional": true,
      "default": "null",
      "description": "Function to execute when the reload has completed. The function is passed two parameters, the JSON from the server and the DataTable's settings object."
    },
    {
      "position": 2,
      "name": "resetPaging",
      "type": "boolean",
      "optional": true,
      "default": "true",
      "description": "When true the current paging will be reset. Use this if you don't want the table to re-paginate after reloading."
    }
  ],
  "returns": {
    "type": "DataTables.Api",
    "description": "DataTables API instance for chaining"
  },
  "examples": [
    {
      "title": "Reload the table data every 30 seconds",
      "code": "var table = $('#myTable').DataTable();\n\nsetInterval( function () {\n    table.ajax.reload();\n}, 30000 );",
      "language": "js"
    },
    {
      "title": "Reload without resetting current page",
      "code": "var table = $('#myTable').DataTable();\n\ntable.ajax.reload( null, false );",
      "language": "js"
    }
  ],
  "related": {
    "API": ["ajax.json()", "ajax.url()", "ajax.url().load()"],
    "Events": ["xhr"],
    "Options": ["ajax"]
  },
  "notes": [
    {
      "type": "note",
      "content": "A full re-sort and re-filter is performed when this method is called, which is why the pagination reset is the default action."
    }
  ],
  "full_text": "DataTables Advanced interaction Features...{original text for context}"
}
```

**Implementation in McpServer.php**:

```php
private function formatSearchResults(array $results): array
{
    $formatted = [];
    
    foreach ($results as $row) {
        $docId = $row['id'];
        
        // Base document
        $doc = [
            'title' => $row['title'],
            'url' => $row['url'],
            'type' => $row['doc_type'],
            'section' => $row['section'],
        ];
        
        // Add structured fields if reference doc
        if ($row['doc_type'] === 'reference') {
            $doc['signature'] = $row['signature'];
            $doc['since_version'] = $row['since_version'];
            $doc['description'] = $row['description'];
            
            // Join with related tables
            $doc['parameters'] = $this->searchEngine->getParameters($docId);
            $doc['returns'] = $this->searchEngine->getReturnTypes($docId);
            $doc['examples'] = $this->searchEngine->getCodeExamples($docId);
            $doc['related'] = $this->searchEngine->getRelatedItems($docId);
            $doc['notes'] = $this->searchEngine->getNotesCaveats($docId);
            
            // For options, include value types
            if ($row['section'] === 'Options') {
                $doc['value_types'] = $this->searchEngine->getValueTypes($docId);
                $doc['sub_properties'] = $this->searchEngine->getSubProperties($docId);
            }
        }
        
        // Keep full text for context
        $doc['full_text'] = $row['content'];
        
        $formatted[] = $doc;
    }
    
    return $formatted;
}
```

**Effort**: 1-2 days
**Impact**: Very High - agents can reason directly with structured data

---

### Phase 4: Cross-Reference Tools 🔗

**Goal**: Help agents navigate complex interdependencies.

**New MCP Tools**:

```json
{
  "name": "find_related",
  "description": "Find all documentation related to a specific item",
  "inputSchema": {
    "type": "object",
    "properties": {
      "item": {
        "type": "string",
        "description": "API method, option, or event name"
      },
      "relationship_type": {
        "type": "string",
        "enum": ["all", "API", "Options", "Events", "Types"],
        "default": "all"
      }
    },
    "required": ["item"]
  }
}

{
  "name": "find_by_tag",
  "description": "Find documentation by topic tags",
  "inputSchema": {
    "type": "object",
    "properties": {
      "tags": {
        "type": "array",
        "items": {"type": "string"},
        "description": "Topic tags (e.g., ['sorting', 'ajax', 'pagination'])"
      },
      "match_all": {
        "type": "boolean",
        "default": false,
        "description": "If true, requires all tags to match"
      }
    },
    "required": ["tags"]
  }
}
```

**Database Schema Addition**:

```sql
CREATE TABLE tags (
    id INTEGER PRIMARY KEY,
    name TEXT UNIQUE
);

CREATE TABLE doc_tags (
    doc_id INTEGER,
    tag_id INTEGER,
    PRIMARY KEY (doc_id, tag_id),
    FOREIGN KEY (doc_id) REFERENCES documentation(id),
    FOREIGN KEY (tag_id) REFERENCES tags(id)
);

-- Populate with common tags
INSERT INTO tags (name) VALUES 
    ('ajax'), ('sorting'), ('filtering'), ('pagination'), 
    ('styling'), ('columns'), ('rows'), ('events'), 
    ('server-side'), ('data-sources'), ('rendering'),
    ('buttons'), ('search'), ('responsive'), ('export');
```

**Implementation**:

```php
public function findRelated(string $item, ?string $relationshipType = null): array
{
    // Find document ID for item
    $doc = $this->findByTitle($item);
    
    if (!$doc) {
        return [];
    }
    
    $sql = "
        SELECT d.*, ri.category
        FROM related_items ri
        JOIN documentation d ON d.id = ri.related_doc_id
        WHERE ri.doc_id = :doc_id
    ";
    
    if ($relationshipType && $relationshipType !== 'all') {
        $sql .= " AND ri.category = :category";
    }
    
    // Execute and return
}

public function findByTags(array $tags, bool $matchAll = false): array
{
    if ($matchAll) {
        // Require all tags
        $sql = "
            SELECT d.*, GROUP_CONCAT(t.name) as tags
            FROM documentation d
            JOIN doc_tags dt ON dt.doc_id = d.id
            JOIN tags t ON t.id = dt.tag_id
            WHERE t.name IN (" . implode(',', array_fill(0, count($tags), '?')) . ")
            GROUP BY d.id
            HAVING COUNT(DISTINCT t.name) = ?
        ";
        // Bind tags + count
    } else {
        // Match any tag
        $sql = "
            SELECT d.*, GROUP_CONCAT(t.name) as tags
            FROM documentation d
            JOIN doc_tags dt ON dt.doc_id = d.id
            JOIN tags t ON t.id = dt.tag_id
            WHERE t.name IN (" . implode(',', array_fill(0, count($tags), '?')) . ")
            GROUP BY d.id
        ";
    }
}
```

**Tag Auto-Population**:

During indexing, automatically tag documents based on:
- URL patterns: `/reference/api/ajax.*` → tag: 'ajax'
- Title patterns: "columns.*" → tag: 'columns'
- Content analysis: mentions of "server-side", "responsive", etc.

**Effort**: 2 days
**Impact**: Medium-High - helps agents navigate complex configurations

---

### Phase 5: Enhanced Example Extraction 💡

**Goal**: Make code examples first-class citizens.

**Database Enhancement**:

```sql
-- Add to code_examples table
ALTER TABLE code_examples ADD COLUMN html TEXT;
ALTER TABLE code_examples ADD COLUMN css TEXT;
ALTER TABLE code_examples ADD COLUMN description TEXT;
ALTER TABLE code_examples ADD COLUMN tags TEXT; -- JSON array

-- Create FTS5 index on examples
CREATE VIRTUAL TABLE code_examples_fts USING fts5(
    title, description, code,
    content='code_examples',
    content_rowid='id'
);
```

**New MCP Tool**:

```json
{
  "name": "find_examples_like",
  "description": "Find code examples similar to a description or code snippet",
  "inputSchema": {
    "type": "object",
    "properties": {
      "description": {
        "type": "string",
        "description": "What you're trying to do (e.g., 'reload table every minute')"
      },
      "code_snippet": {
        "type": "string",
        "description": "Optional code snippet to match against"
      },
      "language": {
        "type": "string",
        "enum": ["js", "html", "css"],
        "default": "js"
      },
      "limit": {"type": "number", "default": 5}
    }
  }
}
```

**Implementation**:

```php
public function findExamplesLike(string $description, ?string $codeSnippet = null, string $language = 'js', int $limit = 5): array
{
    if ($codeSnippet) {
        // Hybrid search: match description OR code similarity
        $sql = "
            SELECT ce.*, d.title as page_title, d.url, d.section,
                   rank
            FROM code_examples_fts fts
            JOIN code_examples ce ON ce.id = fts.rowid
            JOIN documentation d ON d.id = ce.doc_id
            WHERE (
                code_examples_fts MATCH :description
                OR code_examples_fts MATCH :code_snippet
            )
            AND ce.language = :language
            ORDER BY rank
            LIMIT :limit
        ";
    } else {
        // Description-only search
        $sql = "
            SELECT ce.*, d.title as page_title, d.url, d.section
            FROM code_examples_fts fts
            JOIN code_examples ce ON ce.id = fts.rowid
            JOIN documentation d ON d.id = ce.doc_id
            WHERE code_examples_fts MATCH :description
            AND ce.language = :language
            ORDER BY rank
            LIMIT :limit
        ";
    }
}
```

**Effort**: 1 day
**Impact**: High - agents often need examples more than docs

---

### Phase 6: Diagnostic/Linting Tool 🔧

**Goal**: Turn MCP from "documentation" → "domain expert".

**New MCP Tool**:

```json
{
  "name": "diagnose_config",
  "description": "Analyze a DataTables configuration for common issues",
  "inputSchema": {
    "type": "object",
    "properties": {
      "config": {
        "type": "object",
        "description": "DataTables configuration object (JSON)"
      },
      "version": {
        "type": "string",
        "description": "DataTables version (e.g., '1.10', '2.0')",
        "default": "2.0"
      }
    },
    "required": ["config"]
  }
}
```

**Response Example**:

```json
{
  "valid": false,
  "issues": [
    {
      "severity": "error",
      "option": "ajax",
      "message": "When using serverSide: true, ajax must be configured",
      "documentation": "https://datatables.net/reference/option/ajax"
    },
    {
      "severity": "warning",
      "option": "columns.data",
      "message": "columns.data is set but no ajax source configured. Data must be provided via 'data' option.",
      "documentation": "https://datatables.net/reference/option/columns.data"
    },
    {
      "severity": "deprecated",
      "option": "aoColumns",
      "message": "aoColumns is deprecated. Use 'columns' instead.",
      "since_version": "1.10",
      "documentation": "https://datatables.net/upgrade/1.10-convert"
    }
  ],
  "suggestions": [
    {
      "option": "serverSide",
      "message": "Consider enabling serverSide for large datasets (>10,000 rows)",
      "documentation": "https://datatables.net/reference/option/serverSide"
    }
  ]
}
```

**Implementation**:

```php
class ConfigDiagnostic
{
    private array $rules = [
        'ajax_requires_server_side' => [
            'condition' => fn($config) => isset($config['serverSide']) && $config['serverSide'] && empty($config['ajax']),
            'severity' => 'error',
            'message' => 'When using serverSide: true, ajax must be configured',
            'doc_link' => '/reference/option/ajax'
        ],
        'deprecated_ao_prefix' => [
            'pattern' => '/^ao[A-Z]/',
            'severity' => 'deprecated',
            'message' => fn($key) => "$key is deprecated. Use camelCase version instead.",
            'since_version' => '1.10'
        ],
        'conflicting_data_sources' => [
            'condition' => fn($config) => isset($config['ajax']) && isset($config['data']),
            'severity' => 'warning',
            'message' => 'Both ajax and data are set. ajax will take precedence.',
            'doc_link' => '/reference/option/ajax'
        ],
        // Add 20+ more rules based on common mistakes
    ];

    public function diagnose(array $config, string $version = '2.0'): array
    {
        $issues = [];
        $suggestions = [];
        
        foreach ($this->rules as $ruleName => $rule) {
            // Check condition or pattern
            // Generate issue/suggestion
        }
        
        return [
            'valid' => empty(array_filter($issues, fn($i) => $i['severity'] === 'error')),
            'issues' => $issues,
            'suggestions' => $suggestions
        ];
    }
}
```

**Effort**: 2-3 days (building comprehensive rule set)
**Impact**: Very High - prevents common mistakes, saves agent debugging time

---

### Phase 7: Explain Tool (The "Why") 💬

**Goal**: Extract and expose the reasoning behind features.

**Database Enhancement**:

```sql
-- Already have notes_caveats table, enhance it
ALTER TABLE notes_caveats ADD COLUMN extracted_from TEXT; -- Which section it came from
ALTER TABLE notes_caveats ADD COLUMN severity TEXT; -- 'info', 'warning', 'critical'

-- Create FTS5 index on notes
CREATE VIRTUAL TABLE notes_fts USING fts5(
    content,
    content='notes_caveats',
    content_rowid='id'
);
```

**New MCP Tool**:

```json
{
  "name": "explain_feature",
  "description": "Explain why a feature exists, its trade-offs, and best practices",
  "inputSchema": {
    "type": "object",
    "properties": {
      "feature": {
        "type": "string",
        "description": "Feature, option, or API method name"
      },
      "aspect": {
        "type": "string",
        "enum": ["why", "when", "tradeoffs", "limitations", "best-practices"],
        "default": "why",
        "description": "What aspect to explain"
      }
    },
    "required": ["feature"]
  }
}
```

**Response Example**:

```json
{
  "feature": "serverSide",
  "aspect": "why",
  "explanation": {
    "why_exists": "Server-side processing offloads sorting, filtering, and pagination to the server, enabling DataTables to work with datasets of millions of rows without loading all data into the browser.",
    "when_to_use": [
      "Datasets larger than 10,000 rows",
      "Data that changes frequently (real-time updates)",
      "When client-side memory is constrained",
      "When you need server-side data validation/security"
    ],
    "tradeoffs": [
      {
        "pro": "Handles unlimited data size",
        "con": "Requires server-side implementation (more complex)"
      },
      {
        "pro": "Reduced initial page load time",
        "con": "Network latency on every interaction (sort/filter/page)"
      }
    ],
    "limitations": [
      "All sorting/filtering logic must be implemented server-side",
      "Some client-side plugins may not work",
      "Requires specific server response format"
    ],
    "caveats": [
      "Server must return data in expected JSON format with draw, recordsTotal, recordsFiltered",
      "Search parameter must be processed on server for global search to work",
      "Column ordering must match between client and server"
    ],
    "best_practices": [
      "Use indexed database columns for sort/search performance",
      "Validate and sanitize all search inputs on server",
      "Cache results when possible to reduce database queries",
      "Return only needed columns to minimize payload size"
    ],
    "documentation": "https://datatables.net/reference/option/serverSide"
  }
}
```

**Implementation**:

Extract during indexing:
- Look for "Note:", "Warning:", "Caveat:" sections
- Extract bullet points about when to use features
- Parse comparison tables (client-side vs server-side)
- Identify trade-off discussions

```php
private function extractNotesCaveats(Crawler $crawler): array
{
    $notes = [];
    
    // Find explicit "Note:" or "Warning:" paragraphs
    $crawler->filter('p')->each(function (Crawler $p) use (&$notes) {
        $text = $p->text();
        
        if (preg_match('/^(Note|Warning|Caveat|Limitation):/i', $text, $matches)) {
            $notes[] = [
                'type' => strtolower($matches[1]),
                'content' => trim(preg_replace('/^(Note|Warning|Caveat|Limitation):\s*/i', '', $text)),
                'severity' => $this->determineSeverity($matches[1])
            ];
        }
        
        // Also look for bold warnings
        $bold = $p->filter('strong, b');
        if ($bold->count() > 0 && preg_match('/must|must not|required|deprecated/i', $text)) {
            $notes[] = [
                'type' => 'warning',
                'content' => $text,
                'severity' => 'critical'
            ];
        }
    });
    
    // Find "When to use" sections
    // Find trade-off discussions
    // Find best practice lists
    
    return $notes;
}
```

**Effort**: 1 day
**Impact**: Medium - helps agents understand intent, not just syntax

---

### Phase 8: Semantic Search (Optional) 🧠

**Goal**: Handle vague/conceptual queries beyond FTS5 keywords.

**⚠️ Complexity**: Requires embeddings, vector storage, separate indexing pipeline.

**Not Recommended for Now** because:
- FTS5 is already very good for technical docs
- Adds significant complexity (embedding model, vector DB, re-indexing)
- Most agent queries are specific (method names, options, errors)
- Phases 1-7 provide 90% of value

**If Implementing Later**:
- Use `text-embedding-3-small` or similar
- Store embeddings in separate table or use SQLite vector extension
- Hybrid search: FTS5 for keywords, vectors for concepts
- Example: "How do I make my table work with lots of data?" → serverSide docs

---

## Implementation Priority

### Must-Have (High Impact, Foundation)
1. ✅ **Phase 1**: Structured data extraction - Foundation for everything
2. ✅ **Phase 2**: Multiple search modes - Immediate UX improvement
3. ✅ **Phase 3**: Structured JSON responses - Agents reason better

### Should-Have (High Value)
4. ✅ **Phase 6**: Diagnostic tool - Transforms MCP into domain expert
5. ✅ **Phase 5**: Enhanced examples - Agents need working code

### Nice-to-Have (Medium Value)
6. ✅ **Phase 4**: Cross-reference tools - Helps with complex configs
7. ✅ **Phase 7**: Explain tool - Helps understand "why"

### Skip for Now
8. ❌ **Phase 8**: Semantic search - Too complex, FTS5 is sufficient

## Migration Strategy

### Backward Compatibility

Keep existing tools working during migration:
- `search_datatables` continues to work with FTS5
- Gradually enhance responses with structured data
- Add new tools alongside old ones
- Announce deprecations with 2-week notice

### Rollout Plan

**Week 1**: Phase 1 (Foundation)
- Create database schema migration
- Implement StructuredParser class
- Test parsing on sample pages
- Re-index documentation with structured extraction

**Week 2**: Phases 2-3 (Search Enhancement)
- Add new MCP tools (search_by_topic, search_by_function, get_examples)
- Modify response format to return structured JSON
- Update documentation with examples
- Test in Crush with real agent workflows

**Week 3**: Phases 4-6 (Advanced Features)
- Add cross-reference tools
- Implement diagnostic endpoint
- Create rule set for config validation
- Enhanced example extraction

**Week 4**: Phase 7 + Polish
- Implement explain tool
- Comprehensive testing
- Documentation updates
- Performance optimization

### Testing Strategy

**Unit Tests** (new):
```php
// tests/StructuredParserTest.php
public function testExtractApiParameters(): void
{
    $html = file_get_contents('tests/fixtures/ajax.reload.html');
    $crawler = new Crawler($html);
    $parser = new StructuredParser();
    
    $result = $parser->parseApiPage($crawler);
    
    $this->assertCount(2, $result['parameters']);
    $this->assertEquals('callback', $result['parameters'][0]['name']);
    $this->assertEquals('function', $result['parameters'][0]['type']);
    $this->assertTrue($result['parameters'][0]['optional']);
}
```

**Integration Tests**:
```bash
# Test MCP protocol with new tools
echo '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"search_by_function","arguments":{"name":"ajax.reload"}},"id":3}' | \
  php bin/datatables-mcp serve | jq .

# Verify structured response
php bin/datatables-mcp test-structured "ajax.reload()"
```

**Agent Testing**:
- Use Crush to ask common DataTables questions
- Verify agents produce better code with structured responses
- Test diagnostic tool with known bad configs

## Success Metrics

**Before Enhancement**:
- Search returns text blobs
- Agents must parse prose for parameters
- 1 search mode (FTS5 only)
- No code examples isolation
- No config validation

**After Enhancement**:
- Structured JSON with typed fields
- Direct access to parameters/types/examples
- 5+ search modes (topic, function, example, tag, FTS5)
- First-class code examples
- Config diagnostic with 20+ rules

**Expected Agent Improvements**:
- ✅ Better code generation (structured parameters → correct function calls)
- ✅ Faster iteration (find examples directly, not buried in docs)
- ✅ Fewer mistakes (diagnostic catches bad configs)
- ✅ Better understanding (explain tool provides "why" context)

## Files to Create/Modify

### New Files
- `src/StructuredParser.php` (400-500 lines)
- `src/ConfigDiagnostic.php` (300-400 lines)
- `database/migrations/002_add_structured_fields.sql`
- `tests/StructuredParserTest.php`
- `tests/ConfigDiagnosticTest.php`
- `tests/fixtures/` (sample HTML pages for testing)

### Modified Files
- `src/DocumentationIndexer.php` (add structured parsing calls)
- `src/SearchEngine.php` (add new search methods)
- `src/McpServer.php` (add new tools, modify response format)
- `bin/datatables-mcp` (add test commands)
- `README.md` (document new capabilities)
- `AGENTS.md` (update architecture section)

### Database
- `data/datatables.db` (schema migration + re-indexing)
- Estimated size after: 15-20MB (from 10MB)

## Effort Estimates

| Phase | Days | Lines of Code | Database Impact |
|-------|------|---------------|-----------------|
| Phase 1 | 2-3 | 800 | +8 tables, +1MB |
| Phase 2 | 1 | 300 | +1 table, +0.5MB |
| Phase 3 | 1-2 | 400 | None |
| Phase 4 | 2 | 500 | +2 tables, +1MB |
| Phase 5 | 1 | 200 | +FTS5 index |
| Phase 6 | 2-3 | 600 | None |
| Phase 7 | 1 | 300 | +FTS5 index |
| **Total** | **10-14 days** | **3,100 lines** | **~20MB final** |

## Conclusion

These enhancements transform the DataTables MCP from a "documentation search" into a true "domain expert assistant" that coding agents can leverage for:

✅ **Better code generation** (structured data → correct syntax)
✅ **Faster problem-solving** (find examples by intent)
✅ **Fewer errors** (config diagnostics catch mistakes)
✅ **Deeper understanding** (explain tool provides context)

**Recommendation**: Implement Phases 1-3 immediately (3-5 days) for massive value, then Phases 4-6 (3-5 days) for domain expertise, skip Phase 8 (semantic search).

**ROI**: 10-14 days of work → 10x improvement in agent code quality and iteration speed.
