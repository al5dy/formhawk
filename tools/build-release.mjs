import {cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, statSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, relative, sep} from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const packageData = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));
const distDirectory = join(root, 'dist');
const archive = join(distDirectory, `formhawk-${packageData.version}.zip`);
const stagingRoot = mkdtempSync(join(tmpdir(), 'formhawk-release-'));
const stagingPlugin = join(stagingRoot, 'formhawk');

const excludedPaths = new Set([
	'.distignore',
	'.editorconfig',
	'.git',
	'.github',
	'.idea',
	'.phpstan-cache',
	'.phpunit.result.cache',
	'.playwright-cli',
	'AGENTS.md',
	'composer.lock',
	'dist',
	'eslint.config.js',
	'node_modules',
	'output',
	'coverage',
	'test-results',
	'playwright-report',
	'package-lock.json',
	'phpcs.xml.dist',
	'phpstan.neon.dist',
	'phpunit.xml.dist',
	'tests',
	'tools/phpstan-bootstrap.php',
	'tools/benchmark-migration.php',
	'tools/benchmark-field-roi.php',
	'tools/benchmark-cro-integrity.php',
	'vendor',
	'vitest.config.js',
]);

const shouldCopy = source => {
	const path = relative(root, source).split(sep).join('/');

	if (!path) {
		return true;
	}

	// Local hidden configuration and diagnostics must never enter a distributable archive.
	if (path.split('/').some(part => part.startsWith('.'))
		|| /\.(?:sql(?:\.gz)?|pem|key|log)$/i.test(path)
		|| /(?:^|\/)auth\.json$/.test(path)) {
		return false;
	}

	for (const excludedPath of excludedPaths) {
		if (path === excludedPath || path.startsWith(`${excludedPath}/`)) {
			return false;
		}
	}

	return true;
};

try {
	mkdirSync(distDirectory, {recursive: true});
	rmSync(archive, {force: true});
	cpSync(root, stagingPlugin, {recursive: true, filter: shouldCopy});

	const zip = spawnSync('zip', ['-X', '-q', '-r', archive, 'formhawk'], {
		cwd: stagingRoot,
		stdio: 'inherit',
	});

	if (zip.status !== 0) {
		throw new Error('The zip command failed while creating the release artifact.');
	}

	console.log(`Created ${archive} (${statSync(archive).size} bytes)`);
} finally {
	rmSync(stagingRoot, {recursive: true, force: true});
}
