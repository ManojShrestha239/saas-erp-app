// .agents/skills/browser-automation/scripts/browse.js
const { chromium } = require("playwright");

(async () => {
  const [, , action, target, extra] = process.argv;
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  try {
    if (action === "get") {
      await page.goto(target, { waitUntil: "domcontentloaded" });
      // Return readable text content
      const content = await page.evaluate(() => document.body.innerText);
      console.log(content.slice(0, 4000));
    } else if (action === "screenshot") {
      await page.goto(target, { waitUntil: "networkidle" });
      const outputPath = extra || "screenshot.png";
      await page.screenshot({ path: outputPath, fullPage: true });
      console.log(`Saved screenshot to ${outputPath}`);
    }
  } catch (err) {
    console.error(`Browser error: ${err.message}`);
    process.exit(1);
  } finally {
    await browser.close();
  }
})();
