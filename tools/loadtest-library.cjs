#!/usr/bin/env node
/**
 * 前台压测（M6）：只回答三个问题 —— 缓存有没有生效、单页延迟多少、拐点在哪。
 *
 * 刻意不引依赖（autocannon / k6 都要装东西），也不追求「模拟真实用户」：
 * 这个站是读多写零的静态化前台，真实的性能问题只有两种 ——
 *   1. 缓存没命中，每个请求都打库；
 *   2. 某个查询没有索引，平时看不出、并发一上来就拖垮整页。
 * 所以每次压测都同时记录 X-Cache 命中率：只报 QPS 不看命中率，等于什么都没测。
 *
 * 用法：
 *   node tools/loadtest-library.cjs
 *   BASE=http://127.0.0.1:8090 CONCURRENCY=20 REQUESTS=200 node tools/loadtest-library.cjs
 *   PATHS=/library/works,/library/search?q=克制 node tools/loadtest-library.cjs
 */

'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const CONCURRENCY = Number(process.env.CONCURRENCY || 16);
const REQUESTS = Number(process.env.REQUESTS || 160);
const OUT = path.join(ROOT, '.build', 'loadtest');

const DEFAULT_PATHS = [
  '/library',
  '/library/works',
  '/library/works/01M61HYFQ9KQD9B8B509AC5QKK',
  '/library/terms',
  '/library/terms/2',
  '/library/search?q=%E5%85%8B%E5%88%B6'
];

const paths = (process.env.PATHS ? process.env.PATHS.split(',') : DEFAULT_PATHS).map((p) => p.trim());

const agent = new http.Agent({ keepAlive: true, maxSockets: CONCURRENCY });

function once(urlPath) {
  return new Promise((resolve) => {
    const started = process.hrtime.bigint();
    const req = http.get(BASE + urlPath, { agent }, (res) => {
      let bytes = 0;
      res.on('data', (chunk) => { bytes += chunk.length; });
      res.on('end', () => {
        const ms = Number(process.hrtime.bigint() - started) / 1e6;
        resolve({
          ms,
          status: res.statusCode,
          bytes,
          cache: (res.headers['x-cache'] || '-').toUpperCase()
        });
      });
    });
    req.on('error', (err) => {
      const ms = Number(process.hrtime.bigint() - started) / 1e6;
      resolve({ ms, status: 0, bytes: 0, cache: 'ERR', error: err.message });
    });
    req.setTimeout(15000, () => req.destroy(new Error('timeout')));
  });
}

function pct(sorted, p) {
  if (sorted.length === 0) return 0;
  const idx = Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1));
  return sorted[idx];
}

async function bench(urlPath) {
  // 公开读限流是 60 次/分/IP，而缓存中间件在限流之外 —— 缓存页压到 160 次也不会 429，
  // 不缓存的检索页则会。所以非缓存路径把请求数压到 50，测的是「限流线以下的真实延迟」，
  // 而不是拿 429 当错误率（那会把「限流正常工作」误报成「服务挂了」）。
  const isCached = !urlPath.includes('/search');
  const requests = isCached ? REQUESTS : Math.min(REQUESTS, 50);

  // 先打一次「预热」：首访必然是 MISS，把它算进统计会把 p95 拉成缓存的锅
  await once(urlPath);

  const results = [];
  const startedAt = Date.now();

  let issued = 0;
  const workers = Array.from({ length: CONCURRENCY }, async () => {
    while (issued < requests) {
      issued++;
      results.push(await once(urlPath));
    }
  });
  await Promise.all(workers);

  const seconds = (Date.now() - startedAt) / 1000;
  const lat = results.map((r) => r.ms).sort((a, b) => a - b);
  const ok = results.filter((r) => r.status === 200).length;
  const hit = results.filter((r) => r.cache === 'HIT').length;
  const bytes = results.reduce((s, r) => s + r.bytes, 0);

  return {
    path: urlPath,
    n: results.length,
    okPct: +((ok / results.length) * 100).toFixed(1),
    cacheHitPct: +((hit / results.length) * 100).toFixed(1),
    rps: +(results.length / seconds).toFixed(1),
    p50: +pct(lat, 50).toFixed(1),
    p95: +pct(lat, 95).toFixed(1),
    p99: +pct(lat, 99).toFixed(1),
    max: +Math.max(...lat).toFixed(1),
    kb: Math.round(bytes / results.length / 1024)
  };
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  console.log(`压测 ${BASE}  并发 ${CONCURRENCY}  每路径 ${REQUESTS} 次\n`);

  const rows = [];
  for (const p of paths) {
    const r = await bench(p);
    rows.push(r);
    console.log(
      `${String(r.rps).padStart(6)} req/s  p50 ${String(r.p50).padStart(6)}ms  p95 ${String(r.p95).padStart(6)}ms  ` +
      `p99 ${String(r.p99).padStart(6)}ms  命中 ${String(r.cacheHitPct).padStart(5)}%  ok ${r.okPct}%  ${r.kb}KB  ${r.path}`
    );
  }

  const report = {
    base: BASE,
    concurrency: CONCURRENCY,
    requests_per_path: REQUESTS,
    generated_at: new Date().toISOString(),
    rows
  };
  const file = path.join(OUT, `loadtest-${Date.now()}.json`);
  fs.writeFileSync(file, JSON.stringify(report, null, 2), 'utf8');
  fs.writeFileSync(path.join(OUT, 'latest.json'), JSON.stringify(report, null, 2), 'utf8');
  console.log(`\n报告：${path.relative(ROOT, file)}`);

  agent.destroy();

  // 判定：有 5xx、或缓存页命中率低于 90%，都算失败 —— 这套前台没理由做不到
  const bad = rows.filter((r) => r.okPct < 100 || (r.cacheHitPct < 90 && !r.path.includes('/search')));
  process.exit(bad.length ? 1 : 0);
})();
