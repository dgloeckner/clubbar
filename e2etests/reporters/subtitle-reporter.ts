import type {
  FullConfig,
  FullResult,
  Reporter,
  Suite,
  TestCase,
  TestResult,
  TestStep,
} from '@playwright/test/reporter';
import * as fs from 'fs';
import * as path from 'path';

interface SubtitleEntry {
  text: string;
  startSec: number;
  durationSec: number;
}

interface ClipEntry {
  testTitle: string;
  video: string;
  subtitles: SubtitleEntry[];
}

interface SubtitleOutput {
  clips: ClipEntry[];
}

const DEFAULT_DURATION_SEC = 3;

/**
 * Custom Playwright reporter that extracts subtitle timing from test.step('subtitle: ...')
 * blocks in walkthrough tests. Produces a JSON file mapping video clips to subtitle entries.
 */
class SubtitleReporter implements Reporter {
  private clips: ClipEntry[] = [];
  private outputPath: string;

  constructor(options?: { outputFile?: string }) {
    this.outputPath = options?.outputFile
      ?? path.join(process.cwd(), 'walkthrough-subtitles.json');
  }

  onTestEnd(test: TestCase, result: TestResult): void {
    // Only process walkthrough project tests
    if (test.parent?.project()?.name !== 'walkthrough') return;

    // Find the video attachment
    const videoAttachment = result.attachments.find(a => a.name === 'video' && a.path);
    if (!videoAttachment?.path) return;

    const subtitles: SubtitleEntry[] = [];
    const testStartTime = result.startTime.getTime();

    // Recursively collect subtitle steps
    this.collectSubtitleSteps(result.steps, testStartTime, subtitles);

    this.clips.push({
      testTitle: test.title,
      video: videoAttachment.path,
      subtitles,
    });
  }

  private collectSubtitleSteps(
    steps: TestStep[],
    testStartTime: number,
    subtitles: SubtitleEntry[],
  ): void {
    for (const step of steps) {
      if (step.title.startsWith('subtitle:')) {
        const text = step.title.replace('subtitle:', '').trim();
        const startSec = Math.max(0, (step.startTime.getTime() - testStartTime) / 1000);
        subtitles.push({
          text,
          startSec: Math.round(startSec * 10) / 10,
          durationSec: DEFAULT_DURATION_SEC,
        });
      }
      // Recurse into nested steps
      if (step.steps?.length) {
        this.collectSubtitleSteps(step.steps, testStartTime, subtitles);
      }
    }
  }

  async onEnd(result: FullResult): Promise<void> {
    if (this.clips.length === 0) return;

    // Sort clips by test title (they are numbered 01, 02, ...)
    this.clips.sort((a, b) => a.testTitle.localeCompare(b.testTitle));

    const output: SubtitleOutput = { clips: this.clips };
    fs.writeFileSync(this.outputPath, JSON.stringify(output, null, 2) + '\n');
    console.log(`\n[SubtitleReporter] Wrote ${this.clips.length} clips to ${this.outputPath}`);
  }
}

export default SubtitleReporter;
