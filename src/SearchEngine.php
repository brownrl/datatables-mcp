<?php

namespace DataTablesMcp;

/**
 * Full-Text Search engine using SQLite FTS5
 * 
 * FTS5 is SQLite's full-text search extension that provides:
 * - Fast text search with ranking
 * - Boolean queries (AND, OR, NOT)
 * - Phrase queries ("exact match")
 * - Prefix matching (word*)
 */
class SearchEngine
{
    private \PDO $db;

    public function __construct(string $dbPath)
    {
        if (!file_exists($dbPath)) {
            throw new \Exception("Database not found at $dbPath. Run 'composer run index' first.");
        }

        $this->db = new \PDO("sqlite:$dbPath");
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Search documentation using FTS5
     * 
     * @param string $query Search terms
     * @param int $limit Maximum results to return
     * @return array Array of matching documents
     */
    public function search(string $query, int $limit = 10): array
    {
        // FTS5 query syntax:
        // - Multiple words = implicit AND
        // - OR for alternatives: "ajax OR server"
        // - Phrase search: "exact phrase"
        // - Prefix: word*
        // - NOT: "ajax NOT server"
        
        // Sanitize query to handle hyphens and special characters
        $sanitizedQuery = $this->sanitizeQuery($query);
        
        $stmt = $this->db->prepare("
            SELECT 
                d.title,
                d.url,
                d.content,
                d.section,
                d.doc_type,
                fts.rank
            FROM documentation_fts fts
            JOIN documentation d ON d.id = fts.rowid
            WHERE documentation_fts MATCH :query
            ORDER BY rank
            LIMIT :limit
        ");

        $stmt->bindValue(':query', $sanitizedQuery, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Sanitize user query for FTS5
     * 
     * FTS5's default unicode61 tokenizer treats hyphens as separators.
     * This means "server-side" is indexed as two tokens: "server" and "side".
     * 
     * To search for hyphenated terms, we need to convert them to phrase queries:
     * "server-side" becomes "server side" (with quotes)
     * 
     * We preserve existing quoted phrases and FTS5 operators (AND, OR, NOT).
     * 
     * @param string $query Raw user query
     * @return string Sanitized FTS5 query
     */
    private function sanitizeQuery(string $query): string
    {
        // If query is already quoted or contains FTS5 operators, return as-is
        // (assume user knows FTS5 syntax)
        if (preg_match('/".*?"|\b(AND|OR|NOT)\b/i', $query)) {
            return $query;
        }

        // Split into tokens, preserving whitespace
        $tokens = preg_split('/(\s+)/', $query, -1, PREG_SPLIT_DELIM_CAPTURE);
        $sanitized = [];

        foreach ($tokens as $token) {
            // Keep whitespace as-is
            if (preg_match('/^\s+$/', $token)) {
                $sanitized[] = $token;
                continue;
            }

            // If token contains hyphen, convert to phrase query
            // "server-side" -> "server side"
            if (strpos($token, '-') !== false) {
                // Remove hyphens and wrap in quotes
                $phraseQuery = str_replace('-', ' ', $token);
                // Escape any existing quotes in the token
                $phraseQuery = str_replace('"', '""', $phraseQuery);
                $sanitized[] = '"' . $phraseQuery . '"';
            } else {
                // Regular token, keep as-is
                $sanitized[] = $token;
            }
        }

        return implode('', $sanitized);
    }

    /**
     * Get statistics about indexed documentation
     */
    public function getStats(): array
    {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_docs,
                COUNT(DISTINCT doc_type) as doc_types,
                COUNT(DISTINCT section) as sections
            FROM documentation
        ");

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Get all document types in the index
     */
    public function getDocTypes(): array
    {
        $stmt = $this->db->query("
            SELECT doc_type, COUNT(*) as count
            FROM documentation
            GROUP BY doc_type
            ORDER BY count DESC
        ");

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
