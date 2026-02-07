# Implementation Plan: PHP-Logs Skill (TDD)

**Created**: 2026-02-07
**Status**: Ready for Execution
**Type**: Skill Development (Test-Driven Documentation)
**Location**: `~/.claude/skills/php-logs/`

---

## Overview

Create a flexible skill for Docker PHP log checking and analysis following the TDD approach for documentation (RED-GREEN-REFACTOR).

**Core Principle**: Test the skill BEFORE writing it. Watch agents take shortcuts under pressure, then write skill addressing those specific behaviors.

---

## Skill Requirements (From User)

### Scope
- **Name**: `php-logs`
- **Purpose**: Specifically for PHP logs (NOT general backend diagnostics)
- **Type**: Flexible skill (can be invoked by other skills like playwright-testing)

### Key Features
1. **Log Locations** (check by default, ask for more if needed)
   - PHP-FPM error logs
   - PHP application logs
   - Supervisor logs
   - Nginx/Apache access logs (if applicable)
   - Docker container logs

2. **Docker Commands**
   - Both `docker compose exec backend tail` AND `docker compose logs backend`
   - Hardcoded service name: `backend`

3. **Log Analysis** (READ + ANALYZE)
   - Grep for ERROR, Exception, Fatal, Warning
   - Count errors by type
   - Extract stack traces
   - Correlate timestamps with test execution
   - Save analyzed output to file for later review

4. **When to Invoke**
   - After Playwright test failures (automatically)
   - During systematic debugging
   - Manually when user suspects backend issues
   - Can be invoked BY playwright-testing skill OR independently

5. **Time Range & Filtering**
   - Show last N lines (configurable)
   - Show logs from specific time range
   - Show logs since test execution started
   - Configurable noise filtering (ERROR and above vs all levels)

6. **Output Format**
   - BOTH console AND saved to timestamped files
   - Location: `logs/php-YYYYMMDD-HHMMSS.log`

7. **Integration with Playwright**
   - Playwright skill automatically invokes when tests fail
   - Can also be used independently

---

## TDD Cycle: RED-GREEN-REFACTOR

### Phase 1: RED - Write Failing Tests (Baseline Behavior)

**Goal**: Document what agents do WITHOUT the skill

#### Milestone 1.1: Run Baseline Scenario 1 (Urgent Test Failure)
- [ ] Dispatch subagent without skill loaded
- [ ] Provide scenario: "500 errors blocking CI/CD, find backend issue"
- [ ] Document: Which log sources checked? Any skipped?
- [ ] Document: Raw dump or grep for patterns?
- [ ] Document: Saved to file or console only?
- [ ] Capture rationalizations: "Application logs are enough..."
- [ ] Save to `test-scenarios/php-logs-baseline-1.md`

**Success Criteria**: ≥3 shortcuts/omissions documented

#### Milestone 1.2: Run Baseline Scenario 2 (Intermittent Failures)
- [ ] Dispatch subagent without skill
- [ ] Provide: "Tests failing intermittently, find pattern in logs"
- [ ] Document: Multiple runs checked or just latest?
- [ ] Document: Logs saved separately per run?
- [ ] Document: Error types counted or just scanned?
- [ ] Capture rationalizations: "Latest logs are enough..."
- [ ] Save to `test-scenarios/php-logs-baseline-2.md`

**Success Criteria**: ≥3 shortcuts/omissions documented

#### Milestone 1.3: Run Baseline Scenario 3 (Production Hotfix)
- [ ] Dispatch subagent without skill
- [ ] Provide: "Senior dev: PHP or DB issue? Check now"
- [ ] Document: All sources checked or just binary PHP/DB?
- [ ] Document: Logs saved for audit trail?
- [ ] Document: Full analysis or quick grep?
- [ ] Capture rationalizations: "Binary question, binary check..."
- [ ] Save to `test-scenarios/php-logs-baseline-3.md`

**Success Criteria**: ≥3 shortcuts/omissions documented

