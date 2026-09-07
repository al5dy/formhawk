import {build} from 'esbuild';

await build({
	entryPoints: ['resources/js/tracker/index.js'],
	bundle: true,
	minify: true,
	target: ['es2018'],
	format: 'iife',
	outfile: 'assets/js/tracker.js',
	legalComments: 'none',
});

await build({
	entryPoints: ['resources/js/cro/autopilot.js'],
	bundle: true,
	minify: true,
	target: ['es2018'],
	format: 'iife',
	outfile: 'assets/js/cro-autopilot.js',
	legalComments: 'none',
});

await build({
	entryPoints: ['resources/scss/admin.scss'],
	bundle: true,
	minify: true,
	loader: {'.scss': 'css'},
	outfile: 'assets/css/admin.css',
	legalComments: 'none',
});

await build({
	entryPoints: ['resources/scss/cro.scss'],
	bundle: true,
	minify: true,
	loader: {'.scss': 'css'},
	outfile: 'assets/css/cro.css',
	legalComments: 'none',
});

await build({
	entryPoints: ['resources/scss/admin-cro.scss'],
	bundle: true,
	minify: true,
	loader: {'.scss': 'css'},
	outfile: 'assets/css/admin-cro.css',
	legalComments: 'none',
});
