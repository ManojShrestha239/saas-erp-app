import { execSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const steps = [
    { name: 'ESLint', cmd: 'npx --no-install eslint .', bin: 'node_modules/.bin/eslint' },
    { name: 'TypeScript (tsc --noEmit)', cmd: 'npx --no-install tsc --noEmit', bin: 'node_modules/.bin/tsc' },
    { name: 'Vitest', cmd: 'npx --no-install vitest run', bin: 'node_modules/.bin/vitest' },
];

let failed = false;

for (const step of steps) {
    if (existsSync(step.bin)) {
        console.log(`\n--- Running ${step.name} ---`);
        try {
            execSync(step.cmd, { stdio: 'inherit' });
            console.log(`✓ ${step.name} passed.`);
        } catch {
            console.error(`✗ ${step.name} failed.`);
            failed = true;
        }
    } else {
        console.log(`Skipping ${step.name} (tool not installed yet; deferred to frontend scaffold).`);
    }
}

if (failed) {
    process.exit(1);
}