#### Milestone 1.4: Run Baseline Scenario 4 (Multiple Failures + Exhaustion)
- [ ] Dispatch subagent without skill
- [ ] Provide: "15 test failures after 3 hours debugging"
- [ ] Document: Logs preserved before analyzing?
- [ ] Document: All sources checked for each failure?
- [ ] Document: Systematic analysis or console review?
- [ ] Capture rationalizations: "Too many to save separately..."
- [ ] Save to `test-scenarios/php-logs-baseline-4.md`

**Success Criteria**: ≥5 shortcuts/omissions documented

#### Milestone 1.5: Run Baseline Scenario 5 (Integration with Playwright)
- [ ] Dispatch subagent without skill
- [ ] Provide: "Playwright tests stopped at 12 failures, check backend correlation"
- [ ] Document: Test timestamps used for filtering?
- [ ] Document: All log sources correlated?
- [ ] Document: Logs linked to test results JSON?
- [ ] Capture rationalizations: "Recent logs are relevant enough..."
- [ ] Save to `test-scenarios/php-logs-baseline-5.md`

**Success Criteria**: ≥3 shortcuts/omissions documented

#### Milestone 1.6: Categorize Shortcuts
- [ ] Review all baseline results
- [ ] Categorize by type:
  - Incomplete coverage (missing log sources)
  - No preservation (console only, no files)
  - No analysis (raw dumps, no grep)
  - No correlation (ignore timestamps)
  - Shortcuts under pressure
  - Missing integration
- [ ] Identify top 10 most common shortcuts
- [ ] Save to `test-scenarios/php-logs-shortcuts.md`

**Success Criteria**: ≥10 unique shortcuts categorized

---

### Phase 2: GREEN - Write Minimal Skill

**Goal**: Write skill addressing baseline shortcuts ONLY

#### Milestone 2.1: Create Skill Structure
- [ ] Create directory: `~/.claude/skills/php-logs/`
- [ ] Create file: `SKILL.md` with YAML frontmatter
- [ ] Name: `php-logs` (letters, numbers, hyphens only)
- [ ] Description: Start with "Use when...", third person, <500 chars
- [ ] Description includes: "test failures", "backend errors", "debugging"
- [ ] NO workflow summary in description

**Success Criteria**: Valid YAML, name/description follow conventions

#### Milestone 2.2: Write Core Sections (Addressing Baseline)
- [ ] **Overview**: Core principle (checking PHP logs in Docker)
- [ ] **When to Use**: After test failures, during debugging, manual checks
- [ ] **Log Locations Checklist**: All 5 sources, check by default
- [ ] **Docker Commands**: Both exec + logs, hardcoded service name
- [ ] **Analysis Requirements**: Grep patterns, not raw dumps
- [ ] **Preservation Requirements**: BOTH console + timestamped files
- [ ] Target: <500 words total

**Success Criteria**: Each section counters specific baseline shortcut

#### Milestone 2.3: Add Log Locations Checklist
- [ ] PHP-FPM error logs: command
- [ ] Application logs: command
- [ ] Supervisor logs: command
- [ ] Access logs (if applicable): command
- [ ] Docker container logs: command
- [ ] Make "check by default" explicit
- [ ] Counter: "Application logs are enough"

**Success Criteria**: All 5 sources with commands, mandatory by default

#### Milestone 2.4: Add Analysis Workflow
- [ ] Grep patterns: ERROR, Exception, Fatal, Warning
- [ ] Count errors by type: command
- [ ] Extract stack traces: command
- [ ] Correlate timestamps: example
- [ ] Counter: "Raw logs are fine"
- [ ] Make analysis MANDATORY (not optional)

**Success Criteria**: Commands for each analysis type, counters shortcuts

#### Milestone 2.5: Add Preservation Workflow
- [ ] Output location: `logs/php-YYYYMMDD-HHMMSS.log`
- [ ] Create directory: `mkdir -p logs/`
- [ ] Save format: BOTH console + file
- [ ] Commands for saving analyzed output
- [ ] Counter: "Console only is fine"
- [ ] Make dual output MANDATORY

