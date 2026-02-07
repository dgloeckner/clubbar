# Implementation Plan: Playwright-Testing Skill (TDD)

**Created**: 2026-02-07
**Status**: Ready for Execution
**Type**: Skill Development (Test-Driven Documentation)
**Location**: `~/.claude/skills/playwright-testing/`

---

## Overview

Create a flexible skill for Playwright E2E test implementation, execution, and analysis following the TDD approach for documentation (RED-GREEN-REFACTOR).

**Core Principle**: Test the skill BEFORE writing it. Watch agents violate rules under pressure, then write skill addressing those specific rationalizations.

---

## Skill Requirements (From User)

### Scope
- **Name**: `playwright-testing`
- **Focus**: E2E integration tests and API tests with Playwright
- **Type**: Flexible skill (can be used by TDD skills)

### Key Features
1. **JSON Results Preservation**
   - Location: `e2etests/results/` (gitignored)
   - Timestamped filenames for multiple checks
   - Never lose test results

2. **Pattern Enforcement**
   - REQUIRE reading E2E patterns before implementation
   - Checklist for patterns 001-008
   - Flag violations during code review

3. **Failure Threshold**
   - Hardcoded at >10 failures = STOP immediately
   - Provide real-time monitoring command

4. **Context7 Integration**
   - Mandatory for unfamiliar Playwright APIs
   - Optional but recommended otherwise

5. **Backend Health Verification**
   - Verify backend running BEFORE tests
   - Delegate to php-logs skill for debugging

6. **When to Invoke**
   - For EVERY new Playwright test

---

## TDD Cycle: RED-GREEN-REFACTOR

### Phase 1: RED - Write Failing Tests (Baseline Behavior)

**Goal**: Document what agents do WITHOUT the skill

#### Milestone 1.1: Run Baseline Scenario 1 (Time + Sunk Cost)
- [ ] Dispatch subagent without skill loaded
- [ ] Provide scenario: "Urgent settlements test needed, blocking deployment"
- [ ] Document verbatim: Does agent read patterns? Save JSON? Check backend?
- [ ] Capture rationalizations: "Patterns are just best practices..."
- [ ] Save baseline results to `test-scenarios/baseline-1-results.md`

**Success Criteria**: ≥3 distinct rationalizations documented

#### Milestone 1.2: Run Baseline Scenario 2 (Complexity + Exhaustion)
- [ ] Dispatch subagent without skill
- [ ] Provide scenario: "15 tests failing, 2 hours debugging, exhausted"
- [ ] Document: Does agent preserve JSON before debugging? Parse systematically?
- [ ] Capture rationalizations: "Error messages are clear, no need for JSON..."
- [ ] Save to `test-scenarios/baseline-2-results.md`

**Success Criteria**: ≥3 distinct rationalizations documented

#### Milestone 1.3: Run Baseline Scenario 3 (Authority + Overconfidence)
- [ ] Dispatch subagent without skill
- [ ] Provide: "Senior dev: write quick E2E tests, don't overthink"
- [ ] Document: Does agent skip patterns? Use try-catch instead of expect()?
- [ ] Capture rationalizations: "I know Playwright, don't need project patterns..."
- [ ] Save to `test-scenarios/baseline-3-results.md`

**Success Criteria**: ≥3 distinct rationalizations documented

#### Milestone 1.4: Run Baseline Scenario 4 (Maximum Stress)
- [ ] Dispatch subagent without skill
- [ ] Provide: "Production hotfix, PM asking ETA every 15 mins"
- [ ] Document: Does agent skip verification? Create shortcuts?
- [ ] Capture rationalizations: "No time to read patterns, this is production..."
- [ ] Save to `test-scenarios/baseline-4-results.md`

**Success Criteria**: ≥5 distinct rationalizations documented

#### Milestone 1.5: Categorize Rationalizations
- [ ] Review all baseline results
- [ ] Categorize by type:
  - Pattern skipping
  - Verification shortcuts
  - JSON preservation
  - Backend checks
  - Test isolation
  - E2E integration
- [ ] Identify top 10 most common rationalizations
- [ ] Save to `test-scenarios/rationalization-categories.md`

**Success Criteria**: ≥10 unique rationalizations categorized

---

### Phase 2: GREEN - Write Minimal Skill

