#!/usr/bin/env bash
# CodeGraph query helper — wraps codeindex graph-export NDJSON with common queries.
# Usage:
#   ./bin/codegraph-query.sh search <pattern>       — find symbols by name
#   ./bin/codegraph-query.sh callers <symbol>       — who calls this symbol?
#   ./bin/codegraph-query.sh callees <symbol>       — what does this symbol call?
#   ./bin/codegraph-query.sh classes                — list all classes
#   ./bin/codegraph-query.sh functions <file>       — list functions in a file
#   ./bin/codegraph-query.sh update                 — rebuild the graph
#
# Examples:
#   ./bin/codegraph-query.sh search Prime_DB
#   ./bin/codegraph-query.sh callers Prime_DB::create_tables
#   ./bin/codegraph-query.sh callees Prime_DB::create_tables
#   ./bin/codegraph-query.sh classes
#   ./bin/codegraph-query.sh functions inc/ajax/tasks.php

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
GRAPH_FILE="$PROJECT_ROOT/.codeindex-graph.ndjson"

if [ ! -f "$GRAPH_FILE" ]; then
    echo "Graph file not found. Run: ./bin/codegraph-query.sh update"
    exit 1
fi

cmd="${1:-help}"
shift || true

case "$cmd" in
    search)
        pattern="${1:-}"
        [ -z "$pattern" ] && echo "Usage: search <pattern>" && exit 1
        python3 -c "
import sys, json
pattern = '$pattern'
for line in open('$GRAPH_FILE'):
    line = line.strip()
    if not line: continue
    obj = json.loads(line)
    if obj.get('type') == 'entity' and pattern.lower() in obj.get('id', '').lower():
        print(f\"{obj['id']} ({obj['entity_type']}) @ {obj['source_id']}\")
        if obj.get('description'):
            print(f\"  desc: {obj['description']}\")
"
        ;;
    callers)
        symbol="${1:-}"
        [ -z "$symbol" ] && echo "Usage: callers <symbol>" && exit 1
        python3 -c "
import sys, json
symbol = '$symbol'
for line in open('$GRAPH_FILE'):
    line = line.strip()
    if not line: continue
    obj = json.loads(line)
    if obj.get('type') == 'edge' and symbol.lower() in str(obj.get('dst', '') or '').lower():
        print(f\"{obj.get('src','?')} → {obj.get('dst_raw', obj.get('dst','?'))} ({obj['kind']}, {obj.get('resolution_qualifier','?')})\")
"
        ;;
    callees)
        symbol="${1:-}"
        [ -z "$symbol" ] && echo "Usage: callees <symbol>" && exit 1
        python3 -c "
import sys, json
symbol = '$symbol'
for line in open('$GRAPH_FILE'):
    line = line.strip()
    if not line: continue
    obj = json.loads(line)
    if obj.get('type') == 'edge' and symbol.lower() in str(obj.get('src', '')).lower():
        print(f\"{obj['src']} → {obj.get('dst_raw', obj.get('dst','?'))} ({obj['kind']}, {obj.get('resolution_qualifier','?')})\")
"
        ;;
    classes)
        python3 -c "
import sys, json
for line in open('$GRAPH_FILE'):
    line = line.strip()
    if not line: continue
    obj = json.loads(line)
    if obj.get('type') == 'entity' and obj.get('entity_type') == 'class':
        print(f\"{obj['id']} @ {obj['source_id']}\")
"
        ;;
    functions)
        filepath="${1:-}"
        [ -z "$filepath" ] && echo "Usage: functions <file>" && exit 1
        python3 -c "
import sys, json
filepath = '$filepath'
for line in open('$GRAPH_FILE'):
    line = line.strip()
    if not line: continue
    obj = json.loads(line)
    if obj.get('type') == 'entity' and obj.get('entity_type') == 'function' and filepath in obj.get('source_id', ''):
        print(f\"{obj['id']} @ {obj['source_id']}\")
        if obj.get('description'):
            print(f\"  desc: {obj['description']}\")
"
        ;;
    update)
        echo "Rebuilding graph..."
        cd "$PROJECT_ROOT"
        codeindex graph-export -o .codeindex-graph.ndjson
        codeindex symbols -o PROJECT_SYMBOLS.md
        echo "Done."
        ;;
    stats)
        python3 -c "
import json
entities = 0
edges = 0
classes = 0
functions = 0
methods = 0
resolved = 0
unresolved = 0
for line in open('$GRAPH_FILE'):
    line = line.strip()
    if not line: continue
    obj = json.loads(line)
    if obj.get('type') == 'entity':
        entities += 1
        et = obj.get('entity_type', '')
        if et == 'class': classes += 1
        elif et == 'function': functions += 1
        elif et == 'method': methods += 1
    elif obj.get('type') == 'edge':
        edges += 1
        if obj.get('resolution_qualifier') == 'resolved': resolved += 1
        elif obj.get('resolution_qualifier') == 'unresolved': unresolved += 1
print(f'Entities: {entities} ({classes} classes, {methods} methods, {functions} functions)')
print(f'Edges: {edges} ({resolved} resolved, {unresolved} unresolved)')
"
        ;;
    help|*)
        cat << 'USAGE'
CodeGraph query helper for pryme-core

Commands:
  search <pattern>     Find symbols by name (case-insensitive)
  callers <symbol>      Who calls this symbol?
  callees <symbol>      What does this symbol call?
  classes               List all classes
  functions <file>      List functions in a file path
  stats                 Show graph statistics
  update                Rebuild the graph + symbols index
USAGE
        ;;
esac