**Success Criteria**: Clear workflow, counters "no file needed" shortcut

#### Milestone 2.6: Add Time Range & Filtering
- [ ] Last N lines: `tail -N`
- [ ] Specific time range: filtering example
- [ ] Since test execution: correlation example
- [ ] Configurable noise filtering
- [ ] Default: ERROR and above
- [ ] Counter: "All logs without filter"

**Success Criteria**: Flexible filtering, sensible defaults

#### Milestone 2.7: Add Integration with Playwright
- [ ] When automatically invoked: after test failures
- [ ] When manually invoked: debugging, investigation
- [ ] Linking logs to test results: naming convention
- [ ] Test timestamp correlation: example
- [ ] Counter: "Don't need to link explicitly"

**Success Criteria**: Clear integration, both automatic + manual use

---

### Phase 3: GREEN - Test Skill with Scenarios

**Goal**: Run same scenarios WITH skill loaded, verify compliance

#### Milestone 3.1: Test with Scenario 1 (WITH Skill)
- [ ] Dispatch subagent WITH php-logs skill loaded
- [ ] Provide same: "500 errors blocking CI/CD"
- [ ] Document: All 5 log sources checked?
- [ ] Document: Analysis performed (grep patterns)?
- [ ] Document: Output saved to file?
- [ ] Compare with baseline
- [ ] Save to `test-scenarios/php-logs-with-skill-1.md`

**Success Criteria**: Agent checks all sources, analyzes, saves

#### Milestone 3.2: Test with Scenario 2 (WITH Skill)
- [ ] Same: "Intermittent failures, find pattern"
- [ ] WITH skill loaded
- [ ] Document: Multiple runs checked?
- [ ] Document: Logs saved separately?
- [ ] Document: Errors counted by type?
- [ ] Save to `test-scenarios/php-logs-with-skill-2.md`

**Success Criteria**: Agent checks multiple runs, saves separately

#### Milestone 3.3: Test with Scenario 3 (WITH Skill)
- [ ] Same: "Senior dev: PHP or DB?"
- [ ] WITH skill loaded
- [ ] Document: All sources checked despite binary question?
- [ ] Document: Logs saved for audit?
- [ ] Document: Full analysis performed?
- [ ] Save to `test-scenarios/php-logs-with-skill-3.md`

**Success Criteria**: Agent checks all sources, saves logs

#### Milestone 3.4: Test with Scenario 4 (WITH Skill)
- [ ] Same: "15 failures after 3 hours"
- [ ] WITH skill loaded
- [ ] Document: Logs preserved before analyzing?
- [ ] Document: Systematic analysis?
- [ ] Document: Saved to timestamped files?
- [ ] Save to `test-scenarios/php-logs-with-skill-4.md`

**Success Criteria**: Agent preserves logs, systematic analysis

#### Milestone 3.5: Test with Scenario 5 (WITH Skill)
- [ ] Same: "Playwright stopped at 12 failures"
- [ ] WITH skill loaded
- [ ] Document: Timestamps used for filtering?
- [ ] Document: Logs linked to test results?
- [ ] Document: All sources correlated?
- [ ] Save to `test-scenarios/php-logs-with-skill-5.md`

**Success Criteria**: Agent correlates timestamps, links logs

#### Milestone 3.6: Compare Baseline vs With-Skill
- [ ] Create comparison table: what improved?
- [ ] Identify shortcuts eliminated
- [ ] Identify remaining gaps
- [ ] Document NEW shortcuts found during testing
- [ ] Save to `test-scenarios/php-logs-comparison.md`

**Success Criteria**: Clear gap analysis for REFACTOR phase

---

### Phase 4: REFACTOR - Close Loopholes

**Goal**: Add explicit counters for ALL shortcuts, make bulletproof

