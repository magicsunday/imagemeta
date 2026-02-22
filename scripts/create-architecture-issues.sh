#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TICKETS_FILE="${ROOT_DIR}/ARCHITECTURE_VIOLATIONS_TICKETS.md"
DRY_RUN=false

usage() {
    cat <<'USAGE'
Usage: scripts/create-architecture-issues.sh [options]

Creates GitHub issues from ARCHITECTURE_VIOLATIONS_TICKETS.md and applies labels.
Missing labels are created automatically.

Options:
  --file <path>   Path to ticket markdown file (default: ARCHITECTURE_VIOLATIONS_TICKETS.md)
  --dry-run       Parse and print what would be created, without API calls
  --help          Show this help
USAGE
}

while (($# > 0)); do
    case "$1" in
        --file)
            shift
            TICKETS_FILE="${1:-}"
            ;;
        --dry-run)
            DRY_RUN=true
            ;;
        --help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage
            exit 1
            ;;
    esac
    shift
done

if [[ ! -f "$TICKETS_FILE" ]]; then
    echo "Tickets file not found: $TICKETS_FILE" >&2
    exit 1
fi

if [[ "$DRY_RUN" == false ]] && ! command -v gh >/dev/null 2>&1; then
    echo "GitHub CLI (gh) is required." >&2
    exit 1
fi

declare -A LABEL_COLORS=(
    ["architecture"]="5319e7"
    ["needs-implementation"]="0e8a16"
    ["design-tradeoff"]="d4c5f9"
    ["priority:high"]="d73a4a"
    ["priority:medium"]="fbca04"
    ["priority:low"]="0e8a16"
    ["principle:kiss"]="1d76db"
    ["principle:solid"]="1d76db"
    ["principle:dry"]="1d76db"
    ["principle:yagni"]="1d76db"
    ["principle:grasp"]="1d76db"
    ["principle:lod"]="1d76db"
    ["principle:soc"]="1d76db"
    ["principle:coc"]="1d76db"
)

declare -A LABEL_DESCRIPTIONS=(
    ["architecture"]="Architecture and design quality backlog"
    ["needs-implementation"]="Issue is defined and ready for implementation"
    ["design-tradeoff"]="Potential intentional tradeoff requiring review"
    ["priority:high"]="High priority"
    ["priority:medium"]="Medium priority"
    ["priority:low"]="Low priority"
    ["principle:kiss"]="Keep It Simple, Stupid"
    ["principle:solid"]="SOLID principle violation"
    ["principle:dry"]="Don't Repeat Yourself violation"
    ["principle:yagni"]="You Aren't Gonna Need It violation"
    ["principle:grasp"]="GRASP principle violation"
    ["principle:lod"]="Law of Demeter violation"
    ["principle:soc"]="Separation of Concerns violation"
    ["principle:coc"]="Convention over Configuration violation"
)

declare -A EXISTING_LABELS=()
declare -a ISSUE_LABELS=()

load_existing_labels() {
    if [[ "$DRY_RUN" == true ]]; then
        return
    fi

    local label_names
    label_names="$(gh label list --limit 500 --json name --jq '.[].name')"

    while IFS= read -r label; do
        if [[ -n "$label" ]]; then
            EXISTING_LABELS["$label"]=1
        fi
    done <<<"$label_names"
}

ensure_label_exists() {
    local label="$1"

    if [[ -n "${EXISTING_LABELS[$label]:-}" ]]; then
        return
    fi

    if [[ "$DRY_RUN" == true ]]; then
        echo "[dry-run] would create label: $label" >&2
        EXISTING_LABELS["$label"]=1
        return
    fi

    gh label create "$label" \
        --color "${LABEL_COLORS[$label]:-1d76db}" \
        --description "${LABEL_DESCRIPTIONS[$label]:-Architecture ticket label}" >/dev/null

    EXISTING_LABELS["$label"]=1
    echo "Created label: $label" >&2
}

append_labels_from_content() {
    local content="$1"
    local priority=""

    declare -A selected=()
    selected["architecture"]=1
    selected["needs-implementation"]=1

    if grep -qi "Design-Tradeoff" <<<"$content"; then
        selected["design-tradeoff"]=1
    fi

    if grep -qiE '(^|[^A-Za-z])SOLID([^A-Za-z]|$)' <<<"$content"; then selected["principle:solid"]=1; fi
    if grep -qiE '(^|[^A-Za-z])DRY([^A-Za-z]|$)' <<<"$content"; then selected["principle:dry"]=1; fi
    if grep -qiE '(^|[^A-Za-z])KISS([^A-Za-z]|$)' <<<"$content"; then selected["principle:kiss"]=1; fi
    if grep -qiE '(^|[^A-Za-z])YAGNI([^A-Za-z]|$)' <<<"$content"; then selected["principle:yagni"]=1; fi
    if grep -qiE '(^|[^A-Za-z])GRASP([^A-Za-z]|$)' <<<"$content"; then selected["principle:grasp"]=1; fi
    if grep -qiE 'Law of Demeter|\bLoD\b' <<<"$content"; then selected["principle:lod"]=1; fi
    if grep -qiE 'Separation of Concerns|\bSoC\b' <<<"$content"; then selected["principle:soc"]=1; fi
    if grep -qiE 'Convention over Configuration|\bCoC\b' <<<"$content"; then selected["principle:coc"]=1; fi

    priority="$(sed -n 's/^- \*\*Priorität:\*\* \(.*\)$/\1/p' <<<"$content" | head -n 1 | tr '[:upper:]' '[:lower:]')"
    case "$priority" in
        hoch|high)
            selected["priority:high"]=1
            ;;
        mittel|medium)
            selected["priority:medium"]=1
            ;;
        niedrig|low)
            selected["priority:low"]=1
            ;;
    esac

    local key
    local out=()
    for key in "${!selected[@]}"; do
        ensure_label_exists "$key"
        out+=("$key")
    done

    mapfile -t ISSUE_LABELS < <(printf '%s\n' "${out[@]}" | sort -u)
}

create_issue() {
    local title="$1"
    local content="$2"

    local body
    body=$'Automatisch erstellt aus `ARCHITECTURE_VIOLATIONS_TICKETS.md`.\n\n'
    body+="$content"

    append_labels_from_content "$content"
    local labels=("${ISSUE_LABELS[@]}")

    if [[ "$DRY_RUN" == true ]]; then
        echo "[dry-run] would create issue: $title"
        echo "[dry-run] labels: ${labels[*]}"
        echo
        return
    fi

    local args=(issue create --title "$title" --body "$body")
    local label
    for label in "${labels[@]}"; do
        args+=(--label "$label")
    done

    gh "${args[@]}" >/dev/null
    echo "Created issue: $title"
}

process_ticket() {
    local title="$1"
    local content="$2"

    if [[ -z "${title// }" ]]; then
        return
    fi

    create_issue "$title" "$content"
}

load_existing_labels

current_title=""
current_content=""

while IFS= read -r line || [[ -n "$line" ]]; do
    if [[ "$line" =~ ^##[[:space:]]+🎫[[:space:]]+Ticket:[[:space:]]+(.+)$ ]]; then
        process_ticket "$current_title" "$current_content"
        current_title="${BASH_REMATCH[1]}"
        current_content=""
        continue
    fi

    if [[ -n "$current_title" ]]; then
        if [[ -n "$current_content" ]]; then
            current_content+=$'\n'
        fi
        current_content+="$line"
    fi
done <"$TICKETS_FILE"

process_ticket "$current_title" "$current_content"

echo "Done."