**Goal**: Write skill addressing baseline failures ONLY (no hypothetical cases)

#### Milestone 2.1: Create Skill Structure
- [ ] Create directory: `~/.claude/skills/playwright-testing/`
- [ ] Create file: `SKILL.md` with YAML frontmatter
- [ ] Name: `playwright-testing` (letters, numbers, hyphens only)
- [ ] Description: Start with "Use when...", third person, <500 chars
- [ ] Description includes: triggers, symptoms, NO workflow summary
- [ ] Verify YAML frontmatter max 1024 chars total

**Success Criteria**: Valid YAML, name/description follow conventions

#### Milestone 2.2: Write Core Sections (Addressing Baseline)
- [ ] **Overview**: Core principle (1-2 sentences)
- [ ] **When to Use**: Address "pattern skipping" rationalizations
- [ ] **JSON Preservation**: Counter "no need to save" rationalizations
- [ ] **Backend Verification**: Counter "obviously running" rationalizations
- [ ] **Failure Threshold**: Address "let tests finish" rationalizations
- [ ] **E2E Integration**: Counter "form closes = it worked" rationalizations
- [ ] Target: <500 words total

**Success Criteria**: Each section counters specific baseline rationalization

#### Milestone 2.3: Add Pattern Checklist
- [ ] Create checklist for E2E Patterns 001-008
- [ ] Make reading patterns MANDATORY (not optional)
- [ ] Link to `e2etests/patterns/README.md`
- [ ] Add "No exceptions" section

**Success Criteria**: Checklist addresses "pattern skipping" rationalizations

#### Milestone 2.4: Add JSON Preservation Workflow
- [ ] Location: `e2etests/results/`
- [ ] Naming: `test-results-YYYYMMDD-HHMMSS.json`
- [ ] Commands for saving + parsing
- [ ] Address "I'll save later" rationalization
- [ ] Make preservation MANDATORY before analysis

**Success Criteria**: Counters all "no JSON needed" rationalizations

#### Milestone 2.5: Add Backend Health Check
- [ ] Command: `curl -s http://localhost:8080/api/health | jq .`
- [ ] Delegate detailed checks to php-logs skill
- [ ] Make health check BEFORE tests (not during)
- [ ] Address "obviously running" rationalization

**Success Criteria**: Clear workflow, delegates to php-logs

#### Milestone 2.6: Add Failure Monitoring
- [ ] Real-time command: monitor failure count during execution
- [ ] Hardcoded threshold: >10 = STOP immediately
- [ ] Address "let all tests finish" rationalization
- [ ] Include "What to do when threshold hit" section

**Success Criteria**: Actionable commands, clear threshold

#### Milestone 2.7: Add Context7 Integration
- [ ] When mandatory: unfamiliar Playwright APIs
- [ ] When optional: general implementation
- [ ] Example queries for common scenarios
- [ ] Max 3 calls per question reminder

**Success Criteria**: Clear guidance on when to query

---

### Phase 3: GREEN - Test Skill with Scenarios

**Goal**: Run same scenarios WITH skill loaded, verify compliance

#### Milestone 3.1: Test with Scenario 1 (WITH Skill)
- [ ] Dispatch subagent WITH playwright-testing skill loaded
- [ ] Provide same scenario: "Urgent settlements test"
- [ ] Document: Does agent now read patterns? Save JSON? Check backend?
- [ ] Compare with baseline: What changed?
- [ ] Save to `test-scenarios/with-skill-1-results.md`

**Success Criteria**: Agent follows at least 4/6 requirements

#### Milestone 3.2: Test with Scenario 2 (WITH Skill)
- [ ] Same scenario: "15 tests failing, exhausted"
- [ ] WITH skill loaded
- [ ] Document compliance vs violations
- [ ] Identify NEW rationalizations (not in baseline)
- [ ] Save to `test-scenarios/with-skill-2-results.md`

**Success Criteria**: Agent preserves JSON, parses systematically

#### Milestone 3.3: Test with Scenario 3 (WITH Skill)
- [ ] Same scenario: "Senior dev: quick tests"
- [ ] WITH skill loaded
- [ ] Document: Does skill override "quick" instruction?
- [ ] Check if agent still uses expect() vs try-catch
- [ ] Save to `test-scenarios/with-skill-3-results.md`

