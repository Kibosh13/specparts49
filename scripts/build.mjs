import { cp, mkdir, readdir, readFile, rm, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
const mode = process.argv[2];
if (!['demo', 'production'].includes(mode)) {
  console.error('Usage: node scripts/build.mjs demo|production');
  process.exit(1);
}
const source = path.join(root, 'src');
const output = path.join(root, mode === 'demo' ? '.demo' : 'dist');
await rm(output, { recursive: true, force: true });
await mkdir(output, { recursive: true });
await cp(source, output, { recursive: true });
const robots = mode === 'demo' ? 'noindex, nofollow, noarchive, nosnippet' : 'index, follow';
let pages = 0;
for (const entry of await readdir(output)) {
  if (!entry.endsWith('.html')) continue;
  const file = path.join(output, entry);
  let html = await readFile(file, 'utf8');
  html = html.replace(/<meta\b[^>]*name=["'](?:robots|googlebot|bingbot)["'][^>]*>/gi, '');
  html = html.replace('</head>', `<meta name="robots" content="${robots}"></head>`);
  await writeFile(file, html);
  pages += 1;
}
await writeFile(path.join(output, 'robots.txt'), mode === 'demo'
  ? 'User-agent: *\nDisallow: /\n'
  : 'User-agent: *\nAllow: /\n');
await writeFile(path.join(output, '.nojekyll'), '');
console.log(`${mode}: ${pages} HTML pages prepared in ${path.relative(root, output)}/; robots=${robots}`);
