/**
 * ESLint flat config for @wordpress/scripts v32+ (ESLint v10).
 *
 * Mirrors the default config that `wp-scripts lint-js` would apply
 * (`@wordpress/eslint-plugin/recommended` + the test-unit override),
 * and adds project-specific globals and per-file rule tweaks.
 */

const wpPlugin = require( '@wordpress/eslint-plugin' );

module.exports = [
	{
		ignores: [
			'**/build/**',
			'**/node_modules/**',
			'**/vendor/**',
			'**/playwright-report/**',
			'**/test-results/**',
		],
	},

	...wpPlugin.configs.recommended,

	...wpPlugin.configs[ 'test-unit' ].map( ( c ) => ( {
		...c,
		files: [ '**/@(test|__tests__)/**/*.js', '**/?(*.)test.js' ],
	} ) ),

	{
		languageOptions: {
			globals: {
				mw: 'readonly',
				$: 'readonly',
				location: 'readonly',
			},
		},
	},

	{
		files: [ '.docker/**/*.js' ],
		rules: {
			'no-console': 'off',
		},
	},

	{
		files: [ 'tests/playwright/**/*.js' ],
		rules: {
			'react-hooks/rules-of-hooks': 'off',
		},
	},
];
