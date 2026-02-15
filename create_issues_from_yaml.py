#!/usr/bin/env python3
"""
Create all GitHub issues from ALL_ISSUES.yaml
Usage: python3 create_issues_from_yaml.py
"""

import subprocess
import sys
import yaml

def create_issue(repo, issue):
    """Create a single GitHub issue using gh CLI"""
    title = issue['title']
    labels = ','.join(issue.get('labels', []))
    body = issue['body']
    
    cmd = [
        'gh', 'issue', 'create',
        '--repo', repo,
        '--title', title,
        '--body', body
    ]
    
    if labels:
        cmd.extend(['--label', labels])
    
    if issue.get('assignees'):
        for assignee in issue['assignees']:
            cmd.extend(['--assignee', assignee])
    
    if issue.get('milestone'):
        cmd.extend(['--milestone', issue['milestone']])
    
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, check=True)
        return True, result.stdout.strip()
    except subprocess.CalledProcessError as e:
        return False, e.stderr.strip()

def main():
    repo = "magicsunday/imagemeta"
    
    # Read YAML file
    try:
        with open('ALL_ISSUES.yaml', 'r') as f:
            data = yaml.safe_load(f)
    except FileNotFoundError:
        print("Error: ALL_ISSUES.yaml not found!")
        sys.exit(1)
    except yaml.YAMLError as e:
        print(f"Error parsing YAML: {e}")
        sys.exit(1)
    
    issues = data.get('issues', [])
    total = len(issues)
    
    print(f"========================================")
    print(f"Creating {total} GitHub Issues")
    print(f"Repository: {repo}")
    print(f"========================================")
    print()
    
    created = 0
    failed = 0
    
    for i, issue in enumerate(issues, 1):
        issue_id = issue.get('id', f'Issue-{i}')
        print(f"[{i}/{total}] Creating {issue_id}: {issue['title'][:60]}...")
        
        success, message = create_issue(repo, issue)
        
        if success:
            print(f"  ✓ Created: {message}")
            created += 1
        else:
            print(f"  ✗ Failed: {message}")
            failed += 1
        
        print()
    
    print(f"========================================")
    print(f"Summary:")
    print(f"  Total:   {total}")
    print(f"  Created: {created}")
    print(f"  Failed:  {failed}")
    print(f"========================================")
    
    if failed > 0:
        sys.exit(1)

if __name__ == '__main__':
    main()
