#!/bin/bash
# Bulk-create GitHub Issues from the markdown files in this directory.
# Requires: gh CLI (https://cli.github.com/) authenticated with repo access.
#
# Usage: bash .github/issues/create-issues.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

for file in "$SCRIPT_DIR"/[0-9]*.md; do
    # Extract title from front matter
    title=$(grep '^title:' "$file" | head -1 | sed 's/^title: *"//;s/" *$//')
    # Extract labels from front matter
    labels=$(grep '^labels:' "$file" | head -1 | sed 's/^labels: *//')
    # Extract body (everything after the second ---)
    body=$(awk '/^---$/{c++; next} c>=2' "$file")

    if [ -z "$title" ]; then
        echo "Skipping $file — no title found"
        continue
    fi

    echo "Creating issue: $title"
    gh issue create \
        --title "$title" \
        --body "$body" \
        --label "$labels" \
        || echo "  WARNING: Failed to create issue from $file"
done

echo "Done. All issues created."
