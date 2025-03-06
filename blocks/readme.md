# Smaily Blocks

## Caveats

1. Supporting WP < 6.5

[Use WP scripts version 27 as the later versions require WP 6.5+.](https://github.com/WordPress/gutenberg/blob/HEAD/packages/scripts/CHANGELOG.md#breaking-changes-2)

2. Supporting WP < 6.1

The `render` property in the `block.json` file was introduced [in WP 6.1](https://make.wordpress.org/core/2022/10/12/block-api-changes-in-wordpress-6-1/). Use `render_callback` instead.
