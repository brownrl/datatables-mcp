-- Migration: Add structured data tables
-- Purpose: Store parsed structured information from documentation pages

-- Parameters table (for API methods and Options)
CREATE TABLE IF NOT EXISTS parameters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    doc_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL,
    optional INTEGER DEFAULT 0,
    default_value TEXT,
    description TEXT,
    FOREIGN KEY (doc_id) REFERENCES documentation(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_parameters_doc_id ON parameters(doc_id);
CREATE INDEX IF NOT EXISTS idx_parameters_name ON parameters(name);

-- Code examples table
CREATE TABLE IF NOT EXISTS code_examples (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    doc_id INTEGER NOT NULL,
    title TEXT,
    code TEXT NOT NULL,
    language TEXT DEFAULT 'javascript',
    FOREIGN KEY (doc_id) REFERENCES documentation(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_examples_doc_id ON code_examples(doc_id);

-- Related items table (cross-references)
CREATE TABLE IF NOT EXISTS related_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    doc_id INTEGER NOT NULL,
    related_doc_title TEXT NOT NULL,
    category TEXT NOT NULL, -- 'API', 'Options', 'Events'
    FOREIGN KEY (doc_id) REFERENCES documentation(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_related_doc_id ON related_items(doc_id);
CREATE INDEX IF NOT EXISTS idx_related_category ON related_items(category);

-- Return types table (for API methods)
CREATE TABLE IF NOT EXISTS return_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    doc_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    description TEXT,
    FOREIGN KEY (doc_id) REFERENCES documentation(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_return_types_doc_id ON return_types(doc_id);

-- Notes and caveats table
CREATE TABLE IF NOT EXISTS notes_caveats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    doc_id INTEGER NOT NULL,
    note_text TEXT NOT NULL,
    FOREIGN KEY (doc_id) REFERENCES documentation(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_notes_doc_id ON notes_caveats(doc_id);

-- Value types table (for Options that accept multiple types)
CREATE TABLE IF NOT EXISTS value_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    doc_id INTEGER NOT NULL,
    type TEXT NOT NULL, -- 'string', 'object', 'function', 'integer', etc.
    description TEXT,
    FOREIGN KEY (doc_id) REFERENCES documentation(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_value_types_doc_id ON value_types(doc_id);
CREATE INDEX IF NOT EXISTS idx_value_types_type ON value_types(type);
