import { access, readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(process.argv[2] || '.demo');
const mode = process.argv[3] || 'demo';
const pages = (await readdir(root)).filter(p => p.endsWith('.html'));
assert.equal(pages.length, 8, 'Expected all eight pages');
let assets = 0;
for (const page of pages) {
  const html = await readFile(path.join(root, page), 'utf8');
  assert.match(html, /<html lang="ru">/, `${page}: language missing`);
  if (mode === 'demo') {
    assert.match(html, /<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">/, `${page}: indexing protection missing`);
  } else {
    assert.match(html, /<meta name="robots" content="index, follow">/, `${page}: production indexing must be open`);
    assert.ok(!html.includes('noindex'), `${page}: unexpected noindex`);
  }
  for (const match of html.matchAll(/(?:src|href)="([^"]+)"/g)) {
    const url = match[1];
    if (/^(https?:|mailto:|tel:|#)/.test(url)) continue;
    const local = url.split(/[?#]/)[0];
    assert.ok(!local.startsWith('/'), `${page}: root-relative link breaks project Pages`);
    await access(path.join(root, local));
    assets += 1;
  }
}
assert.match(await readFile(path.join(root, 'robots.txt'), 'utf8'), mode === 'demo' ? /Disallow: \// : /Allow: \//);
console.log(`Verified ${pages.length} pages, ${assets} local links/resources, ${mode} indexing rules.`);
