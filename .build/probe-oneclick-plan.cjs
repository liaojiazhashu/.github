#!/usr/bin/env node
/* 验收：一键拆解页的「已忽略」行是否渲染、数值是否为 1 */
'use strict';

const path = require('path');
const { openAdmin } = require('../tools/lib/browser.cjs');

(async () => {
  const page = await openAdmin({
    user: 'ui_audit',
    pass: 'UIAudit#2026tmp',
    width: 1440,
    height: 900,
    port: 9361
  });

  await page.goto('/admin/oneclick', '.planline');
  const txt = await page.evaluate(`document.querySelector('.planline').innerText.replace(/\\n/g, ' | ')`);
  console.log('planline:', txt);

  const spans = await page.evaluate(`Array.from(document.querySelectorAll('.planline span')).map(function(s){return s.innerText.trim();})`);
  console.log('spans:', JSON.stringify(spans, null, 1));

  await page.screenshot(path.join(__dirname, 'shots', 'oneclick-planline.png'), { selector: '.planline' });
  await page.close();
  console.log('截图：.build/shots/oneclick-planline.png');
})();
