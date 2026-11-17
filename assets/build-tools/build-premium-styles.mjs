/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Builds the premium overlay's frontend stylesheet, when the overlay is present.
 *
 * Run from assets/frontend/app after the free build (see its package.json). The premium
 * stylesheet uses this app's Tailwind and Vite, so it is built here rather than in an app
 * of its own. A tree without pro/assets/frontend/src/ (the free plugin) skips it.
 */
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const PLUGIN_ROOT = resolve(import.meta.dirname, '..', '..');
const source = resolve(PLUGIN_ROOT, 'pro/assets/frontend/src/testimonials-pro.css');

if (!existsSync(source)) {
  console.log('build-premium-styles: no premium stylesheet source, nothing to build');
  process.exit(0);
}

const app = resolve(PLUGIN_ROOT, 'assets/frontend/app');
const vite = resolve(app, 'node_modules/vite/bin/vite.js');
const run = spawnSync(process.execPath, [vite, 'build'], {
  cwd: app,
  stdio: 'inherit',
  env: { ...process.env, PLUGIXA_FRONTEND: 'premium' },
});
process.exit(run.status ?? 1);