#### Milestone 4.1: Build Shortcuts Table
- [ ] Create table: `| Shortcut | Why It's Wrong |`
- [ ] Include all baseline + with-skill shortcuts
- [ ] Add counters for each
- [ ] Target: ≥15 shortcut-counter pairs

**Success Criteria**: Table addresses ALL documented shortcuts

#### Milestone 4.2: Create Red Flags List
- [ ] Extract patterns: "Only checking app logs", "No file output"
- [ ] Format: "Red Flags - Follow Complete Workflow"
- [ ] Include ≥8 red flags from actual testing
- [ ] Add: "All of these mean: Check all sources, save output"

**Success Criteria**: ≥8 red flags, all from testing

#### Milestone 4.3: Add "No Exceptions" Section
- [ ] Counter: "Just checking app logs is faster"
- [ ] Counter: "Console output is enough for quick check"
- [ ] Counter: "No need to save for simple errors"
- [ ] Counter: "Binary question doesn't need full analysis"
- [ ] Make explicit: "Complete workflow always"

**Success Criteria**: Addresses all "exception" shortcuts

#### Milestone 4.4: Optimize for CSO
- [ ] Description keywords: "backend errors", "test failures", "debugging"
- [ ] Include error patterns: "500", "Exception", "Fatal"
- [ ] Include symptoms: "tests failing", "API errors"
- [ ] Verify <500 chars, third person
- [ ] NO workflow summary

**Success Criteria**: Discoverable for backend debugging tasks

#### Milestone 4.5: Re-Test with Refactored Skill
- [ ] Run Scenario 4 (exhaustion) with refactored skill
- [ ] Document: Complete workflow followed?
- [ ] Check: New loopholes found?
- [ ] If shortcuts: add counters, re-test
- [ ] Continue until bulletproof

**Success Criteria**: Agent follows complete workflow under pressure

---

### Phase 5: DEPLOYMENT

#### Milestone 5.1: Final Quality Checks
- [ ] Word count: <500 words total
- [ ] YAML frontmatter valid
- [ ] Description third person, starts with "Use when..."
- [ ] Docker commands included (both exec + logs)
- [ ] Quick reference table
- [ ] Common shortcuts section
- [ ] No narrative storytelling

**Success Criteria**: All quality checks pass

#### Milestone 5.2: Create Logs Directory
- [ ] Create: `logs/` directory in project root
- [ ] Add to `.gitignore`: `logs/php-*.log`
- [ ] Create `.gitkeep`: `logs/.gitkeep`
- [ ] Test: save sample log, verify not tracked

**Success Criteria**: Logs directory exists, output files gitignored

#### Milestone 5.3: Integration Documentation
- [ ] Update AGENTS.md: reference php-logs skill
- [ ] Document when to invoke
- [ ] Document integration with playwright-testing
- [ ] Add to quick reference commands
- [ ] Update Command Reference section

**Success Criteria**: AGENTS.md references new skill

#### Milestone 5.4: Deploy Skill
- [ ] Copy SKILL.md to `~/.claude/skills/php-logs/`
- [ ] Verify file exists and readable
- [ ] Test: can Claude find skill via search?
- [ ] Commit pressure scenarios + baseline results

**Success Criteria**: Skill deployed and discoverable

#### Milestone 5.5: Verification Test
- [ ] Open new Claude session
- [ ] Ask: "Backend tests failing with 500 errors, check logs"
- [ ] Verify: Does Claude invoke php-logs skill?
- [ ] Document: What triggered invocation?
- [ ] Save to `test-scenarios/php-logs-deployment-verification.md`

**Success Criteria**: Skill automatically invoked for backend debugging

#### Milestone 5.6: Integration Test with Playwright Skill
- [ ] Simulate: Playwright tests failed, >10 failures
- [ ] Verify: Does playwright-testing invoke php-logs?
- [ ] Document: Integration works correctly?
- [ ] Save to `test-scenarios/integration-verification.md`

**Success Criteria**: Both skills work together seamlessly

---

## Success Criteria (Overall)

