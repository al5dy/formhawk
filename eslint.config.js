import eslint from '@eslint/js';
import globals from 'globals';

export default [
	{
		ignores: ['assets/**', 'node_modules/**', 'vendor/**'],
	},
	eslint.configs.recommended,
	{
		files: ['resources/**/*.js', 'tests/JS/**/*.js', 'tools/**/*.mjs'],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'module',
			globals: {...globals.browser, ...globals.node},
		},
		rules: {
			'no-empty': ['error', {allowEmptyCatch: true}],
		},
	},
];
