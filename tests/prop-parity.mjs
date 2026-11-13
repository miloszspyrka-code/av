#!/usr/bin/env node
/**
 * Parytet silnika prop challenge: public/js/prop.js vs PropChallengeService.php.
 * Uruchom: npm run test:mcp:prop
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import prop from '../public/js/prop.js';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PHP = process.env.PHP_BIN || 'php';
const fixture = JSON.parse(fs.readFileSync(path.join(ROOT, 'tests', 'fixtures', 'prop-parity.json'), 'utf8'));
const result = spawnSync(PHP, [path.join(ROOT, 'tests', 'prop-parity.php')], {
  cwd: ROOT,
  encoding: 'utf8',
  env: process.env,
});
if (result.status !== 0) {
  console.error(result.stdout);
  console.error(result.stderr);
  process.exit(1);
}
const php = JSON.parse(result.stdout);
assert.equal(php.length, fixture.cases.length, 'liczba przypadków');

const numericKeys = [
  'startBalance', 'balance', 'profitAmount', 'targetAmount', 'targetProgressPct',
  'dailyLimitAmount', 'dailyLossAmount', 'dailyUsedPct', 'drawdownUsedPct',
  'maxDrawdownAmount', 'maxLossAmount', 'daysLeft', 'daysTotal', 'tradingDays',
  'n', 'wins', 'losses', 'winRate', 'pf', 'avgR', 'maxConsecutiveLosses', 'riskPct'
];
const stringKeys = ['status', 'riskLevel', 'startDate', 'endDate', 'todayKey'];

let checked = 0;
fixture.cases.forEach((testCase, index) => {
  const now = new Date(fixture.now);
  const js = prop.compute(testCase.challenge, testCase.trades, now, fixture.accountNames);
  const phpCase = php[index].metrics;
  for (const key of numericKeys) {
    const a = Number(js[key]);
    const b = Number(phpCase[key]);
    assert.ok(Number.isFinite(a), `${testCase.name}: JS ${key} nie jest liczbą`);
    assert.ok(Number.isFinite(b), `${testCase.name}: PHP ${key} nie jest liczbą`);
    assert.ok(Math.abs(a - b) <= 0.02, `${testCase.name}: ${key} JS=${a} PHP=${b}`);
    checked++;
  }
  for (const key of stringKeys) {
    assert.equal(String(js[key]), String(phpCase[key]), `${testCase.name}: ${key}`);
    checked++;
  }
  assert.equal(phpCase.n, js.n, `${testCase.name}: liczba transakcji`);
});

console.log(`PROP PARITY PASS  ·  ${fixture.cases.length} przypadki, ${checked} porównań`);