**Success Criteria**: Agent reads patterns despite "quick" instruction

#### Milestone 3.4: Test with Scenario 4 (WITH Skill)
- [ ] Same scenario: "Production hotfix, extreme pressure"
- [ ] WITH skill loaded
- [ ] Document: Does skill hold under maximum stress?
- [ ] Identify any remaining loopholes
- [ ] Save to `test-scenarios/with-skill-4-results.md`

**Success Criteria**: Agent follows skill under maximum pressure

#### Milestone 3.5: Compare Baseline vs With-Skill
- [ ] Create comparison table: what changed?
- [ ] Identify improvements: which rationalizations disappeared?
- [ ] Identify remaining gaps: which rationalizations persist?
- [ ] Document NEW rationalizations found during testing
- [ ] Save to `test-scenarios/comparison-analysis.md`

**Success Criteria**: Clear gap analysis for REFACTOR phase

---

### Phase 4: REFACTOR - Close Loopholes

**Goal**: Add explicit counters for ALL rationalizations, make bulletproof

#### Milestone 4.1: Build Rationalization Table
- [ ] Create table with all rationalizations from testing
- [ ] Format: `| Excuse | Reality |`
- [ ] Include both baseline AND with-skill rationalizations
- [ ] Add counters for each excuse
- [ ] Target: ≥15 rationalization-counter pairs

**Success Criteria**: Table addresses ALL documented rationalizations

#### Milestone 4.2: Create Red Flags List
- [ ] Extract common patterns from rationalizations
- [ ] Format as checklist: "Red Flags - STOP and Follow Skill"
- [ ] Include: "Pattern skipping", "I'll save later", "Obviously running"
- [ ] Add explicit: "All of these mean: Follow skill requirements"

**Success Criteria**: ≥8 red flags, all from actual testing

#### Milestone 4.3: Add "No Exceptions" Section
- [ ] Counter: "I'll read patterns after quick implementation"
- [ ] Counter: "I'll save JSON if tests fail"
- [ ] Counter: "Backend check is overkill"
- [ ] Counter: "This test is simple, rules don't apply"
- [ ] Make explicit: "No exceptions means no exceptions"

**Success Criteria**: Addresses all "exception" rationalizations

#### Milestone 4.4: Optimize for CSO (Claude Search Optimization)
- [ ] Verify description has keywords: "flaky", "timeout", "E2E", "integration"
- [ ] Add error messages: "Target page closed", "Request failed"
- [ ] Include symptoms throughout: "tests pass with 1 worker fail with 4"
- [ ] Verify description is <500 chars, third person
- [ ] NO workflow summary in description

**Success Criteria**: Skill discoverable by future Claude instances

#### Milestone 4.5: Re-Test with Refactored Skill
- [ ] Run Scenario 4 (maximum stress) with refactored skill
- [ ] Document: Does agent comply under pressure?
- [ ] Check: Are new loopholes found?
- [ ] If violations: add counters, re-test
- [ ] Continue until bulletproof

**Success Criteria**: Agent follows skill under maximum stress with NO violations

---

### Phase 5: DEPLOYMENT

#### Milestone 5.1: Final Quality Checks
- [ ] Word count: <500 words total
- [ ] YAML frontmatter valid (max 1024 chars)
- [ ] Description third person, starts with "Use when..."
- [ ] One excellent example (not multi-language)
- [ ] Quick reference table included
- [ ] Common mistakes section included
- [ ] Flowchart only if decision non-obvious
- [ ] No narrative storytelling

**Success Criteria**: All quality checks pass

#### Milestone 5.2: Create .gitignore Entry
- [ ] Add `e2etests/results/` to `.gitignore`
- [ ] Verify directory exists: `mkdir -p e2etests/results`
- [ ] Test: save sample JSON to results/, verify not tracked

**Success Criteria**: JSON results directory gitignored

#### Milestone 5.3: Integration Documentation
- [ ] Update AGENTS.md: reference playwright-testing skill
- [ ] Document when to invoke (EVERY new test)
- [ ] Document integration with php-logs skill
- [ ] Add skill to quick reference commands

**Success Criteria**: AGENTS.md references new skill

