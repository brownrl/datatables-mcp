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

        $stmt->bindValue(':query', $query, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