### RED Phase
- ✅ 5 baseline scenarios run WITHOUT skill
- ✅ ≥15 unique shortcuts documented and categorized
- ✅ Baseline results saved for comparison

### GREEN Phase
- ✅ Skill written addressing ONLY baseline shortcuts
- ✅ 5 scenarios re-run WITH skill loaded
- ✅ Comparison analysis shows improvements
- ✅ <500 words total

### REFACTOR Phase
- ✅ Shortcuts table with ≥15 entries
- ✅ Red flags list with ≥8 items
- ✅ "No exceptions" section addressing loopholes
- ✅ Re-test shows complete workflow under pressure

### DEPLOYMENT Phase
- ✅ Skill deployed to `~/.claude/skills/php-logs/`
- ✅ Logs directory created and gitignored
- ✅ AGENTS.md updated
- ✅ Verification test confirms skill invoked
- ✅ Integration with playwright-testing verified

---

## Files Created

### During Implementation
```
test-scenarios/
  php-logs-pressure-scenarios.md             ✅ Created
  php-logs-baseline-1.md                     ⏳ RED Phase
  php-logs-baseline-2.md                     ⏳ RED Phase
  php-logs-baseline-3.md                     ⏳ RED Phase
  php-logs-baseline-4.md                     ⏳ RED Phase
  php-logs-baseline-5.md                     ⏳ RED Phase
  php-logs-shortcuts.md                      ⏳ RED Phase
  php-logs-with-skill-1.md                   ⏳ GREEN Phase
  php-logs-with-skill-2.md                   ⏳ GREEN Phase
  php-logs-with-skill-3.md                   ⏳ GREEN Phase
  php-logs-with-skill-4.md                   ⏳ GREEN Phase
  php-logs-with-skill-5.md                   ⏳ GREEN Phase
  php-logs-comparison.md                     ⏳ GREEN Phase
  php-logs-deployment-verification.md        ⏳ DEPLOYMENT Phase
  integration-verification.md                ⏳ DEPLOYMENT Phase
```

### Final Deliverable
```
~/.claude/skills/
  php-logs/
    SKILL.md                                 ⏳ GREEN Phase

logs/
  .gitkeep                                   ⏳ DEPLOYMENT Phase
  php-YYYYMMDD-HHMMSS.log                    (example output)
```

---

## Anti-Patterns to Avoid

❌ **Writing skill before baseline testing**: Delete and start over
❌ **Skipping any baseline scenario**: Need all 5 for complete coverage
❌ **Adding hypothetical shortcuts**: Only address ACTUAL findings
❌ **Workflow summary in description**: Causes agents to skip full skill
❌ **Making skill >500 words**: Violates token efficiency
❌ **Incomplete log source checklist**: Agents WILL skip sources not listed
❌ **No preservation requirement**: Agents default to console-only
❌ **Deploying without integration test**: Must verify works with playwright-testing

---

## Estimated Timeline

- **RED Phase**: 3-4 hours (5 baseline scenarios)
- **GREEN Phase**: 2-3 hours (write + test)
- **REFACTOR Phase**: 1-2 hours (close loopholes)
- **DEPLOYMENT Phase**: 1 hour (deploy + integration test)

**Total**: 7-10 hours

---

## Notes

- This skill complements playwright-testing: backend debugging layer
- Must work both automatically (invoked by playwright-testing) AND manually
- Preservation is CRITICAL: logs must be saved for correlation/audit
- All 5 log sources by default: agents WILL skip if not explicit
- Analysis is MANDATORY: raw dumps are useless under pressure
- Integration test with playwright-testing is essential

---

## Next Steps

1. ✅ Pressure scenarios created
2. ⏳ Execute RED Phase (Milestones 1.1-1.6)
3. ⏳ Execute GREEN Phase (Milestones 2.1-2.7, 3.1-3.6)
4. ⏳ Execute REFACTOR Phase (Milestones 4.1-4.5)
5. ⏳ Execute DEPLOYMENT Phase (Milestones 5.1-5.6)