#### Milestone 5.4: Deploy Skill
- [ ] Copy final SKILL.md to `~/.claude/skills/playwright-testing/`
- [ ] Verify file exists and is readable
- [ ] Test: can Claude find skill via search?
- [ ] Commit pressure scenarios + baseline results (for reference)

**Success Criteria**: Skill deployed and discoverable

#### Milestone 5.5: Verification Test
- [ ] Open new Claude session
- [ ] Ask: "Help me create Playwright tests for settlements API"
- [ ] Verify: Does Claude invoke playwright-testing skill?
- [ ] Document: What triggered skill invocation?
- [ ] Save to `test-scenarios/deployment-verification.md`

**Success Criteria**: Skill automatically invoked for relevant task

---

## Success Criteria (Overall)

### RED Phase
- ✅ 4 baseline scenarios run WITHOUT skill
- ✅ ≥15 unique rationalizations documented and categorized
- ✅ Baseline results saved for comparison

### GREEN Phase
- ✅ Skill written addressing ONLY baseline failures
- ✅ 4 scenarios re-run WITH skill loaded
- ✅ Comparison analysis shows clear improvements
- ✅ <500 words total

### REFACTOR Phase
- ✅ Rationalization table with ≥15 entries
- ✅ Red flags list with ≥8 items
- ✅ "No exceptions" section addressing all loopholes
- ✅ Re-test shows NO violations under maximum stress

### DEPLOYMENT Phase
- ✅ Skill deployed to `~/.claude/skills/playwright-testing/`
- ✅ JSON results directory created and gitignored
- ✅ AGENTS.md updated
- ✅ Verification test confirms skill invoked for relevant tasks

---

## Files Created

### During Implementation
```
test-scenarios/
  playwright-testing-pressure-scenarios.md   ✅ Created
  baseline-1-results.md                      ⏳ RED Phase
  baseline-2-results.md                      ⏳ RED Phase
  baseline-3-results.md                      ⏳ RED Phase
  baseline-4-results.md                      ⏳ RED Phase
  rationalization-categories.md              ⏳ RED Phase
  with-skill-1-results.md                    ⏳ GREEN Phase
  with-skill-2-results.md                    ⏳ GREEN Phase
  with-skill-3-results.md                    ⏳ GREEN Phase
  with-skill-4-results.md                    ⏳ GREEN Phase
  comparison-analysis.md                     ⏳ GREEN Phase
  deployment-verification.md                 ⏳ DEPLOYMENT Phase
```

### Final Deliverable
```
~/.claude/skills/
  playwright-testing/
    SKILL.md                                 ⏳ GREEN Phase

e2etests/
  results/                                   ⏳ DEPLOYMENT Phase
    .gitkeep
```

---

## Anti-Patterns to Avoid

❌ **Writing skill before baseline testing**: Delete and start over
❌ **Skipping any baseline scenario**: Need all 4 for complete picture
❌ **Adding hypothetical rationalizations**: Only address ACTUAL baseline findings
❌ **Workflow summary in description**: Causes agents to skip reading full skill
❌ **Making skill >500 words**: Violates token efficiency guidelines
❌ **No rationalization table**: Agents WILL find loopholes without it
❌ **Deploying without verification test**: How do you know it works?

---

## Estimated Timeline

- **RED Phase**: 3-4 hours (baseline testing is time-consuming)
- **GREEN Phase**: 2-3 hours (write + test)
- **REFACTOR Phase**: 1-2 hours (close loopholes)
- **DEPLOYMENT Phase**: 30 minutes (deploy + verify)

**Total**: 6.5-9.5 hours

---

## Notes

- This is TDD for documentation: same RED-GREEN-REFACTOR cycle
- Baseline testing is CRITICAL: watching agents fail reveals what skill must teach
- Rationalizations are verbatim: exact words agents use under pressure
- Skill must be flexible (usable by other skills) not rigid (must follow exactly)
- Integration with php-logs skill: backend verification is delegated
- JSON preservation is MANDATORY: can't lose test results

---

## Next Steps

1. ✅ Pressure scenarios created
2. ⏳ Execute RED Phase (Milestones 1.1-1.5)
3. ⏳ Execute GREEN Phase (Milestones 2.1-2.7, 3.1-3.5)
4. ⏳ Execute REFACTOR Phase (Milestones 4.1-4.5)
5. ⏳ Execute DEPLOYMENT Phase (Milestones 5.1-5.5)
