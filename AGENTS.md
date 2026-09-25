# Codebase Memory

This repository uses the `codebase-memory` MCP knowledge graph as the primary source for code discovery and impact analysis.

## Mandatory search workflow

- Use codebase-memory for every codebase search. Do not begin structural exploration with `rg`, `grep`, `find`, IDE search, or broad file reads.
- The indexed project name is `Users-User-Desktop-untitled-folder`.
- Start a session with `list_projects` or `index_status` when index availability or freshness is unknown.
- Use `get_architecture` for repository structure, packages, routes, layers, dependencies, hotspots, and entry points.
- Use `search_graph` to find symbols, `trace_path` to find callers/callees, `get_code_snippet` or `get_file_outline` to inspect source, and `query_graph` for multi-hop or aggregate questions.
- Use codebase-memory `search_code` for literal/text searches.
- Before citing or editing a path returned by the graph, use `check_index_coverage` when coverage or freshness is uncertain.
- Fall back to `rg`/`grep` only when `search_code` cannot answer the query or `check_index_coverage` identifies a gap. Keep fallback searches scoped to that gap and re-index afterward.

## Mandatory update workflow

- After every code, configuration, schema, route, test, or documentation change, update codebase-memory before declaring the task complete.
- First run `detect_changes` for `Users-User-Desktop-untitled-folder` to inspect affected files and impact. If the repository has no initial Git commit yet, record that limitation and continue with a full re-index; `detect_changes` requires a valid `HEAD`.
- Then run `index_repository` for `/Users/User/Desktop/untitled folder` with project name `Users-User-Desktop-untitled-folder`, `mode="full"`, and `persistence=true`.
- Verify the refreshed index with `index_status`; use `check_index_coverage` for all changed paths.
- Treat `.codebase-memory/graph.db.zst`, `.codebase-memory/artifact.json`, and `.codebase-memory/.gitattributes` as generated project artifacts. Include their refreshed versions with the related change unless the task explicitly excludes generated artifacts.
- If indexing is unavailable, report that limitation explicitly; do not claim the memory is current.
