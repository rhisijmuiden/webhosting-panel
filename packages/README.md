# Panel packages

Each folder here is a Composer package that the panel loads through the path
repository in `panel/composer.json`. Require it in the panel with
`composer require rhis/<name>:@dev`. See `docs/architecture/interfaces.md`
for the menu, layout, agent and translation hooks a package can use.
