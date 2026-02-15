#!/usr/bin/env bash

##
# GitHub Issue Creator for Architecture Violation Tickets
#
# This script parses ARCHITECTURE_VIOLATIONS_TICKETS.md and creates
# GitHub issues with appropriate labels for each architecture violation.
#
# Usage:
#   ./scripts/create-architecture-issues.sh [--dry-run]
#
# Options:
#   --dry-run    Show what would be created without actually creating issues
#
# Requirements:
#   - GitHub CLI (gh) must be installed and authenticated
#   - Repository must be a git repository
##

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
TICKET_FILE="ARCHITECTURE_VIOLATIONS_TICKETS.md"
DRY_RUN=false
REPO_OWNER="magicsunday"
REPO_NAME="imagemeta"

# Parse arguments
for arg in "$@"; do
    case $arg in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        *)
            ;;
    esac
done

# Check prerequisites
check_prerequisites() {
    echo -e "${BLUE}Checking prerequisites...${NC}"
    
    # Check if gh is installed
    if ! command -v gh &> /dev/null; then
        echo -e "${RED}Error: GitHub CLI (gh) is not installed${NC}"
        echo "Install it from: https://cli.github.com/"
        exit 1
    fi
    
    # Check if authenticated (skip in dry-run mode)
    if [[ "$DRY_RUN" == false ]]; then
        if ! gh auth status &> /dev/null; then
            echo -e "${RED}Error: Not authenticated with GitHub CLI${NC}"
            echo "Run: gh auth login"
            exit 1
        fi
    fi
    
    # Check if ticket file exists
    if [[ ! -f "$TICKET_FILE" ]]; then
        echo -e "${RED}Error: $TICKET_FILE not found${NC}"
        exit 1
    fi
    
    echo -e "${GREEN}✓ Prerequisites OK${NC}"
}

# Create or update a label
create_label() {
    local name="$1"
    local color="$2"
    local description="$3"
    
    if [[ "$DRY_RUN" == true ]]; then
        echo -e "${YELLOW}[DRY-RUN] Would create/update label: $name${NC}"
        return
    fi
    
    # Check if label exists
    if gh label list --repo "$REPO_OWNER/$REPO_NAME" | grep -q "^$name"; then
        echo -e "${BLUE}Label '$name' already exists${NC}"
    else
        echo -e "${GREEN}Creating label: $name${NC}"
        gh label create "$name" \
            --repo "$REPO_OWNER/$REPO_NAME" \
            --color "$color" \
            --description "$description" || true
    fi
}

# Setup all required labels
setup_labels() {
    echo -e "${BLUE}Setting up labels...${NC}"
    
    # Priority labels
    create_label "priority-high" "d73a4a" "High priority - critical architecture issues"
    create_label "priority-medium" "fbca04" "Medium priority - important improvements"
    create_label "priority-low" "0e8a16" "Low priority - nice to have improvements"
    
    # Principle labels - SOLID
    create_label "solid-srp" "c5def5" "Single Responsibility Principle violation"
    create_label "solid-ocp" "c5def5" "Open/Closed Principle violation"
    create_label "solid-lsp" "c5def5" "Liskov Substitution Principle violation"
    create_label "solid-isp" "c5def5" "Interface Segregation Principle violation"
    create_label "solid-dip" "c5def5" "Dependency Inversion Principle violation"
    
    # Other principle labels
    create_label "dry" "bfdadc" "Don't Repeat Yourself violation"
    create_label "kiss" "bfdadc" "Keep It Simple Stupid violation"
    create_label "yagni" "bfdadc" "You Aren't Gonna Need It violation"
    create_label "grasp" "bfdadc" "GRASP principles violation"
    create_label "lod" "bfdadc" "Law of Demeter violation"
    create_label "soc" "bfdadc" "Separation of Concerns violation"
    create_label "coc" "bfdadc" "Convention over Configuration violation"
    
    # Type labels
    create_label "architecture" "5319e7" "Architecture and design issues"
    create_label "refactoring" "ededed" "Code refactoring needed"
    create_label "technical-debt" "d93f0b" "Technical debt to be addressed"
    
    echo -e "${GREEN}✓ Labels setup complete${NC}"
}

