// Screenshots the same pages on the PHP app and the Go app, signed in, for side-by-side comparison.
//   NODE_PATH=<scratchpad>/node_modules GO=http://127.0.0.1:8101 OUT=/tmp/x node tools/shot.js /crm/leads /crm/leads/1
// env: PHP (default http://localhost:8000), GO (default http://127.0.0.1:8001), U / P (default admin / Secret123A),
//      OUT (folder, default .), W (viewport width, default 1400), CHROME (browser path)
const { chromium } = require('playwright-core');
(async () => {
  const paths = process.argv.slice(2);
  const out = process.env.OUT || '.';
  const exe = process.env.CHROME || '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell';
  const browser = await chromium.launch({ executablePath: exe });
  for (const [tag, base] of [['php', process.env.PHP || 'http://localhost:8000'], ['go', process.env.GO || 'http://127.0.0.1:8001']]) {
    const ctx = await browser.newContext({ viewport: { width: +(process.env.W || 1400), height: 900 } });
    const page = await ctx.newPage();
    await page.goto(base + '/login');
    await page.fill('#username', process.env.U || 'admin');
    await page.fill('#password', process.env.P || 'Secret123A');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    for (const p of paths) {
      await page.goto(base + p);
      await page.waitForTimeout(500);
      const name = p.replace(/[^a-z0-9]+/gi, '_');
      await page.screenshot({ path: `${out}/${name}-${tag}.png`, fullPage: true });
    }
    await ctx.close();
  }
  await browser.close();
})();