# Extract ticket details from markdown
parse_ticket() {
    local ticket_num="$1"
    local start_line="$2"
    
    # Extract title (remove emoji and ticket number)
    local title=$(sed -n "${start_line}p" "$TICKET_FILE" | sed 's/^### 🎫 Ticket #[0-9]*: //')
    
    # Extract priority
    local priority_line=$((start_line + 2))
    local priority=$(sed -n "${priority_line}p" "$TICKET_FILE" | sed 's/\*\*Priority:\*\* //')
    
    # Extract principles violated (next non-empty line after Priority)
    local principles_start=$((priority_line + 2))
    local principles_line=$((principles_start + 1))
    
    # Build labels array
    local labels=("architecture" "refactoring" "technical-debt")
    
    # Add priority label
    case "$priority" in
        High)
            labels+=("priority-high")
            ;;
        Medium)
            labels+=("priority-medium")
            ;;
        Low)
            labels+=("priority-low")
            ;;
    esac
    
    # Extract principles section to analyze
    local principles_section=$(sed -n "${principles_start},${principles_line}p" "$TICKET_FILE")
    
    # Add principle labels based on principles section
    if grep -qi "Single Responsibility\|SRP" <<< "$principles_section" || grep -qi "SRP" <<< "$title"; then
        labels+=("solid-srp")
    fi
    if grep -qi "Interface Segregation\|ISP" <<< "$principles_section" || grep -qi "Fat Interface" <<< "$title"; then
        labels+=("solid-isp")
    fi
    if grep -qi "Dependency Inversion\|DIP" <<< "$principles_section" || grep -qi "Tight Coupling" <<< "$title"; then
        labels+=("solid-dip")
    fi
    if grep -qi "Open.Closed\|OCP" <<< "$principles_section"; then
        labels+=("solid-ocp")
    fi
    if grep -qi "DRY" <<< "$principles_section" || grep -qi "Repetitive\|Duplication" <<< "$title"; then
        labels+=("dry")
    fi
    if grep -qi "KISS" <<< "$principles_section"; then
        labels+=("kiss")
    fi
    if grep -qi "YAGNI" <<< "$principles_section" || grep -qi "Over-engineered" <<< "$title"; then
        labels+=("yagni")
    fi
    if grep -qi "GRASP" <<< "$principles_section"; then
        labels+=("grasp")
    fi
    if grep -qi "LoD\|Law of Demeter" <<< "$principles_section"; then
        labels+=("lod")
    fi
    if grep -qi "SoC\|Separation of Concerns" <<< "$principles_section" || grep -qi "Configuration-as-Code" <<< "$title"; then
        labels+=("soc")
    fi
    
    # Extract body (from Location to the next ticket or end of medium/low sections)
    local next_ticket_line=$(awk "NR > $start_line && /^### 🎫 Ticket #/ {print NR; exit}" "$TICKET_FILE")
    if [[ -z "$next_ticket_line" ]]; then
        # Check for section boundaries
        next_ticket_line=$(awk "NR > $start_line && /^## (Low Priority|Summary)/ {print NR; exit}" "$TICKET_FILE")
    fi
    
    local body_end=$((next_ticket_line - 1))
    if [[ -z "$next_ticket_line" ]]; then
        body_end=$(wc -l < "$TICKET_FILE")
    fi
    
    local body_start=$((start_line + 1))
    local body=$(sed -n "${body_start},${body_end}p" "$TICKET_FILE")
    
    # Create issue body with metadata
    local issue_body="**Ticket #${ticket_num}** from Architecture Violations Analysis

---

$body

---

**Source:** ARCHITECTURE_VIOLATIONS_TICKETS.md
**Analysis Date:** 2026-02-15
"
    
    # Join labels with comma
    local labels_str=$(IFS=,; echo "${labels[*]}")
    
    # Use a different delimiter
    echo -e "$title\n<<<DELIM>>>\n$priority\n<<<DELIM>>>\n$labels_str\n<<<DELIM>>>\n$issue_body"
}

# Create a GitHub issue
create_issue() {
    local ticket_num="$1"
    local title="$2"
    local labels="$3"
    local body="$4"
    
    if [[ "$DRY_RUN" == true ]]; then
        echo -e "${YELLOW}[DRY-RUN] Would create issue #$ticket_num:${NC}"
        echo -e "${BLUE}  Title: $title${NC}"
        echo -e "${BLUE}  Labels: $labels${NC}"
        echo ""
        return
    fi
    
    echo -e "${GREEN}Creating issue #$ticket_num: $title${NC}"
    
    # Create the issue
    gh issue create \
        --repo "$REPO_OWNER/$REPO_NAME" \
        --title "$title" \
        --body "$body" \
        --label "$labels" || {
            echo -e "${RED}Failed to create issue #$ticket_num${NC}"
            return 1
        }
    
    echo -e "${GREEN}✓ Issue #$ticket_num created${NC}"
}

# Main function to process all tickets
process_tickets() {
    echo -e "${BLUE}Processing tickets from $TICKET_FILE...${NC}"
    
    # Find all ticket headers
    local ticket_lines=$(grep -n "^### 🎫 Ticket #" "$TICKET_FILE" | cut -d: -f1)
    
    local ticket_num=0
    for line in $ticket_lines; do
        ticket_num=$((ticket_num + 1))
        
        echo -e "${BLUE}Processing Ticket #$ticket_num (line $line)...${NC}"
        
        # Parse ticket
        local ticket_data=$(parse_ticket "$ticket_num" "$line")
        
        # Split data using custom delimiter
        local title=$(echo "$ticket_data" | awk 'BEGIN{RS="<<<DELIM>>>"} NR==1')
        local priority=$(echo "$ticket_data" | awk 'BEGIN{RS="<<<DELIM>>>"} NR==2')
        local labels=$(echo "$ticket_data" | awk 'BEGIN{RS="<<<DELIM>>>"} NR==3')
        local body=$(echo "$ticket_data" | awk 'BEGIN{RS="<<<DELIM>>>"} NR==4')
        
        # Trim whitespace
        title=$(echo "$title" | xargs)
        priority=$(echo "$priority" | xargs)
        labels=$(echo "$labels" | xargs)
        
        # Create issue
        create_issue "$ticket_num" "$title" "$labels" "$body"
        
        # Rate limiting - wait a bit between issues
        if [[ "$DRY_RUN" == false ]]; then
            sleep 2
        fi
    done
    
    echo -e "${GREEN}✓ Processed $ticket_num tickets${NC}"
}

# Main execution
main() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}Architecture Issues Creator${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""
    
    if [[ "$DRY_RUN" == true ]]; then
        echo -e "${YELLOW}DRY RUN MODE - No changes will be made${NC}"
        echo ""
    fi
    
    check_prerequisites
    echo ""
    
    setup_labels
    echo ""
    
    process_tickets
    echo ""
    
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}✓ Complete!${NC}"
    echo -e "${GREEN}========================================${NC}"
}

# Run main function
main
